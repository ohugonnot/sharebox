# TMDB AI Scan — Admin Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter un bouton "Vérification IA" dans l'admin ShareBox qui lance le skill Claude `tmdb-scan` en background et affiche les logs en temps réel dans un panneau terminal.

**Architecture:** PHP spawn le wrapper bash via sudo, qui lance `claude --dangerously-skip-permissions -p "/tmdb-scan"` en background avec sortie redirigée vers `data/ai-scan.log`. Trois nouvelles actions PHP (`ai_scan_launch`, `ai_scan_status`, `ai_scan_log`) sont ajoutées à `admin.php`. Le JS poll `ai_scan_log?offset=N` toutes les 2s et append les lignes dans un panneau terminal HTML inline.

**Tech Stack:** PHP 8.3, bash, Claude CLI (`/root/.local/bin/claude`), SQLite WAL, vanilla JS, sudoers

---

## File Map

| Action | Fichier |
|---|---|
| Créer | `/usr/local/bin/sharebox-tmdb-ai-scan` |
| Créer | `/etc/sudoers.d/sharebox-ai-scan` |
| Modifier | `admin.php` — 3 nouvelles actions PHP + UI + JS |

---

## Task 1 : Wrapper bash + sudoers

**Files:**
- Create: `/usr/local/bin/sharebox-tmdb-ai-scan`
- Create: `/etc/sudoers.d/sharebox-ai-scan`

- [ ] **Step 1 : Créer le wrapper**

```bash
cat > /usr/local/bin/sharebox-tmdb-ai-scan << 'EOF'
#!/bin/bash
export HOME=/root
export PATH="/root/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
exec /root/.local/bin/claude --dangerously-skip-permissions -p "/tmdb-scan"
EOF
chmod 755 /usr/local/bin/sharebox-tmdb-ai-scan
```

- [ ] **Step 2 : Tester le wrapper directement en root**

