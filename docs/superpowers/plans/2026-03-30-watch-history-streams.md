# Watch History + Streams Actifs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter le suivi vu/non vu par utilisateur (marquage auto à 85%) et le monitoring des streams actifs dans l'admin.

**Architecture:** Watch history stocké en SQLite (table `watch_history`), déclenché depuis `player.js` via POST `ctrl.php`. Streams actifs via fichiers JSON dans `/tmp`, lus par une action admin dans `ctrl.php`. Affichage : badge `✓` sur les cartes listing, card "Streams actifs" dans l'onglet Système de l'admin.

**Tech Stack:** PHP 8.3, SQLite WAL, vanilla JS, `/proc/{pid}/stat` pour CPU ffmpeg.

---

## Fichiers touchés

| Fichier | Rôle |
|---|---|
| `db.php` | Migration v16 : table `watch_history` |
| `ctrl.php` | 2 nouvelles actions : `mark_watched`, `active_streams` |
| `download.php` | Injection `watchPath`+`csrfToken` dans `PLAYER_CONFIG`, écriture fichier stream info, badge `✓` dans `fetchPosters`, flag `watched` depuis `?posters` |
| `handlers/tmdb.php` | Ajout flag `watched` dans les réponses `?posters` (dossiers + vidéos) |
| `player.js` | Détection 85%, POST `mark_watched` |
| `admin.php` | Card "Streams actifs" dans l'onglet Système + polling JS |

---

## Task 1 : Migration BDD — table `watch_history`

**Files:**
- Modify: `db.php`

- [ ] **Ouvrir `db.php`, trouver le bloc `$targetVersion = 15`** et le passer à `16`

```php
$targetVersion = 16; // bump when adding migrations
```

- [ ] **Ajouter le bloc migration v16** juste avant `if ($version < $targetVersion)` :

```php
if ($version < 16) {
    // v16 : historique de visionnage par utilisateur
    $db->query("
        CREATE TABLE IF NOT EXISTS watch_history (
            user         TEXT NOT NULL,
            path         TEXT NOT NULL,
            watched_at   TEXT NOT NULL DEFAULT (datetime('now')),
            duration_sec INTEGER,
            PRIMARY KEY (user, path)
        )
    ");
    $db->query("CREATE INDEX IF NOT EXISTS idx_watch_user ON watch_history(user)");
    $db->query('PRAGMA user_version = 16');
}
```

- [ ] **Vérifier la migration**

```bash
sudo -u www-data php -r "
require '/var/www/sharebox/db.php';
\$db = get_db();
echo 'version: ' . \$db->query('PRAGMA user_version')->fetchColumn() . PHP_EOL;
echo \$db->query('SELECT COUNT(*) FROM watch_history')->fetchColumn() . ' rows' . PHP_EOL;
"
```
Attendu : `version: 16`, `0 rows`

- [ ] **Reload PHP-FPM**

```bash
systemctl reload php8.3-fpm
```

- [ ] **Commit**

```bash
git add db.php
git commit -m "feat: migration v16 — table watch_history (vu/non vu par user)"
```

---

## Task 2 : Action `mark_watched` dans `ctrl.php`

**Files:**
- Modify: `ctrl.php`

- [ ] **Localiser la fin du switch** dans `ctrl.php` (chercher `case 'change_password'`) et ajouter le case avant le `default` ou à la fin des cases existants :

```php
        case 'mark_watched':
            // POST {path, duration, csrf_token}
            // Disponible pour tout user connecté (non admin-only)
            $path = $input['path'] ?? '';
            $duration = isset($input['duration']) ? (int)$input['duration'] : null;
            $currentUser = $_SESSION['sharebox_user'] ?? '';
            if (!$currentUser || !$path) {
                http_response_code(400);
                echo json_encode(['error' => 'missing params']);
                break;
            }
            if (!file_exists($path)) {
                http_response_code(404);
                echo json_encode(['error' => 'file not found']);
                break;
            }
            $db->prepare("
                INSERT INTO watch_history (user, path, watched_at, duration_sec)
                VALUES (:u, :p, datetime('now'), :d)
                ON CONFLICT(user, path) DO UPDATE SET watched_at = datetime('now'), duration_sec = :d
            ")->execute([':u' => $currentUser, ':p' => $path, ':d' => $duration]);
            echo json_encode(['ok' => true]);
            break;
```

