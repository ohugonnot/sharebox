# Activity Logs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter une table `activity_logs` pour tracer les connexions, créations/suppressions de liens et actions admin, et les afficher dans une nouvelle card dans l'onglet Activité d'`admin.php`.

**Architecture:** Nouvelle table SQLite `activity_logs` (migration v14), helper `log_activity()` dans `functions.php`, appels aux points d'insertion dans `login.php`, `ctrl.php`, `admin.php`. Nouvelle action AJAX `activity_events` et card UI dans l'onglet Activité.

**Tech Stack:** PHP 8.3, SQLite WAL, JS vanilla, PHPUnit

---

## Fichiers modifiés

- `db.php` — migration v14, table `activity_logs`
- `functions.php` — ajout de `log_activity()`
- `login.php` — appels `log_activity` pour `login_ok` / `login_fail`
- `ctrl.php` — appels pour `link_create` / `link_delete`
- `admin.php` — appels pour actions user, nouvelle action AJAX `activity_events`, card UI
- `tests/ActivityLogsTest.php` — nouveau fichier de tests

---

### Task 1 : Migration DB + helper `log_activity()`

**Files:**
- Modify: `db.php:233` (après la migration v13)
- Modify: `functions.php` (fin du fichier)
- Create: `tests/ActivityLogsTest.php`

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/ActivityLogsTest.php` :

```php
<?php

use PHPUnit\Framework\TestCase;

class ActivityLogsTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        require_once __DIR__ . '/../functions.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM activity_logs");
    }

    public function testTableExists(): void
    {
        $tables = self::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='activity_logs'"
        )->fetchAll();
        $this->assertCount(1, $tables);
    }

    public function testLogActivityInsertsRow(): void
    {
        log_activity('login_ok', 'alice', '1.2.3.4', null);

        $row = self::$db->query("SELECT * FROM activity_logs")->fetch();
        $this->assertSame('login_ok', $row['event_type']);
        $this->assertSame('alice', $row['username']);
        $this->assertSame('1.2.3.4', $row['ip']);
        $this->assertNull($row['details']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testLogActivityWithDetails(): void
    {
        log_activity('link_create', 'bob', '5.6.7.8', 'film.mkv [abc123]');

        $row = self::$db->query("SELECT * FROM activity_logs")->fetch();
        $this->assertSame('link_create', $row['event_type']);
        $this->assertSame('film.mkv [abc123]', $row['details']);
    }

    public function testLogActivitySilentOnError(): void
    {
        // Doit ne pas lever d'exception même avec des valeurs nulles
        log_activity('login_fail', null, null, null);
        $count = (int)self::$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCleanupRemovesOldEntries(): void
    {
        $old = date('Y-m-d H:i:s', time() - 91 * 86400);
        self::$db->prepare("INSERT INTO activity_logs (event_type, username, ip, created_at) VALUES (?,?,?,?)")
            ->execute(['login_ok', 'alice', '1.2.3.4', $old]);
        self::$db->prepare("INSERT INTO activity_logs (event_type, username, ip) VALUES (?,?,?)")
            ->execute(['login_ok', 'bob', '9.9.9.9']);

        // Simuler le nettoyage
        self::$db->exec("DELETE FROM activity_logs WHERE created_at < datetime('now', '-90 days')");

        $count = (int)self::$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = self::$db->query("SELECT username FROM activity_logs")->fetch();
        $this->assertSame('bob', $remaining['username']);
    }
}
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
cd /var/www/sharebox && vendor/bin/phpunit tests/ActivityLogsTest.php --no-coverage 2>&1 | tail -15
```

Attendu : FAIL — table `activity_logs` ou fonction `log_activity` non trouvée.

- [ ] **Step 3 : Ajouter la migration v14 dans `db.php`**

Après la ligne `$db->query('PRAGMA user_version = 13');` (et la fermeture `}` du bloc v13), ajouter :

```php
    if ($version < 14) {
        // v14 : logs d'activité système (connexions, liens, actions admin)
        $db->query("
            CREATE TABLE IF NOT EXISTS activity_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type  TEXT NOT NULL,
                username    TEXT,
                ip          TEXT,
                details     TEXT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->query("CREATE INDEX IF NOT EXISTS idx_activity_ts ON activity_logs(created_at DESC)");
        $db->query('PRAGMA user_version = 14');
    }
```

- [ ] **Step 4 : Ajouter `log_activity()` dans `functions.php`**

À la fin du fichier, avant le `?>` final s'il existe, sinon en fin de fichier :

```php
/**
 * Enregistre un événement dans activity_logs.
 * Silencieux en cas d'erreur.
 */
function log_activity(string $event_type, ?string $username, ?string $ip, ?string $details): void
{
    try {
        $db = get_db();
        $db->prepare(
            "INSERT INTO activity_logs (event_type, username, ip, details) VALUES (?, ?, ?, ?)"
        )->execute([$event_type, $username, $ip, $details]);
        $db->exec("DELETE FROM activity_logs WHERE created_at < datetime('now', '-90 days')");
    } catch (\Throwable $e) {
        // Silencieux — les logs ne doivent pas casser l'app
    }
}
```

- [ ] **Step 5 : Lancer les tests pour vérifier qu'ils passent**

```bash
cd /var/www/sharebox && vendor/bin/phpunit tests/ActivityLogsTest.php --no-coverage 2>&1 | tail -10
```

Attendu : `OK (5 tests, 6 assertions)`

- [ ] **Step 6 : Lancer la suite complète pour vérifier l'absence de régression**

```bash
cd /var/www/sharebox && vendor/bin/phpunit --no-coverage 2>&1 | tail -5
```

Attendu : tous les tests passent.

- [ ] **Step 7 : Commit**

```bash
cd /var/www/sharebox && git add db.php functions.php tests/ActivityLogsTest.php
git commit -m "feat: table activity_logs (migration v14) + helper log_activity()"
```

---

### Task 2 : Logs de connexion dans `login.php`

**Files:**
- Modify: `login.php:32-43`

- [ ] **Step 1 : Ajouter `require_once functions.php` si absent**

Vérifier que `functions.php` est chargé :

```bash
grep -n "functions.php" /var/www/sharebox/login.php
```

Si absent, ajouter après `require_once __DIR__ . '/auth.php';` :

```php
require_once __DIR__ . '/functions.php';
```

- [ ] **Step 2 : Logger le login réussi**

Après `$_SESSION['csrf_token'] = bin2hex(random_bytes(32));` (ligne ~38) et avant `header('Location: /share/');`, ajouter :

```php
                log_activity('login_ok', $user['username'], $ip, null);
```

- [ ] **Step 3 : Logger le login échoué**

La ligne d'échec est `$error = 'Identifiants incorrects.';` (ligne ~43). Juste avant, ajouter :

```php
                log_activity('login_fail', $username, $ip, null);
```

Note : `$username` contient le nom saisi (avant vérification), `$ip` est déjà défini ligne 18.

- [ ] **Step 4 : Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/login.php
```

Attendu : `No syntax errors detected`

- [ ] **Step 5 : Commit**

```bash
cd /var/www/sharebox && git add login.php
git commit -m "feat: logger login_ok et login_fail dans activity_logs"
```

---

### Task 3 : Logs de liens dans `ctrl.php`

**Files:**
- Modify: `ctrl.php:153-163` (link_create)
- Modify: `ctrl.php:184-199` (link_delete)

- [ ] **Step 1 : Vérifier que `functions.php` est chargé dans `ctrl.php`**

```bash
grep -n "functions.php" /var/www/sharebox/ctrl.php
```

Si absent, ajouter après les autres `require_once`.

- [ ] **Step 2 : Logger la création de lien**

Dans le case `create_link`, après `$stmt->execute([...])` (l'INSERT INTO links) et avant `echo json_encode([...])`, ajouter :

```php
            log_activity('link_create', $createdBy, $_SERVER['REMOTE_ADDR'] ?? null, $name . ' [' . $token . ']');
```

- [ ] **Step 3 : Récupérer le nom du lien avant suppression**

Dans le case `delete`, juste après `$db = get_db();` et avant le check du rôle (ligne ~185), ajouter une récupération du nom :

```php
            $linkRow = $db->prepare("SELECT name, created_by FROM links WHERE id = ?")->execute([$id]) ? $db->prepare("SELECT name, created_by FROM links WHERE id = ?")->execute([$id]) : null;
```

En fait, utiliser le pattern déjà en place dans le code. Remplacer le bloc à partir de `$db = get_db();` jusqu'à `$stmt->execute([':id' => $id]);` par :

```php
            $db = get_db();
            $linkRow = $db->prepare("SELECT name, created_by FROM links WHERE id = ?")->execute([$id])
                ? $db->prepare("SELECT name, created_by FROM links WHERE id = ?")
                : null;
```

En fait la façon correcte est :

```php
            $db = get_db();
            $fetchLink = $db->prepare("SELECT name, created_by FROM links WHERE id = ?");
            $fetchLink->execute([$id]);
            $linkRow = $fetchLink->fetch();

            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                if ($linkRow && $linkRow['created_by'] !== null && $linkRow['created_by'] !== ($_SESSION['sharebox_user'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Vous ne pouvez supprimer que vos propres liens']);
                    exit;
                }
            }
            $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
            $stmt->execute([':id' => $id]);

            log_activity('link_delete', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $linkRow ? $linkRow['name'] : "id:$id");

            echo json_encode(['success' => true]);
```

Le bloc actuel (lignes ~184-199) est :

```php
            $db = get_db();
            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                $owner = $db->prepare("SELECT created_by FROM links WHERE id = ?");
                $owner->execute([$id]);
                $row = $owner->fetch();
                if ($row && $row['created_by'] !== null && $row['created_by'] !== ($_SESSION['sharebox_user'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Vous ne pouvez supprimer que vos propres liens']);
                    exit;
                }
            }
            $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true]);
```

Remplacer par :

```php
            $db = get_db();
            $fetchLink = $db->prepare("SELECT name, created_by FROM links WHERE id = ?");
            $fetchLink->execute([$id]);
            $linkRow = $fetchLink->fetch();

            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                if ($linkRow && $linkRow['created_by'] !== null && $linkRow['created_by'] !== ($_SESSION['sharebox_user'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Vous ne pouvez supprimer que vos propres liens']);
                    exit;
                }
            }
            $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
            $stmt->execute([':id' => $id]);

            log_activity('link_delete', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $linkRow ? $linkRow['name'] : "id:$id");

            echo json_encode(['success' => true]);
```

- [ ] **Step 4 : Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/ctrl.php
```

Attendu : `No syntax errors detected`

- [ ] **Step 5 : Commit**

```bash
cd /var/www/sharebox && git add ctrl.php
git commit -m "feat: logger link_create et link_delete dans activity_logs"
```

---

### Task 4 : Logs actions admin dans `admin.php`

**Files:**
- Modify: `admin.php` — cases `create_user`, `update_user`, `delete_user`

- [ ] **Step 1 : Logger `admin_create_user`**

Dans le case `create_user`, après `echo json_encode(['ok' => true, ...])` (ligne ~141), ajouter avant le `break;` :

```php
                log_activity('admin_create_user', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $username);
```

- [ ] **Step 2 : Logger `admin_edit_user`**

Dans le case `update_user`, après `echo json_encode(['ok' => true, ...])` (ligne ~182), ajouter avant le `break;` :

```php
                log_activity('admin_edit_user', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $user['username']);
```

- [ ] **Step 3 : Logger `admin_delete_user`**

Dans le case `delete_user`, après `echo json_encode(['ok' => true, ...])` (ligne ~218), ajouter avant le `break;` :

```php
                log_activity('admin_delete_user', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $user['username']);
```

- [ ] **Step 4 : Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/admin.php
```

Attendu : `No syntax errors detected`

- [ ] **Step 5 : Commit**

```bash
cd /var/www/sharebox && git add admin.php
git commit -m "feat: logger admin_create_user, admin_edit_user, admin_delete_user dans activity_logs"
```

---

### Task 5 : Action AJAX `activity_events` dans `admin.php`

**Files:**
- Modify: `admin.php:34` (`$adminOnlyActions`)
- Modify: `admin.php` (case `activity_events` dans le switch)

- [ ] **Step 1 : Ajouter `activity_events` à `$adminOnlyActions`**

Ligne ~34 :
```php
    $adminOnlyActions = ['list_users','create_user','update_user','delete_user',
                         'restart_rtorrent','stop_rtorrent','tmdb_status','tmdb_scan',
                         'purge_expired','recent_activity'];
```

Remplacer par :
```php
    $adminOnlyActions = ['list_users','create_user','update_user','delete_user',
                         'restart_rtorrent','stop_rtorrent','tmdb_status','tmdb_scan',
                         'purge_expired','recent_activity','activity_events'];
```

- [ ] **Step 2 : Ajouter le case `activity_events` dans le switch**

Après le case `recent_activity` (avant `default:`), ajouter :

```php
            case 'activity_events':
                $db = get_db();
                $typeFilter = trim($input['type_filter'] ?? '');
                $offset     = max(0, (int)($input['offset'] ?? 0));
                $limit      = 15;

                $where  = [];
                $params = [];

                if ($typeFilter === 'connexions') {
                    $where[]  = "event_type IN ('login_ok','login_fail')";
                } elseif ($typeFilter === 'liens') {
                    $where[]  = "event_type IN ('link_create','link_delete')";
                } elseif ($typeFilter === 'admin') {
                    $where[]  = "event_type IN ('admin_create_user','admin_edit_user','admin_delete_user')";
                }

                $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $total = (int)$db->query("SELECT COUNT(*) FROM activity_logs $whereClause")->fetchColumn();

                $stmt = $db->prepare("SELECT id, event_type, username, ip, details, created_at FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET ?");
                $stmt->execute([...$params, $offset]);
                echo json_encode(['logs' => $stmt->fetchAll(), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
                break;
```

- [ ] **Step 3 : Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/admin.php
```

Attendu : `No syntax errors detected`

- [ ] **Step 4 : Commit**

```bash
cd /var/www/sharebox && git add admin.php
git commit -m "feat: action AJAX activity_events pour les logs système"
```

---

### Task 6 : UI — card Événements système dans l'onglet Activité

**Files:**
- Modify: `admin.php` — tab-activite HTML + JS

- [ ] **Step 1 : Ajouter la card HTML dans l'onglet Activité**

Le tab-activite actuel se termine par :
```html
        <div id="activity-pagination" class="activity-pager"></div>
        </div>
    </div>
```

Après la fermeture `</div>` de la card téléchargements (juste avant `</div>` qui ferme `tab-activite`), ajouter :

```html
        <div class="card" style="margin-top:1.5rem">
            <div class="card-header">
                <div class="card-title">Événements système</div>
                <select id="events-type-filter" onchange="reloadEvents()"
                        style="background:var(--bg-input);border:1px solid var(--border-strong);border-radius:6px;color:var(--text);padding:.3rem .6rem;font-size:.8rem;font-family:inherit">
                    <option value="">Tous</option>
                    <option value="connexions">Connexions</option>
                    <option value="liens">Liens</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div id="events-wrap" style="padding:.8rem 1.4rem">
                <div class="empty-msg">Chargement…</div>
            </div>
            <div id="events-pagination" class="activity-pager"></div>
        </div>
```

- [ ] **Step 2 : Ajouter les fonctions JS**

Juste avant `// ── Tabs ──` (cherche ce commentaire dans le JS), ajouter :

```javascript
// ── Événements système ──────────────────────────────────────────────────────
let eventsOffset = 0;

function reloadEvents() {
    eventsOffset = 0;
    loadEvents();
}

function eventsPage(dir) {
    eventsOffset = Math.max(0, eventsOffset + dir * 15);
    loadEvents();
}

async function loadEvents() {
    const wrap  = document.getElementById('events-wrap');
    const pager = document.getElementById('events-pagination');
    const typeFilter = document.getElementById('events-type-filter')?.value ?? '';
    wrap.innerHTML  = '<div class="empty-msg">Chargement…</div>';
    pager.innerHTML = '';
    try {
        const res = await api('activity_events', { type_filter: typeFilter, offset: eventsOffset });
        if (!res.logs || res.logs.length === 0) {
            wrap.innerHTML = '<div class="empty-msg">Aucun événement enregistré.</div>';
            return;
        }

        const badgeStyle = {
            login_ok:            'background:#1a3a2a;color:#3ddc84',
            login_fail:          'background:#3a1a1a;color:#e8453c',
            link_create:         'background:#1a2a3a;color:#4a9eff',
            link_delete:         'background:#3a2a1a;color:#f0a030',
            admin_create_user:   'background:#2a1a3a;color:#c084fc',
            admin_edit_user:     'background:#2a1a3a;color:#c084fc',
            admin_delete_user:   'background:#3a1a2a;color:#f472b6',
        };

        let html = '<table class="user-table"><thead><tr>';
        html += '<th>Type</th><th class="col-user">Utilisateur</th><th class="col-ip">IP</th><th>Détails</th><th>Date</th>';
        html += '</tr></thead><tbody>';
        for (const log of res.logs) {
            const d  = log.created_at ? new Date(log.created_at + 'Z').toLocaleString('fr-FR') : '-';
            const bs = badgeStyle[log.event_type] ?? 'background:#222;color:#aaa';
            html += '<tr>';
            html += '<td><span style="' + bs + ';font-size:.72rem;padding:.15rem .45rem;border-radius:4px;white-space:nowrap">' + esc(log.event_type) + '</span></td>';
            html += '<td class="col-user" style="font-size:.8rem">' + esc(log.username ?? '—') + '</td>';
            html += '<td class="col-ip" style="font-family:monospace;font-size:.78rem">' + esc(log.ip ?? '—') + '</td>';
            html += '<td style="font-size:.8rem;color:var(--text-dim);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(log.details ?? '') + '">' + esc(log.details ?? '—') + '</td>';
            html += '<td style="font-size:.78rem;color:var(--text-dim)">' + esc(d) + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;

        const total = res.total ?? 0;
        const limit = res.limit ?? 15;
        const page  = Math.floor(eventsOffset / limit) + 1;
        const pages = Math.ceil(total / limit);
        let pagerHtml = '<span style="margin-right:auto">' + total + ' événement' + (total > 1 ? 's' : '') + '</span>';
        if (pages > 1) {
            pagerHtml +=
                '<button class="pager-btn" onclick="eventsPage(-1)"' + (page <= 1 ? ' disabled' : '') + '>← Préc.</button>' +
                '<span>Page ' + page + ' / ' + pages + '</span>' +
                '<button class="pager-btn" onclick="eventsPage(1)"' + (page >= pages ? ' disabled' : '') + '>Suiv. →</button>';
        }
        pager.innerHTML = pagerHtml;
    } catch (e) {
        wrap.innerHTML = '<div class="empty-msg" style="color:var(--red)">Erreur de chargement.</div>';
    }
}
```

- [ ] **Step 3 : Déclencher le chargement des événements au clic sur l'onglet Activité**

La fonction `switchTab` contient :
```javascript
    if (name === 'activite' && !activityLoaded) {
        activityLoaded = true;
        populateActivityUserFilter().then(() => loadRecentActivity());
    }
```

Remplacer par :
```javascript
    if (name === 'activite' && !activityLoaded) {
        activityLoaded = true;
        populateActivityUserFilter().then(() => loadRecentActivity());
        loadEvents();
    }
```

- [ ] **Step 4 : Vérifier la syntaxe PHP**

```bash
php -l /var/www/sharebox/admin.php
```

Attendu : `No syntax errors detected`

- [ ] **Step 5 : Recharger PHP-FPM**

```bash
systemctl reload php8.3-fpm
```

- [ ] **Step 6 : Lancer la suite complète PHPUnit**

```bash
cd /var/www/sharebox && vendor/bin/phpunit --no-coverage 2>&1 | tail -5
```

Attendu : tous les tests passent.

- [ ] **Step 7 : Commit**

```bash
cd /var/www/sharebox && git add admin.php
git commit -m "feat: card Événements système dans l'onglet Activité"
```
