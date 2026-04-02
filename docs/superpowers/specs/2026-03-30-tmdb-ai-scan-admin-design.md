# Design — Lancement du skill IA TMDB depuis l'admin

**Date :** 2026-03-30
**Statut :** Approuvé

## Contexte

Le skill `tmdb-scan` (Claude Code) effectue la vérification et correction des posters TMDB : faux positifs, confidence scoring, cas ambigus. Jusqu'ici il ne pouvait être lancé que manuellement via SSH. L'objectif est de le rendre accessible depuis l'admin ShareBox avec un suivi des logs en temps réel.

Le worker PHP existant (`tools/tmdb-worker.php`) reste inchangé — il gère le matching initial rapide. Le skill IA est une passe qualité complémentaire, déclenchée séparément.

## Architecture

### Wrapper système

**Fichier :** `/usr/local/bin/sharebox-tmdb-ai-scan`

Script bash exécutable par root, invocable via sudo par www-data :
- Set `HOME=/root` pour que claude trouve sa config
- Lance `claude --dangerously-skip-permissions -p "/tmdb-scan"`
- Sortie capturée par l'appelant (redirection dans le log)

**Sudoers :** `/etc/sudoers.d/sharebox-ai-scan`
```
www-data ALL=(root) NOPASSWD: /usr/local/bin/sharebox-tmdb-ai-scan
```

### Fichiers runtime

| Fichier | Usage |
|---|---|
| `data/ai-scan.log` | Sortie du scan, tronquée à chaque nouveau lancement |
| `data/sharebox_ai_scan.lock` | Contient le PID du process en cours |

### Actions PHP (dans `admin.php`)

Toutes admin-only, ajoutées à `$adminOnlyActions`.

**`ai_scan_launch` (POST)**
1. Vérifie qu'aucun scan ne tourne (`lock file` + `posix_kill($pid, 0)`)
2. Tronque `data/ai-scan.log`
3. Spawn : `sudo /usr/local/bin/sharebox-tmdb-ai-scan >> data/ai-scan.log 2>&1 & echo $!`
4. Écrit le PID dans `data/sharebox_ai_scan.lock`
5. Retourne `{ok: true, pid: N}`

**`ai_scan_status` (GET)**
- Lit le lock file, vérifie si PID vivant via `posix_kill($pid, 0)`
- Retourne `{running: bool, pid: N, log_size: N}`

**`ai_scan_log` (GET, param `offset`)**
- Lit `data/ai-scan.log` depuis l'offset en bytes (`fseek` + `fread`)
- Retourne `{lines: [...], next_offset: N, done: bool}`
- `done = true` quand le scan n'est plus en cours ET offset = fin du fichier

## UI

### Localisation

Dans la card "TMDB Posters" de l'onglet Système (`#tab-systeme`), header de la card :

```
[Scan TMDB (N)]   [Vérification IA]
```

### Comportement du bouton

- **Idle :** "Vérification IA", activé
- **En cours :** "IA en cours...", disabled
- **Fin :** retour à "Vérification IA", activé — les stats TMDB se rechargent

### Panneau log

- S'ouvre sous les boutons au lancement
- Fond sombre (`#0c0e14`), texte monospace `.75rem`, hauteur fixe `200px`, overflow-y scroll, auto-scroll sur nouvelles lignes
- Polling toutes les 2s via `ai_scan_log?offset=N`
- Les codes ANSI sont strippés côté PHP avant envoi (regex `\e[...m`)
- Bouton "×" en haut à droite pour replier (reste visible après la fin)
- Dernière ligne mise en évidence si elle contient "Pending:" ou "Coverage:" (résumé du skill)

### JS

Nouvelles fonctions dans le bloc TMDB existant :
- `launchAiScan()` — POST `ai_scan_launch`, ouvre le panneau, démarre le polling
- `pollAiLog(offset)` — GET `ai_scan_log`, append les lignes, reschedule si `!done`
- `stopAiLog()` — arrête le polling, recharge les stats TMDB

## Séquence complète

1. Admin clique "Vérification IA"
2. POST `ai_scan_launch` → PID retourné, log tronqué
3. Panneau terminal s'ouvre
4. `pollAiLog(0)` toutes les 2s — lignes ajoutées au terminal
5. Quand `done: true` — polling s'arrête, stats rechargées, bouton réactivé

## Hors scope

- Arrêt manuel du scan (pas de bouton Stop)
- Enchaînement automatique worker PHP → skill IA
- Historique des scans précédents