- [ ] **Tester via curl** (remplacer TOKEN et SESSION_COOKIE par des valeurs réelles) :

```bash
# Récupérer d'abord un CSRF token en chargeant la page (ou via dev tools)
# Puis :
curl -s -X POST 'http://localhost/share/ctrl.php?cmd=mark_watched' \
  -H 'Content-Type: application/json' \
  -b 'PHPSESSID=VOTRE_SESSION' \
  -d '{"path":"/data/test.mkv","duration":5400,"csrf_token":"VOTRE_TOKEN"}'
```
Attendu : `{"ok":true}`

- [ ] **Commit**

```bash
git add ctrl.php
git commit -m "feat: action mark_watched dans ctrl.php (vu/non vu)"
```

---

## Task 3 : Injection dans le player + déclenchement à 85%

**Files:**
- Modify: `download.php` (fonction `afficher_player`)
- Modify: `player.js`

- [ ] **Dans `download.php`, dans `afficher_player()`**, ajouter le calcul du chemin et de l'user avant le bloc `PLAYER_CONFIG`. Localiser `$remuxEnabled = STREAM_REMUX_ENABLED` et ajouter juste avant :

```php
    // Watch history : disponible uniquement si l'user est connecté
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $watchUser = $_SESSION['sharebox_user'] ?? null;
    $watchPath = $watchUser ? ($subPath ? $basePath . '/' . $subPath : $basePath) : null;
    $watchCsrf = $watchUser ? ($_SESSION['csrf_token'] ?? null) : null;
    $watchPathJs = $watchPath ? json_encode($watchPath) : 'null';
    $watchCsrfJs = $watchCsrf ? json_encode($watchCsrf) : 'null';
```

- [ ] **Ajouter `watchPath` et `watchCsrf` dans `PLAYER_CONFIG`**. Localiser le bloc `var PLAYER_CONFIG = {` et l'étendre :

```php
var PLAYER_CONFIG = {
    remuxEnabled: {$remuxEnabled},
    isVideo: {$isVideo},
    baseUrl: '{$baseUrl}',
    pp: '{$pParamJs}',
    episodeNav: {$episodeNavJson},
    watchPath: {$watchPathJs},
    watchCsrf: {$watchCsrfJs}
};
```

- [ ] **Dans `player.js`**, localiser `var base = PLAYER_CONFIG.baseUrl` (ligne ~38) et ajouter après :

```js
    var watchPath   = PLAYER_CONFIG.watchPath  || null;
    var watchCsrf   = PLAYER_CONFIG.watchCsrf  || null;
    var watchMarked = false;
```

- [ ] **Dans `player.js`**, localiser la fonction `startStream` et ajouter une réinitialisation du flag au début de la fonction, juste après `plog('STREAM', 'startStream` :

```js
        watchMarked = false;
```

- [ ] **Dans `player.js`**, localiser le `player.addEventListener('timeupdate'` (ligne ~564) et ajouter la détection 85% à l'intérieur, après `updateBuffered(); Subs.render();` :

```js
        // Watch history : marquer vu à 85% (une seule fois par lecture)
        if (!watchMarked && watchPath && watchCsrf && S.duration > 60) {
            if (realTime() / S.duration >= 0.85) {
                watchMarked = true;
                fetch('/share/ctrl.php?cmd=mark_watched', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({path: watchPath, duration: Math.round(S.duration), csrf_token: watchCsrf})
                }).catch(function(){});
            }
        }
```