```bash
/usr/local/bin/sharebox-tmdb-ai-scan --version 2>&1 | head -3
```
Expected : affiche la version de claude (pas d'erreur "command not found")

- [ ] **Step 3 : Créer l'entrée sudoers**

```bash
echo 'www-data ALL=(root) NOPASSWD: /usr/local/bin/sharebox-tmdb-ai-scan' \
  > /etc/sudoers.d/sharebox-ai-scan
chmod 440 /etc/sudoers.d/sharebox-ai-scan
visudo -c  # vérifie la syntaxe
```
Expected : `visudo -c` retourne `parsed OK`

- [ ] **Step 4 : Vérifier que www-data peut invoquer le wrapper**

```bash
sudo -u www-data sudo /usr/local/bin/sharebox-tmdb-ai-scan --version 2>&1 | head -3
```
Expected : même sortie qu'en root, sans demande de mot de passe

---

## Task 2 : Actions PHP — `ai_scan_launch`, `ai_scan_status`, `ai_scan_log`

**Files:**
- Modify: `admin.php` (dans le `switch ($action)`, après le `case 'tmdb_scan':`)

- [ ] **Step 1 : Ajouter les 3 actions à `$adminOnlyActions`**

Dans `admin.php`, ligne ~33, modifier le tableau :

```php
$adminOnlyActions = ['list_users','create_user','update_user','delete_user',
                     'restart_rtorrent','stop_rtorrent','tmdb_status','tmdb_scan',
                     'purge_expired','recent_activity','activity_events',
                     'ai_scan_launch','ai_scan_status','ai_scan_log'];
```

- [ ] **Step 2 : Ajouter les 3 cases dans le switch**

Après le `case 'tmdb_scan': ... break;` (ligne ~286), insérer :

```php
            case 'ai_scan_launch':
                $lockFile = __DIR__ . '/data/sharebox_ai_scan.lock';
                $logFile  = __DIR__ . '/data/ai-scan.log';
                // Vérifier qu'aucun scan ne tourne déjà
                if (file_exists($lockFile)) {
                    $pid = (int)file_get_contents($lockFile);
                    if ($pid > 0 && posix_kill($pid, 0)) {
                        echo json_encode(['ok' => false, 'message' => 'Scan IA déjà en cours', 'pid' => $pid]);
                        break;
                    }
                }
                // Tronquer le log précédent
                file_put_contents($logFile, '');
                // Lancer le wrapper via sudo en background
                $cmd = 'sudo /usr/local/bin/sharebox-tmdb-ai-scan >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
                $pid = (int)trim(shell_exec($cmd));
                if ($pid <= 0) {
                    echo json_encode(['ok' => false, 'message' => 'Échec du lancement']);
                    break;
                }
                file_put_contents($lockFile, (string)$pid);
                echo json_encode(['ok' => true, 'pid' => $pid]);
                break;

            case 'ai_scan_status':
                $lockFile = __DIR__ . '/data/sharebox_ai_scan.lock';
                $logFile  = __DIR__ . '/data/ai-scan.log';
                $running  = false;
                $pid      = 0;
                if (file_exists($lockFile)) {
                    $pid = (int)file_get_contents($lockFile);
                    if ($pid > 0 && posix_kill($pid, 0)) {
                        $running = true;
                    }
                }
                $logSize = file_exists($logFile) ? filesize($logFile) : 0;
                echo json_encode(['running' => $running, 'pid' => $pid, 'log_size' => $logSize]);
                break;

            case 'ai_scan_log':
                $logFile = __DIR__ . '/data/ai-scan.log';
                $offset  = max(0, (int)($_GET['offset'] ?? 0));
                $lines   = [];
                $nextOffset = $offset;
                if (file_exists($logFile)) {
                    $fh = fopen($logFile, 'r');
                    if ($fh) {
                        fseek($fh, $offset);
                        $chunk = fread($fh, 65536); // 64 KB max par poll
                        fclose($fh);
                        if ($chunk !== false && $chunk !== '') {
                            // Strip codes ANSI
                            $chunk = preg_replace('/\x1b\[[0-9;]*[mGKHF]/u', '', $chunk);
                            $lines = explode("\n", $chunk);
                            // Retirer la dernière entrée vide si chunk se termine par \n
                            if (end($lines) === '') array_pop($lines);
                            $nextOffset = $offset + strlen($chunk);
                        }
                    }
                }
                // done = scan terminé ET on est à la fin du fichier
                $lockFile = __DIR__ . '/data/sharebox_ai_scan.lock';
                $running  = false;
                if (file_exists($lockFile)) {
                    $pid = (int)file_get_contents($lockFile);
                    if ($pid > 0 && posix_kill($pid, 0)) {
                        $running = true;
                    }
                }
                $fileSize   = file_exists($logFile) ? filesize($logFile) : 0;
                $done       = !$running && $nextOffset >= $fileSize;
                echo json_encode(['lines' => $lines, 'next_offset' => $nextOffset, 'done' => $done]);
                break;
```

- [ ] **Step 3 : Recharger PHP-FPM**

```bash
systemctl reload php8.3-fpm
```

- [ ] **Step 4 : Tester `ai_scan_status` manuellement**

```bash
curl -s 'http://localhost/share/admin.php?action=ai_scan_status' \
  -H 'Cookie: PHPSESSID=...'  # remplacer par une session admin valide
```
Expected : `{"running":false,"pid":0,"log_size":0}` ou similaire sans erreur PHP

---

## Task 3 : UI — bouton + panneau terminal

**Files:**
- Modify: `admin.php` (section HTML `#tab-systeme`)

- [ ] **Step 1 : Modifier le header de la card TMDB**

Remplacer (ligne ~812-814) :

```html
            <div class="card-header">
                <div class="card-title">TMDB Posters</div>
                <button class="btn btn-accent" id="tmdb-scan-btn" onclick="launchTmdbScan()">Scan TMDB</button>
            </div>
```

Par :

```html
            <div class="card-header">
                <div class="card-title">TMDB Posters</div>
                <div style="display:flex;gap:.5rem">
                    <button class="btn btn-ghost" id="tmdb-scan-btn" onclick="launchTmdbScan()">Scan TMDB</button>
                    <button class="btn btn-accent" id="ai-scan-btn" onclick="launchAiScan()">Vérification IA</button>
                </div>
            </div>
```

- [ ] **Step 2 : Ajouter le panneau terminal dans la card**

Après la div `id="tmdb-bar-wrap"` (ligne ~825), avant la fermeture `</div>` de `padding:1rem 1.4rem`, ajouter :

```html
                <div id="ai-scan-panel" style="display:none;margin-top:1rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem">
                        <span style="font-size:.72rem;color:var(--text-muted)">Log IA</span>
                        <button onclick="closeAiPanel()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.8rem;padding:0">×</button>
                    </div>
                    <div id="ai-scan-log" style="background:#0c0e14;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .8rem;height:200px;overflow-y:auto;font-family:monospace;font-size:.72rem;color:#cdd6f4;white-space:pre-wrap;word-break:break-all"></div>
                </div>
```

- [ ] **Step 3 : Vérifier visuellement**

Ouvrir `/share/admin.php#systeme` dans le navigateur. Les deux boutons doivent être côte à côte. Le panneau est masqué initialement.

---

## Task 4 : JS — `launchAiScan`, `pollAiLog`, `closeAiPanel`

**Files:**
- Modify: `admin.php` (bloc JS `// ── TMDB status & scan ──`, après `launchTmdbScan()`)

- [ ] **Step 1 : Ajouter les fonctions JS**

Après la fonction `launchTmdbScan()` (ligne ~1221), insérer :

```javascript
// ── AI scan ──
var aiScanPollTimer = null;

async function launchAiScan() {
    const btn = document.getElementById('ai-scan-btn');
    btn.textContent = 'IA en cours...';
    btn.disabled = true;

    const panel = document.getElementById('ai-scan-panel');
    const log   = document.getElementById('ai-scan-log');
    log.textContent = '';
    panel.style.display = '';

    const res = await api('ai_scan_launch', {});
    if (res.error || !res.ok) {
        appendAiLog('Erreur : ' + (res.message || res.error || 'Échec du lancement'));
        btn.textContent = 'Vérification IA';
        btn.disabled = false;
        return;
    }
    appendAiLog('Scan IA démarré (PID ' + res.pid + ')…\n');
    pollAiLog(0);
}

function appendAiLog(text) {
    const log = document.getElementById('ai-scan-log');
    if (!log) return;
    log.textContent += text;
    log.scrollTop = log.scrollHeight;
}

async function pollAiLog(offset) {
    try {
        const res = await fetch('/share/admin.php?action=ai_scan_log&offset=' + offset);
        const data = await res.json();
        if (data.lines && data.lines.length) {
            appendAiLog(data.lines.join('\n') + '\n');
        }
        if (data.done) {
            onAiScanDone();
            return;
        }
        aiScanPollTimer = setTimeout(function() { pollAiLog(data.next_offset); }, 2000);
    } catch(e) {
        aiScanPollTimer = setTimeout(function() { pollAiLog(offset); }, 3000);
    }
}

function onAiScanDone() {
    const btn = document.getElementById('ai-scan-btn');
    btn.textContent = 'Vérification IA';
    btn.disabled = false;
    appendAiLog('\n--- Scan terminé ---');
    loadTmdbStatus(); // Rafraîchir les stats
}

function closeAiPanel() {
    clearTimeout(aiScanPollTimer);
    document.getElementById('ai-scan-panel').style.display = 'none';
}
```

- [ ] **Step 2 : Recharger PHP-FPM**

```bash
systemctl reload php8.3-fpm
```

- [ ] **Step 3 : Test end-to-end dans le navigateur**

1. Ouvrir `/share/admin.php#systeme`
2. Cliquer "Vérification IA"
3. Vérifier : bouton passe en "IA en cours...", panneau s'ouvre, lignes apparaissent toutes les 2s
4. Attendre la fin : bouton se réactive, "--- Scan terminé ---" apparaît, stats TMDB se rechargent
5. Cliquer "×" : panneau se referme

- [ ] **Step 4 : Vérifier le log brut**

```bash
tail -20 /var/www/sharebox/data/ai-scan.log
```
Expected : sortie textuelle de claude, sans codes ANSI visibles

---

## Notes d'implémentation

- `posix_kill($pid, 0)` retourne `true` si le process existe, sans l'envoyer de signal. Disponible sur toutes les Debian Linux avec PHP.
- Le lock file `data/sharebox_ai_scan.lock` n'est PAS nettoyé automatiquement après la fin — il garde le dernier PID pour référence. Il est écrasé au prochain lancement.
- Si le log fait > 64 KB par poll, le prochain poll récupère la suite automatiquement (offset incrémental).
- `btn-ghost` pour "Scan TMDB" et `btn-accent` pour "Vérification IA" : le bouton IA est plus visible car c'est la nouvelle fonctionnalité principale.
