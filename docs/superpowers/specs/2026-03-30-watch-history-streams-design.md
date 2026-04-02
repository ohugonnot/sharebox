# Spec : Vu/non vu + Streams actifs

## Périmètre

Deux features indépendantes ajoutées en une passe :
1. **Vu/non vu** — suivi de progression par utilisateur, marquage auto à 85%
2. **Streams actifs** — monitoring temps réel dans l'admin + historique

---

## Feature 1 : Vu/non vu

### Base de données (migration v16)

Nouvelle table `watch_history` :

```sql
CREATE TABLE watch_history (
    user        TEXT NOT NULL,
    path        TEXT NOT NULL,
    watched_at  TEXT NOT NULL DEFAULT (datetime('now')),
    duration_sec INTEGER,
    PRIMARY KEY (user, path)
);
CREATE INDEX idx_watch_user ON watch_history(user);
```

`ON CONFLICT REPLACE` sur `(user, path)` — re-regarder un film met à jour `watched_at`.

### Déclenchement (player.js)

- PHP injecte le chemin absolu du fichier dans une variable JS `WATCH_PATH` dans `afficher_player()`
- Dans l'event `timeupdate` : quand `realTime() / S.duration >= 0.85` ET `S.duration > 60`, POST unique vers `ctrl.php?cmd=mark_watched` avec `{ path: WATCH_PATH, duration: Math.round(S.duration) }`
- Flag JS `watchMarked = false` réinitialisé à chaque `startStream()` — un seul appel par session de lecture

### API (ctrl.php)

Nouvelle action `mark_watched` (authentifiée, non admin-only) :
- Reçoit `{ path, duration, csrf_token }`
- Vérifie que le path appartient à un lien actif de l'utilisateur courant (ou que l'user est admin)
- INSERT OR REPLACE dans `watch_history`

### Affichage dans le listing (handlers/tmdb.php + download.php)

**Backend** : l'endpoint `?posters` ajoute un champ `watched: true` aux items dont le chemin absolu est dans `watch_history` pour l'user courant. Batch query sur les chemins retournés.

**Frontend** :
- Badge `✓` en bas à droite des cartes (CSS `.grid-card-watched`)
- Position : `bottom: .42rem; right: .42rem` — ne chevauche pas le dot de confiance (déplacé légèrement)
- Couleur : vert `#3ddc84`, fond semi-transparent
- Le badge est ajouté/supprimé dynamiquement par `fetchPosters` comme les autres badges

### Ce qui n'est PAS inclus

- Pas de filtre "non vu seulement" (peut venir après)
- Pas de marquage manuel depuis l'interface listing
- Pas de dé-marquage (peut venir après)

---

## Feature 2 : Streams actifs + historique

### Fichiers d'état des streams

Dans `download.php`, avant de dispatcher vers le handler stream, écriture de `/tmp/sharebox_stream_{md5(session_id())}.json` :

```json
{
  "user": "folken",
  "filename": "Film.mkv",
  "path": "/data/films/Film.mkv",
  "token": "abc123",
  "mode": "hls",
  "hls_pid_file": "/tmp/hls_xxx/ffmpeg.pid",
  "start_time": "2026-03-30T14:00:00",
  "last_seen": 1743340800
}
```

- `last_seen` = timestamp Unix, mis à jour à chaque requête stream (HLS = toutes les ~5s naturellement)
- `hls_pid_file` : chemin vers le fichier PID ffmpeg (HLS uniquement, null sinon)
- Écriture uniquement pour les modes `hls`, `transcode`, `native` (pas les téléchargements ni les probes)

### API admin (ctrl.php, action `active_streams`)

- Scanne `/tmp/sharebox_stream_*.json`
- Filtre `last_seen >= now - 120s` (stream considéré actif si vu dans les 2 dernières minutes)
- Pour chaque stream HLS avec `hls_pid_file` valide : lit le PID, calcule CPU% depuis `/proc/{pid}/stat` (delta sur 500ms)
- Retourne JSON : liste de streams avec `{ user, filename, mode, duration_sec, cpu_pct }`

### Historique (activity_logs)

- À la première écriture du fichier d'état (quand `start_time` n'existe pas encore), loggue `stream_start` dans `activity_logs` avec `details = "mode=hls | file=Film.mkv | user=folken"`
- Pas de `stream_end` (complexe à détecter proprement, pas critique)

### Affichage admin (admin.php, onglet Système)

Nouvelle card **"Streams actifs"** au-dessus de la section TMDB :

- Rafraîchissement automatique toutes les 10s (setInterval)
- Colonnes : Utilisateur | Fichier | Mode | Durée | CPU
- Si aucun stream : message "Aucun stream actif"
- CPU affiché uniquement pour HLS (les autres modes n'ont pas de PID accessible)
- Historique : lien vers l'onglet Activité filtré sur `stream_start`

### Ce qui n'est PAS inclus

- Pas de kill de stream depuis l'admin (peut venir après)
- CPU pour transcode non-HLS (le process PHP lui-même, trop complexe à isoler)

---

## Ordre d'implémentation suggéré

1. Migration BDD v16 (`watch_history`)
2. `ctrl.php` : action `mark_watched`
3. `player.js` : déclenchement à 85%
4. `handlers/tmdb.php` : flag `watched` dans `?posters`
5. `download.php` : badge `✓` dans `fetchPosters` + CSS
6. `download.php` : écriture fichier d'état stream
7. `ctrl.php` : action `active_streams`
8. `admin.php` : card Streams actifs + polling JS