- [ ] **Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/download.php
```
Attendu : `No syntax errors detected`

- [ ] **Tester manuellement** : ouvrir un film, avancer à 86% dans la barre de seek, vérifier dans les devtools Network qu'un POST `ctrl.php?cmd=mark_watched` part avec status 200.

- [ ] **Vérifier en BDD**

```bash
sudo -u www-data php -r "
require '/var/www/sharebox/db.php';
\$db = get_db();
print_r(\$db->query('SELECT * FROM watch_history LIMIT 5')->fetchAll(PDO::FETCH_ASSOC));
"
```

- [ ] **Reload + commit**

```bash
systemctl reload php8.3-fpm
git add download.php player.js
git commit -m "feat: marquer vu à 85% depuis le player (watch_history)"
```

---

## Task 4 : Badge ✓ dans le listing

**Files:**
- Modify: `handlers/tmdb.php`
- Modify: `download.php` (CSS + JS fetchPosters)

### 4a — Flag `watched` dans l'endpoint `?posters`

- [ ] **Dans `handlers/tmdb.php`**, au début du bloc `if (isset($_GET['posters']))`, après `$result = [];`, ajouter la récupération des chemins vus par l'user courant :

```php
    // Watch history : chemins vus par l'user courant
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $watchUser = $_SESSION['sharebox_user'] ?? null;
    $watchedPaths = [];
    if ($watchUser) {
        $wStmt = $db->prepare("SELECT path FROM watch_history WHERE user = :u");
        $wStmt->execute([':u' => $watchUser]);
        $watchedPaths = array_flip($wStmt->fetchAll(PDO::FETCH_COLUMN));
    }
```

- [ ] **Dans la section dossiers**, après `if ($row['tmdb_rating'] > 0) $cached[$f]['rating'] = ...`, ajouter :

```php
                $fullPathCheck = $resolvedPath . '/' . $f;
                if (isset($watchedPaths[$fullPathCheck])) $cached[$f]['watched'] = true;
```

- [ ] **Dans la section vidéos (movies mode)**, après `if ($row['tmdb_rating'] > 0) $result[$vf]['rating'] = ...`, ajouter :

```php
                    $fullPathCheck = $resolvedPath . '/' . $vf;
                    if (isset($watchedPaths[$fullPathCheck])) $result[$vf]['watched'] = true;
```

- [ ] **Vérifier la syntaxe**

```bash
php -l /var/www/sharebox/handlers/tmdb.php
```

### 4b — CSS badge ✓

- [ ] **Dans `download.php`**, localiser `.grid-card-rating {` et ajouter juste après :

```css
.grid-card-watched { position:absolute; bottom:.42rem; right:.5rem; width:18px; height:18px; border-radius:50%; background:#3ddc84; display:flex; align-items:center; justify-content:center; z-index:4; pointer-events:none; }
.grid-card-watched svg { width:10px; height:10px; color:#000; }
```

### 4c — JS : afficher le badge dans fetchPosters

- [ ] **Dans `download.php`**, dans le JS `fetchPosters`, localiser `if (rating >= 1) {` (le bloc qui crée le badge rating) et ajouter juste après son bloc fermant `}` :

```js
                        // Badge vu
                        if (info.watched && !card.querySelector('.grid-card-watched')) {
                            var wb = document.createElement('div');
                            wb.className = 'grid-card-watched';
                            wb.title = 'Vu';
                            wb.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>';
                            card.appendChild(wb);
                        }
```

- [ ] **Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/download.php
```

- [ ] **Reload + commit**

```bash
systemctl reload php8.3-fpm
git add handlers/tmdb.php download.php
git commit -m "feat: badge vu/non vu sur les cartes listing"
```

---

## Task 5 : Écriture fichier d'état stream (pour monitoring)

**Files:**
- Modify: `download.php`

- [ ] **Dans `download.php`**, localiser les blocs de dispatch stream (vers les handlers). Ajouter une fonction helper juste avant `// Mode streaming natif` :

```php
/**
 * Écrit un fichier d'état stream dans /tmp pour le monitoring admin.
 * Appelé à chaque requête stream (HLS le rafraîchit naturellement toutes les ~5s).
 */
function write_stream_info(string $mode, string $resolvedPath, string $token, ?string $hlsPidFile = null): void {
    if (PHP_SAPI === 'cli') return;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = $_SESSION['sharebox_user'] ?? 'anonymous';
    $key  = md5(session_id());
    $file = '/tmp/sharebox_stream_' . $key . '.json';
    $existing = @file_get_contents($file);
    $startTime = $existing ? (json_decode($existing, true)['start_time'] ?? date('c')) : date('c');
    $data = [
        'user'         => $user,
        'filename'     => basename($resolvedPath),
        'path'         => $resolvedPath,
        'token'        => $token,
        'mode'         => $mode,
        'hls_pid_file' => $hlsPidFile,
        'start_time'   => $startTime,
        'last_seen'    => time(),
    ];
    file_put_contents($file, json_encode($data));
}
```

- [ ] **Ajouter l'appel avant chaque dispatch stream**. Localiser les 3 blocs et les modifier :

```php
    // Mode streaming natif
    if (isset($_GET['stream']) && $_GET['stream'] === '1') {
        write_stream_info('native', $resolvedPath, $token);
        require __DIR__ . '/handlers/stream_native.php';
    }
```

```php
    if (isset($_GET['stream']) && $_GET['stream'] === 'transcode') {
        write_stream_info('transcode', $resolvedPath, $token);
        require __DIR__ . '/handlers/stream_transcode.php';
    }
```

```php
    if (isset($_GET['stream']) && $_GET['stream'] === 'hls') {
        $hlsKey     = md5($resolvedPath . '|' . (isset($_GET['quality']) ? (int)$_GET['quality'] : 720) . '|' . (isset($_GET['audio']) ? (int)$_GET['audio'] : 0) . '|' . (isset($_GET['burn_sub']) ? (int)$_GET['burn_sub'] : -1) . '|' . (isset($_GET['start']) ? (float)$_GET['start'] : 0));
        $hlsPidFile = sys_get_temp_dir() . '/hls_' . $hlsKey . '/ffmpeg.pid';
        write_stream_info('hls', $resolvedPath, $token, $hlsPidFile);
        require __DIR__ . '/handlers/stream_hls.php';
    }
```

Note : le `$hlsKey` ici est recalculé avec les mêmes paramètres que dans `stream_hls.php` pour pointer vers le bon pid file.

- [ ] **Vérifier la syntaxe**

```bash
php -l /var/www/sharebox/download.php
```

- [ ] **Tester** : lancer un stream depuis l'interface, puis vérifier :

```bash
ls /tmp/sharebox_stream_*.json 2>/dev/null && cat /tmp/sharebox_stream_*.json | python3 -m json.tool
```
Attendu : un fichier JSON avec user, filename, mode, last_seen.

- [ ] **Reload + commit**

```bash
systemctl reload php8.3-fpm
git add download.php
git commit -m "feat: écriture fichier état stream pour monitoring admin"
```

---

## Task 6 : Action `active_streams` dans `ctrl.php`

**Files:**
- Modify: `ctrl.php`

- [ ] **Ajouter le helper de lecture CPU** (au-dessus du `switch` ou dans une fonction dans `ctrl.php`) :

```php
/**
 * Calcule le CPU% d'un PID en lisant /proc/{pid}/stat deux fois avec 500ms de delta.
 * Retourne null si le PID est invalide ou /proc non disponible.
 */
function get_pid_cpu(int $pid): ?float {
    if ($pid <= 0 || !file_exists("/proc/$pid")) return null;
    $read = function() use ($pid) {
        $stat = @file_get_contents("/proc/$pid/stat");
        if (!$stat) return null;
        $parts = explode(' ', $stat);
        // utime=13, stime=14 (index dans /proc/pid/stat)
        return (isset($parts[13], $parts[14])) ? (int)$parts[13] + (int)$parts[14] : null;
    };
    $cpuHz = (int)shell_exec('getconf CLK_TCK 2>/dev/null') ?: 100;
    $t1 = $read(); if ($t1 === null) return null;
    usleep(500000); // 500ms
    $t2 = $read(); if ($t2 === null) return null;
    return round(($t2 - $t1) / $cpuHz / 0.5 * 100, 1);
}
```

- [ ] **Ajouter le case `active_streams`** dans le switch (admin-only) :

```php
        case 'active_streams':
            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Admin requis']);
                break;
            }
            $files   = glob('/tmp/sharebox_stream_*.json') ?: [];
            $now     = time();
            $streams = [];
            foreach ($files as $f) {
                $data = @json_decode(file_get_contents($f), true);
                if (!$data || ($now - ($data['last_seen'] ?? 0)) > 120) continue;
                $pid = null;
                $cpu = null;
                if ($data['mode'] === 'hls' && !empty($data['hls_pid_file'])) {
                    $pidFile = $data['hls_pid_file'];
                    if (is_file($pidFile)) {
                        $pid = (int)file_get_contents($pidFile);
                        if ($pid > 0 && file_exists("/proc/$pid")) {
                            $cpu = get_pid_cpu($pid);
                        }
                    }
                }
                $streams[] = [
                    'user'     => $data['user'],
                    'filename' => $data['filename'],
                    'mode'     => $data['mode'],
                    'duration' => $now - strtotime($data['start_time']),
                    'cpu_pct'  => $cpu,
                ];
            }
            echo json_encode(['streams' => $streams]);
            break;
```

- [ ] **Tester l'action** (avec un stream actif) :

```bash
# Depuis le navigateur dev tools ou curl avec session admin :
curl -s 'http://localhost/share/ctrl.php?cmd=active_streams' -b 'PHPSESSID=SESSION_ADMIN'
```
Attendu : `{"streams":[{"user":"...","filename":"...","mode":"hls","duration":42,"cpu_pct":85.3}]}`

Sans stream : `{"streams":[]}`

- [ ] **Commit**

```bash
git add ctrl.php
git commit -m "feat: action active_streams dans ctrl.php (monitoring admin)"
```

---

## Task 7 : Card "Streams actifs" dans l'admin

**Files:**
- Modify: `admin.php`

- [ ] **Dans `admin.php`**, dans l'onglet Système (`id="tab-systeme"`), ajouter la card HTML **avant** la section TMDB existante. Localiser `<div id="tab-systeme"` et ajouter en début de contenu :

```html
<div class="card" style="margin-bottom:1.2rem">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between">
        <span>Streams actifs</span>
        <span id="streams-refresh-indicator" style="font-size:.72rem;color:var(--text-muted)"></span>
    </div>
    <div id="streams-container">
        <div class="empty-msg">Chargement…</div>
    </div>
</div>
```

- [ ] **Dans le JS de `admin.php`**, ajouter la fonction `loadActiveStreams` et son polling. Localiser `function switchTab` et ajouter avant :

```js
async function loadActiveStreams() {
    const res = await api('active_streams', null);
    const el = document.getElementById('streams-container');
    const ind = document.getElementById('streams-refresh-indicator');
    if (!el) return;
    if (!res || !res.streams) { el.innerHTML = '<div class="empty-msg">Erreur de chargement</div>'; return; }
    if (res.streams.length === 0) {
        el.innerHTML = '<div class="empty-msg">Aucun stream actif</div>';
    } else {
        let html = '<table class="user-table"><thead><tr>'
            + '<th>Utilisateur</th><th>Fichier</th><th>Mode</th><th>Durée</th><th>CPU</th>'
            + '</tr></thead><tbody>';
        res.streams.forEach(s => {
            const dur = s.duration >= 3600
                ? Math.floor(s.duration/3600)+'h'+String(Math.floor((s.duration%3600)/60)).padStart(2,'0')+'m'
                : Math.floor(s.duration/60)+'m'+String(s.duration%60).padStart(2,'0')+'s';
            const cpu = s.cpu_pct !== null ? s.cpu_pct.toFixed(1)+'%' : '—';
            const cpuColor = s.cpu_pct !== null && s.cpu_pct > 90 ? 'color:#e8453c' : '';
            html += `<tr>
                <td>${esc(s.user)}</td>
                <td title="${esc(s.filename)}" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.filename)}</td>
                <td><span class="badge" style="font-size:.7rem">${esc(s.mode)}</span></td>
                <td>${dur}</td>
                <td style="${cpuColor}">${cpu}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }
    if (ind) ind.textContent = 'Mis à jour ' + new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
```

- [ ] **Démarrer le polling au chargement de l'onglet Système**. Localiser `function switchTab(name)` et ajouter dans le body :

```js
    if (name === 'systeme') {
        loadActiveStreams();
        if (!window._streamsInterval) {
            window._streamsInterval = setInterval(loadActiveStreams, 10000);
        }
    } else {
        if (window._streamsInterval) {
            clearInterval(window._streamsInterval);
            window._streamsInterval = null;
        }
    }
```

- [ ] **Déclencher aussi au chargement initial** si l'onglet actif est `systeme`. Localiser le bloc `if (_urlTab && document.getElementById('tab-' + _urlTab))` et après `switchTab(_urlTab)` vérifier que le polling s'initialise correctement (il le sera via `switchTab`).

- [ ] **Tester** : aller dans l'onglet Système, lancer un stream depuis un autre onglet, vérifier que la card se met à jour en 10s.

- [ ] **Commit**

```bash
git add admin.php
git commit -m "feat: card streams actifs dans l'admin (monitoring temps réel)"
```

---

## Task 8 : Log historique stream dans `activity_logs`

**Files:**
- Modify: `download.php` (fonction `write_stream_info`)

- [ ] **Dans la fonction `write_stream_info`** dans `download.php`, ajouter le log au premier appel (quand `$existing` est null). Modifier la fonction :

```php
function write_stream_info(string $mode, string $resolvedPath, string $token, ?string $hlsPidFile = null): void {
    if (PHP_SAPI === 'cli') return;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = $_SESSION['sharebox_user'] ?? 'anonymous';
    $key  = md5(session_id());
    $file = '/tmp/sharebox_stream_' . $key . '.json';
    $existing = @file_get_contents($file);
    $startTime = $existing ? (json_decode($existing, true)['start_time'] ?? date('c')) : date('c');
    // Log stream_start uniquement à la première écriture
    if (!$existing) {
        log_activity('stream_start', $user, $_SERVER['REMOTE_ADDR'] ?? null,
            'mode=' . $mode . ' | file=' . basename($resolvedPath) . ' | token=' . $token);
    }
    $data = [
        'user'         => $user,
        'filename'     => basename($resolvedPath),
        'path'         => $resolvedPath,
        'token'        => $token,
        'mode'         => $mode,
        'hls_pid_file' => $hlsPidFile,
        'start_time'   => $startTime,
        'last_seen'    => time(),
    ];
    file_put_contents($file, json_encode($data));
}
```

- [ ] **Vérifier que `log_activity` est accessible** dans le contexte de `download.php` (il est défini dans `functions.php` qui est require'd en tête de `download.php`). Vérifier :

```bash
grep "require.*functions" /var/www/sharebox/download.php | head -3
```
Attendu : `require_once __DIR__ . '/functions.php';`

- [ ] **Vérifier syntaxe**

```bash
php -l /var/www/sharebox/download.php
```

- [ ] **Vérifier le log après un stream**

```bash
sudo -u www-data php -r "
require '/var/www/sharebox/db.php';
\$db = get_db();
print_r(\$db->query(\"SELECT * FROM activity_logs WHERE event_type='stream_start' ORDER BY created_at DESC LIMIT 3\")->fetchAll(PDO::FETCH_ASSOC));
"
```

- [ ] **Reload + commit final**

```bash
systemctl reload php8.3-fpm
git add download.php
git commit -m "feat: log stream_start dans activity_logs (historique streams)"
```
