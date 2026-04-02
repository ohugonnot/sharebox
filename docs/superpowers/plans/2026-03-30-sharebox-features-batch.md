# ShareBox Features Batch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter 7 features au projet ShareBox en 2 groupes — d'abord les features sans migration DB, puis celles qui en requièrent une.

**Architecture:** Groupe 1 (badge, filtre JS, changement mdp, purge, quota) modifie les fichiers existants sans toucher au schéma SQLite. Groupe 2 (max_downloads, download_logs) ajoute les migrations v12/v13 dans `db.php` et étend `download.php`, `ctrl.php`, `admin.php`. Les helpers purs (`change_password_for_user`, `purge_expired_links`) vont dans `functions.php` pour rester testables indépendamment.

**Tech Stack:** PHP 8.3, SQLite WAL, PHPUnit, vanilla JS, CSS custom properties. Pas de nouvelles dépendances.

---

## Fichiers modifiés

| Fichier | Raison |
|---------|--------|
| `functions.php` | Ajoute `change_password_for_user()` et `purge_expired_links()` |
| `ctrl.php` | Action `change_password`, champ `max_downloads` dans `create` |
| `admin.php` | Actions `purge_expired`, `disk_quota` (dans list_users), `recent_activity` ; HTML sections ; JS |
| `index.php` | Badge "créé par", input filtre, affichage max_downloads sur cartes |
| `header.php` | Bouton "Mon compte" + modal changement mdp |
| `app.js` | Filtre JS, modal mdp, max_downloads dans share sheet, reset filtre |
| `download.php` | Check max_downloads, insert download_logs |
| `db.php` | Migrations v12 (max_downloads) + v13 (download_logs) |
| `style.css` | Styles filtre, modal compte, badge-owner, état épuisé, sections admin |
| `tests/ChangePasswordTest.php` | Nouveau |
| `tests/PurgeExpiredTest.php` | Nouveau |
| `tests/MaxDownloadsTest.php` | Nouveau |
| `tests/DownloadLogsTest.php` | Nouveau |

---

## Groupe 1 — Sans migration DB

---

### Task 1 : Badge "créé par" sur les cartes de liens

**Files:**
- Modify: `index.php` (fonction `afficher_liens()`, vers ligne 107)
- Modify: `style.css`

- [ ] **Step 1 : Ajouter le badge dans `afficher_liens()`**

Dans `index.php`, après `if ($pwdHtml) echo $pwdHtml;` (ligne ~107), ajouter :

```php
        if ($currentRole === 'admin' && !empty($link['created_by'])) {
            $owner = htmlspecialchars($link['created_by']);
            echo "<span class=\"badge badge-owner\">{$owner}</span>";
        }
```

- [ ] **Step 2 : Ajouter le style `.badge-owner` dans `style.css`**

Chercher `.badge-password` dans `style.css` et ajouter juste après :

```css
.badge-owner { background: rgba(66, 165, 245, .1); color: var(--blue); border: 1px solid rgba(66, 165, 245, .15); }
```

- [ ] **Step 3 : Vérifier visuellement + tests existants**

```bash
vendor/bin/phpunit tests/PrivacyTest.php -v
```

Expected: PASS (les tests de visibilité des liens ne sont pas affectés)

- [ ] **Step 4 : Commit**

```bash
git add index.php style.css
git commit -m "feat: badge 'créé par' sur les cartes de liens (vue admin)"
```

---

### Task 2 : Filtre JS dans le navigateur de fichiers

**Files:**
- Modify: `index.php` (section `#file-list`)
- Modify: `app.js` (fonctions `afficherFichiers` et init)
- Modify: `style.css`

- [ ] **Step 1 : Ajouter l'input de filtre dans `index.php`**

Chercher `<ul id="file-list"` dans `index.php` et le remplacer par :

```html
        <div class="file-filter-wrap">
            <input type="search" id="file-filter" class="file-filter" placeholder="Filtrer les fichiers…" autocomplete="off">
        </div>
        <ul id="file-list" class="file-list">
```

- [ ] **Step 2 : Réinitialiser le filtre à chaque navigation dans `app.js`**

Dans la fonction `afficherFichiers(entries)`, après `list.innerHTML = '';` (première ligne de la fonction, vers ligne 101), ajouter :

```js
    const filterInput = document.getElementById('file-filter');
    if (filterInput) filterInput.value = '';
```

- [ ] **Step 3 : Ajouter le listener de filtre dans `app.js`**

À la fin du fichier `app.js`, ajouter :

```js
// ── Filtre fichiers ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const filterInput = document.getElementById('file-filter');
    if (!filterInput) return;
    filterInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#file-list .file-item').forEach(li => {
            const name = li.querySelector('.file-name')?.textContent?.toLowerCase() ?? '';
            li.style.display = q === '' || name.includes(q) ? '' : 'none';
        });
    });
});
```

- [ ] **Step 4 : Ajouter les styles CSS**

Dans `style.css`, ajouter après les styles `.panel` existants :

```css
.file-filter-wrap { padding: .5rem .8rem .2rem; }
.file-filter {
    width: 100%;
    background: var(--bg-input, #0d1018);
    border: 1px solid var(--border, rgba(255,255,255,.04));
    border-radius: 8px;
    padding: .45rem .75rem;
    color: var(--text, #d8dce8);
    font-size: .85rem;
    outline: none;
    font-family: inherit;
}
.file-filter:focus { border-color: var(--accent, #f0a030); }
```

- [ ] **Step 5 : Vérifier que les tests passent toujours**

```bash
vendor/bin/phpunit -v
```

Expected: tous PASS (changements purement front-end)

- [ ] **Step 6 : Commit**

```bash
git add index.php app.js style.css
git commit -m "feat: filtre JS dans le navigateur de fichiers"
```

---

### Task 3 : Changement de mot de passe

**Files:**
- Modify: `functions.php` (nouvelle fonction `change_password_for_user`)
- Modify: `ctrl.php` (nouvelle action `change_password`)
- Modify: `header.php` (bouton + modal)
- Modify: `app.js` (fonctions modal)
- Modify: `style.css` (styles modal)
- Create: `tests/ChangePasswordTest.php`

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/ChangePasswordTest.php` :

```php
<?php

use PHPUnit\Framework\TestCase;

class ChangePasswordTest extends TestCase
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
        self::$db->query("DELETE FROM users WHERE username = 'testpwd'");
        self::$db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)")
            ->execute(['testpwd', password_hash('oldpass', PASSWORD_BCRYPT), 'user']);
    }

    public function testSuccessfulChange(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'oldpass', 'newpass1', 'newpass1');
        $this->assertArrayHasKey('ok', $result);
        $row = self::$db->query("SELECT password_hash FROM users WHERE username = 'testpwd'")->fetch();
        $this->assertTrue(password_verify('newpass1', $row['password_hash']));
    }

    public function testWrongCurrentPassword(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'wrongpass', 'newpass1', 'newpass1');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsStringIgnoringCase('actuel', $result['error']);
    }

    public function testConfirmationMismatch(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'oldpass', 'newpass1', 'different');
        $this->assertArrayHasKey('error', $result);
    }

    public function testTooShortNewPassword(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'oldpass', 'ab', 'ab');
        $this->assertArrayHasKey('error', $result);
    }

    public function testUnknownUserReturnsError(): void
    {
        $result = change_password_for_user(self::$db, 'nobody', 'pass', 'newpass1', 'newpass1');
        $this->assertArrayHasKey('error', $result);
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
vendor/bin/phpunit tests/ChangePasswordTest.php -v
```

Expected: FAIL avec "Call to undefined function change_password_for_user()"

- [ ] **Step 3 : Implémenter `change_password_for_user` dans `functions.php`**

À la fin de `functions.php`, ajouter :

```php
/**
 * Change le mot de passe d'un utilisateur.
 * Retourne ['ok' => true] ou ['error' => '...'].
 */
function change_password_for_user(PDO $db, string $username, string $currentPwd, string $newPwd, string $confirmPwd): array {
    if (strlen($newPwd) < 4) {
        return ['error' => 'Nouveau mot de passe : 4 caractères minimum'];
    }
    if ($newPwd !== $confirmPwd) {
        return ['error' => 'La confirmation ne correspond pas'];
    }
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($currentPwd, $user['password_hash'])) {
        return ['error' => 'Mot de passe actuel incorrect'];
    }
    $db->prepare("UPDATE users SET password_hash = ? WHERE username = ?")
       ->execute([password_hash($newPwd, PASSWORD_BCRYPT), $username]);
    return ['ok' => true];
}
```

- [ ] **Step 4 : Vérifier que les tests passent**

```bash
vendor/bin/phpunit tests/ChangePasswordTest.php -v
```

Expected: 5 PASS

- [ ] **Step 5 : Ajouter l'action `change_password` dans `ctrl.php`**

Dans `ctrl.php`, dans le `switch ($action)`, ajouter un nouveau `case` après le case `send_email` :

```php
        case 'change_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }
            $username = $_SESSION['sharebox_user'] ?? '';
            if (empty($username)) {
                http_response_code(401);
                echo json_encode(['error' => 'Non authentifié']);
                exit;
            }
            echo json_encode(change_password_for_user(
                get_db(),
                $username,
                $input['current_password'] ?? '',
                $input['new_password'] ?? '',
                $input['confirm_password'] ?? ''
            ));
            break;
```

- [ ] **Step 6 : Ajouter le bouton "Mon compte" dans `header.php`**

Dans `header.php`, repérer la ligne avec `logout.php` et ajouter le bouton juste avant :

```php
        <button onclick="ouvrirModalCompte()" style="color:var(--text-secondary,#8892a4);font-size:.8rem;background:none;border:1px solid var(--border,rgba(255,255,255,.04));border-radius:var(--radius-sm,6px);padding:.3rem .6rem;cursor:pointer">Mon compte</button>
```

Puis, juste avant la balise `</header>` fermante, ajouter le modal (en dehors du header pour le z-index) — en fait juste après `</header>` :

```php
</header>
<div id="modal-compte" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;align-items:center;justify-content:center">
    <div style="background:var(--bg-card,#111420);border:1px solid var(--border-strong,rgba(255,255,255,.08));border-radius:14px;padding:1.5rem;width:100%;max-width:360px;margin:1rem">
        <div style="font-size:1rem;font-weight:600;margin-bottom:1.2rem">Changer le mot de passe</div>
        <div class="modal-compte-label">Mot de passe actuel</div>
        <input type="password" id="mdp-actuel" class="modal-compte-input" autocomplete="current-password">
        <div class="modal-compte-label">Nouveau mot de passe</div>
        <input type="password" id="mdp-nouveau" class="modal-compte-input" autocomplete="new-password">
        <div class="modal-compte-label">Confirmation</div>
        <input type="password" id="mdp-confirm" class="modal-compte-input" autocomplete="new-password">
        <div id="mdp-error" style="display:none;color:var(--red,#e8453c);font-size:.82rem;margin-top:.5rem"></div>
        <div style="display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.2rem">
            <button onclick="fermerModalCompte()" style="padding:.4rem .8rem;background:transparent;color:var(--text-dim,#5a6078);border:1px solid var(--border-strong,rgba(255,255,255,.08));border-radius:6px;cursor:pointer;font-family:inherit;font-size:.82rem">Annuler</button>
            <button id="mdp-submit" onclick="soumettreChangementMdp()" style="padding:.4rem .8rem;background:var(--accent,#f0a030);color:#000;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600">Enregistrer</button>
        </div>
    </div>
</div>
```

- [ ] **Step 7 : Ajouter les styles pour les inputs du modal dans `style.css`**

```css
.modal-compte-label { font-size: .78rem; color: var(--text-dim); margin-bottom: .3rem; margin-top: .8rem; }
.modal-compte-input { width: 100%; background: var(--bg-input, #0d1018); border: 1px solid var(--border, rgba(255,255,255,.04)); border-radius: 8px; padding: .5rem .75rem; color: var(--text, #d8dce8); font-size: .88rem; outline: none; font-family: inherit; box-sizing: border-box; }
.modal-compte-input:focus { border-color: var(--accent, #f0a030); }
```

- [ ] **Step 8 : Ajouter les fonctions JS dans `app.js`**

À la fin de `app.js`, ajouter :

```js
// ── Modal changement de mot de passe ────────────────────────────────────────
function ouvrirModalCompte() {
    const m = document.getElementById('modal-compte');
    if (!m) return;
    m.style.display = 'flex';
    document.getElementById('mdp-actuel').value = '';
    document.getElementById('mdp-nouveau').value = '';
    document.getElementById('mdp-confirm').value = '';
    document.getElementById('mdp-error').style.display = 'none';
    document.getElementById('mdp-actuel').focus();
}
function fermerModalCompte() {
    const m = document.getElementById('modal-compte');
    if (m) m.style.display = 'none';
}
async function soumettreChangementMdp() {
    const btn = document.getElementById('mdp-submit');
    const errDiv = document.getElementById('mdp-error');
    btn.disabled = true;
    errDiv.style.display = 'none';
    try {
        const resp = await fetch('/share/ctrl.php?cmd=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_password: document.getElementById('mdp-actuel').value,
                new_password: document.getElementById('mdp-nouveau').value,
                confirm_password: document.getElementById('mdp-confirm').value,
                csrf_token: CSRF_TOKEN
            })
        });
        const data = await resp.json();
        if (data.error) {
            errDiv.textContent = data.error;
            errDiv.style.display = 'block';
        } else {
            fermerModalCompte();
        }
    } catch (_) {
        errDiv.textContent = 'Erreur de connexion';
        errDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}
```

- [ ] **Step 9 : Vérifier que tous les tests passent**

```bash
vendor/bin/phpunit -v
```

Expected: tous PASS

- [ ] **Step 10 : Commit**

```bash
git add functions.php ctrl.php header.php app.js style.css tests/ChangePasswordTest.php
git commit -m "feat: changement de mot de passe pour les utilisateurs connectés"
```

---

### Task 4 : Purge des liens expirés

**Files:**
- Modify: `functions.php` (nouvelle fonction `purge_expired_links`)
- Modify: `admin.php` (action + HTML + JS)
- Create: `tests/PurgeExpiredTest.php`

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/PurgeExpiredTest.php` :

```php
<?php

use PHPUnit\Framework\TestCase;

class PurgeExpiredTest extends TestCase
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
        self::$db->query("DELETE FROM links");
    }

    public function testPurgesExpiredOnly(): void
    {
        // Lien expiré
        self::$db->prepare("INSERT INTO links (token, path, type, name, expires_at) VALUES (?,?,?,?,?)")
            ->execute(['tok-exp', '/tmp/a', 'file', 'a.mkv', '2020-01-01 00:00:00']);
        // Lien actif (expire demain)
        self::$db->prepare("INSERT INTO links (token, path, type, name, expires_at) VALUES (?,?,?,?,?)")
            ->execute(['tok-active', '/tmp/b', 'file', 'b.mkv', date('Y-m-d H:i:s', time() + 86400)]);
        // Lien permanent (pas d'expiration)
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-perm', '/tmp/c', 'file', 'c.mkv']);

        $deleted = purge_expired_links(self::$db);

        $this->assertSame(1, $deleted);
        $remaining = (int)self::$db->query("SELECT COUNT(*) FROM links")->fetchColumn();
        $this->assertSame(2, $remaining);
        $gone = (int)self::$db->query("SELECT COUNT(*) FROM links WHERE token = 'tok-exp'")->fetchColumn();
        $this->assertSame(0, $gone);
    }

    public function testReturnsZeroWhenNothingToDelete(): void
    {
        $deleted = purge_expired_links(self::$db);
        $this->assertSame(0, $deleted);
    }

    public function testDoesNotDeletePermanentLinks(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-perm2', '/tmp/d', 'file', 'd.mkv']);
        $deleted = purge_expired_links(self::$db);
        $this->assertSame(0, $deleted);
        $count = (int)self::$db->query("SELECT COUNT(*) FROM links")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
vendor/bin/phpunit tests/PurgeExpiredTest.php -v
```

Expected: FAIL avec "Call to undefined function purge_expired_links()"

- [ ] **Step 3 : Implémenter `purge_expired_links` dans `functions.php`**

À la fin de `functions.php`, ajouter :

```php
/**
 * Supprime tous les liens expirés de la base.
 * Retourne le nombre de liens supprimés.
 */
function purge_expired_links(PDO $db): int {
    $stmt = $db->prepare("DELETE FROM links WHERE expires_at IS NOT NULL AND expires_at < datetime('now')");
    $stmt->execute();
    return $stmt->rowCount();
}
```

- [ ] **Step 4 : Vérifier que les tests passent**

```bash
vendor/bin/phpunit tests/PurgeExpiredTest.php -v
```

Expected: 3 PASS

- [ ] **Step 5 : Ajouter l'action `purge_expired` dans `admin.php`**

Dans le `switch ($action)` de `admin.php`, ajouter après le `case 'tmdb_scan'` :

```php
            case 'purge_expired':
                $db = get_db();
                require_once __DIR__ . '/functions.php';
                $deleted = purge_expired_links($db);
                echo json_encode(['ok' => true, 'deleted' => $deleted]);
                break;
```

- [ ] **Step 6 : Ajouter la section HTML "Maintenance" dans `admin.php`**

Dans le HTML de `admin.php`, juste avant la `<div class="card">` de la section Utilisateurs (ligne ~594), ajouter :

```html
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
            <div class="card-title">Maintenance</div>
        </div>
        <div style="padding:1rem 1.4rem;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
            <button class="btn btn-ghost" id="purge-btn" onclick="purgeExpired()">Purger les liens expirés</button>
            <span id="purge-result" style="font-size:.82rem;color:var(--text-dim)"></span>
        </div>
    </div>
```

- [ ] **Step 7 : Ajouter la fonction JS `purgeExpired` dans `admin.php`**

Dans le `<script>` de `admin.php`, avant `loadUsers();` (dernière ligne du script), ajouter :

```js
async function purgeExpired() {
    const btn = document.getElementById('purge-btn');
    const result = document.getElementById('purge-result');
    btn.disabled = true;
    btn.textContent = 'Purge…';
    try {
        const res = await api('purge_expired', {});
        if (res.error) { toast(res.error, false); }
        else {
            const msg = res.deleted === 0 ? 'Aucun lien expiré.' : res.deleted + ' lien(s) supprimé(s).';
            result.textContent = msg;
            toast(msg);
        }
    } finally {
        btn.disabled = false;
        btn.textContent = 'Purger les liens expirés';
    }
}
```

- [ ] **Step 8 : Vérifier tous les tests**

```bash
vendor/bin/phpunit -v
```

Expected: tous PASS

- [ ] **Step 9 : Commit**

```bash
git add functions.php admin.php tests/PurgeExpiredTest.php
git commit -m "feat: purge des liens expirés depuis l'admin"
```

---

### Task 5 : Quota disque par utilisateur

**Files:**
- Modify: `admin.php` (action `list_users` + JS `loadUsers`)

- [ ] **Step 1 : Ajouter `disk_used` dans l'action `list_users` de `admin.php`**

Dans le `foreach ($users as &$u)` de l'action `list_users`, après le bloc `$u['rtorrent_status']` / `$u['has_system_user']`, ajouter :

```php
                    // Quota disque
                    $u['disk_used'] = null;
                    if (defined('BASE_PATH')) {
                        $userDir = rtrim(BASE_PATH, '/') . '/' . $u['username'];
                        if (is_dir($userDir)) {
                            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                            $proc = proc_open(['/usr/bin/du', '-sb', $userDir], $descriptors, $pipes);
                            if (is_resource($proc)) {
                                stream_set_timeout($pipes[1], 5);
                                $out = fgets($pipes[1]);
                                fclose($pipes[0]);
                                fclose($pipes[1]);
                                fclose($pipes[2]);
                                proc_close($proc);
                                if ($out !== false && preg_match('/^(\d+)/', $out, $m)) {
                                    $u['disk_used'] = (int)$m[1];
                                }
                            }
                        }
                    }
```

Note : ce bloc se place **après** la fermeture du `if ($seedboxMode) { ... } else { ... }`, mais toujours à l'intérieur du `foreach ($users as &$u)`. Même niveau d'indentation que le `if`.

- [ ] **Step 2 : Ajouter la colonne "Disque" dans `loadUsers()` côté JS**

Dans la fonction `loadUsers()` du `<script>` de `admin.php` :

Remplacer :
```js
    html += '<th>Utilisateur</th><th>Rôle</th>';
    if (sbMode) html += '<th>rtorrent</th>';
    html += '<th>Créé le</th><th>Actions</th>';
```

Par :
```js
    html += '<th>Utilisateur</th><th>Rôle</th>';
    if (sbMode) html += '<th>rtorrent</th>';
    html += '<th>Disque</th><th>Créé le</th><th>Actions</th>';
```

Et dans la boucle `for (const u of res.users)`, ajouter avant la cellule "Créé le" :

```js
        const diskStr = formatBytes(u.disk_used);
        html += '<td style="font-size:.8rem;color:var(--text-dim)">' + diskStr + '</td>';
```

- [ ] **Step 3 : Ajouter la fonction `formatBytes` dans le `<script>` de `admin.php`**

Au début du `<script>`, après la définition de `api()`, ajouter :

```js
function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
}
```

- [ ] **Step 4 : Vérifier que les tests passent toujours**

```bash
vendor/bin/phpunit -v
```

Expected: tous PASS

- [ ] **Step 5 : Commit**

```bash
git add admin.php
git commit -m "feat: quota disque par utilisateur dans le tableau admin"
```

---

## Groupe 2 — Avec migrations DB

---

### Task 6 : Migration v12 + max_downloads sur les liens

**Files:**
- Modify: `db.php` (migration v12, `$targetVersion = 12`)
- Modify: `ctrl.php` (champ `max_downloads` dans l'action `create`)
- Modify: `download.php` (vérification avant de servir)
- Modify: `index.php` (affichage N/max sur les cartes)
- Modify: `app.js` (champ dans la share sheet, passage à `creerLienSheet`)
- Modify: `style.css` (état "épuisé")
- Create: `tests/MaxDownloadsTest.php`

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/MaxDownloadsTest.php` :

```php
<?php

use PHPUnit\Framework\TestCase;

class MaxDownloadsTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM links");
    }

    public function testColumnExists(): void
    {
        $cols = array_column(self::$db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        $this->assertContains('max_downloads', $cols);
    }

    public function testDefaultIsNull(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-unlim', '/tmp/a', 'file', 'a.mkv']);
        $link = self::$db->query("SELECT max_downloads FROM links WHERE token = 'tok-unlim'")->fetch();
        $this->assertNull($link['max_downloads']);
    }

    public function testLinkNotYetExhausted(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name, max_downloads, download_count) VALUES (?,?,?,?,?,?)")
            ->execute(['tok-max', '/tmp/b', 'file', 'b.mkv', 3, 2]);
        $link = self::$db->query("SELECT * FROM links WHERE token = 'tok-max'")->fetch();
        $this->assertFalse((int)$link['download_count'] >= (int)$link['max_downloads']);
    }

    public function testLinkExhausted(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name, max_downloads, download_count) VALUES (?,?,?,?,?,?)")
            ->execute(['tok-done', '/tmp/c', 'file', 'c.mkv', 2, 2]);
        $link = self::$db->query("SELECT * FROM links WHERE token = 'tok-done'")->fetch();
        $this->assertTrue((int)$link['download_count'] >= (int)$link['max_downloads']);
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
vendor/bin/phpunit tests/MaxDownloadsTest.php -v
```

Expected: FAIL — `testColumnExists` échoue car la colonne n'existe pas encore.

- [ ] **Step 3 : Ajouter la migration v12 dans `db.php`**

Dans `db.php`, changer `$targetVersion = 11;` en `$targetVersion = 12;`

Puis ajouter après le bloc `if ($version < 11)` :

```php
    if ($version < 12) {
        // v12 : limite optionnelle de téléchargements par lien
        $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        if (!in_array('max_downloads', $cols, true)) {
            $db->query("ALTER TABLE links ADD COLUMN max_downloads INTEGER DEFAULT NULL");
        }
        $db->query('PRAGMA user_version = 12');
    }
```

- [ ] **Step 4 : Vérifier que les tests passent**

```bash
vendor/bin/phpunit tests/MaxDownloadsTest.php -v
```

Expected: 4 PASS

- [ ] **Step 5 : Ajouter `max_downloads` dans l'action `create` de `ctrl.php`**

Dans l'action `create`, après la ligne `$expiresAt = null;` et son bloc, ajouter :

```php
            // Limite optionnelle de téléchargements
            $maxDownloads = null;
            if (isset($input['max_downloads']) && (int)$input['max_downloads'] > 0) {
                $maxDownloads = (int)$input['max_downloads'];
            }
```

Puis modifier le `INSERT` pour inclure `max_downloads` :

```php
            $stmt = $db->prepare("
                INSERT INTO links (token, path, type, name, password_hash, expires_at, created_by, max_downloads)
                VALUES (:token, :path, :type, :name, :password_hash, :expires_at, :created_by, :max_downloads)
            ");
            $stmt->execute([
                ':token'         => $token,
                ':path'          => $fullPath,
                ':type'          => $type,
                ':name'          => $name,
                ':password_hash' => $passwordHash,
                ':expires_at'    => $expiresAt,
                ':created_by'    => $createdBy,
                ':max_downloads' => $maxDownloads,
            ]);
```

- [ ] **Step 6 : Ajouter la vérification dans `download.php`**

Dans `download.php`, après le bloc de vérification d'expiration (après le `exit;` de l'expiry check, vers ligne ~41), ajouter :

```php
// Vérifier la limite de téléchargements
if ($link['max_downloads'] !== null && (int)$link['download_count'] >= (int)$link['max_downloads']) {
    stream_log('ACCESS 410 | token=' . $token . ' | max_downloads reached (' . $link['download_count'] . '/' . $link['max_downloads'] . ')');
    http_response_code(410);
    afficher_erreur('Lien épuisé', 'Ce lien a atteint sa limite de téléchargements.');
    exit;
}
```

- [ ] **Step 7 : Mettre à jour l'affichage du compteur sur les cartes dans `index.php`**

Dans la fonction `afficher_liens()` de `index.php`, remplacer :

```php
        $dlCount = (int)$link['download_count'];
```

Par :

```php
        $dlCount = (int)$link['download_count'];
        $maxDl = isset($link['max_downloads']) && $link['max_downloads'] !== null ? (int)$link['max_downloads'] : null;
        $dlDisplay = $maxDl !== null ? "{$dlCount}/{$maxDl}" : "{$dlCount}";
        $isExhausted = $maxDl !== null && $dlCount >= $maxDl;
```

Puis remplacer la ligne `$expiredClass = $expired ? ' is-expired' : '';` par :

```php
        $expiredClass = ($expired || $isExhausted) ? ' is-expired' : '';
```

Et remplacer dans la carte :

```php
        echo "<div class=\"link-meta\"><span class=\"link-meta-label\">Téléch.</span><span class=\"link-meta-val\">{$dlCount}</span></div>";
```

Par :

```php
        echo "<div class=\"link-meta\"><span class=\"link-meta-label\">Téléch.</span><span class=\"link-meta-val\">{$dlDisplay}</span></div>";
```

- [ ] **Step 8 : Ajouter le champ max_downloads dans la share sheet (`app.js`)**

Dans la fonction `ouvrirShareSheet`, juste avant `// Bouton créer`, ajouter :

```js
    // Champ max_downloads
    const maxDlLabel = creerElement('div', 'sheet-field-label');
    maxDlLabel.textContent = 'Max téléchargements (optionnel)';
    const maxDlInput = document.createElement('input');
    maxDlInput.type = 'number';
    maxDlInput.min = '1';
    maxDlInput.placeholder = 'Illimité';
    maxDlInput.className = 'sheet-pwd-input';
    maxDlInput.style.width = '50%';
```

Et dans le listener du bouton "Créer" (remplacer l'appel à `creerLienSheet`) :

```js
        creerLienSheet(path, pwdInput.value, expiry, sheet, maxDlInput.value ? parseInt(maxDlInput.value) : null);
```

Et dans le `sheet.append(...)`, ajouter `maxDlLabel, maxDlInput,` avant `createBtn` :

```js
    sheet.append(handle, titleLabel, filename, pwdLabel, pwdWrap, expLabel, pillsWrap, customWrap, maxDlLabel, maxDlInput, createBtn);
```

- [ ] **Step 9 : Mettre à jour `creerLienSheet` dans `app.js`**

Changer la signature de la fonction :

```js
async function creerLienSheet(path, password, expiresStr, sheet, maxDownloads = null) {
```

Et modifier le `body` du fetch dans cette fonction :

```js
            body: JSON.stringify({ path, password: password || '', expires, max_downloads: maxDownloads, csrf_token: CSRF_TOKEN }),
```

- [ ] **Step 10 : Vérifier tous les tests**

```bash
vendor/bin/phpunit -v
```

Expected: tous PASS

- [ ] **Step 11 : Commit**

```bash
git add db.php ctrl.php download.php index.php app.js style.css tests/MaxDownloadsTest.php
git commit -m "feat: limite de téléchargements (max_downloads) sur les liens de partage"
```

---

### Task 7 : Migration v13 + logs de téléchargement

**Files:**
- Modify: `db.php` (migration v13, `$targetVersion = 13`)
- Modify: `download.php` (insert log + cleanup probabiliste)
- Modify: `admin.php` (action `recent_activity` + section HTML + JS)
- Create: `tests/DownloadLogsTest.php`

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/DownloadLogsTest.php` :

```php
<?php

use PHPUnit\Framework\TestCase;

class DownloadLogsTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM download_logs");
        self::$db->query("DELETE FROM links");
    }

    public function testTableExists(): void
    {
        $tables = self::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='download_logs'"
        )->fetchAll();
        $this->assertCount(1, $tables);
    }

    public function testInsertLog(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-log1', '/tmp/a', 'file', 'a.mkv']);
        $linkId = (int)self::$db->lastInsertId();

        self::$db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
            ->execute([$linkId, '1.2.3.4']);

        $count = (int)self::$db->query("SELECT COUNT(*) FROM download_logs")->fetchColumn();
        $this->assertSame(1, $count);

        $log = self::$db->query("SELECT * FROM download_logs")->fetch();
        $this->assertSame($linkId, (int)$log['link_id']);
        $this->assertSame('1.2.3.4', $log['ip']);
        $this->assertNotEmpty($log['downloaded_at']);
    }

    public function testCleanupRemovesOldLogs(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-log2', '/tmp/b', 'file', 'b.mkv']);
        $linkId = (int)self::$db->lastInsertId();

        // Log vieux de 31 jours
        $old = date('Y-m-d H:i:s', time() - 31 * 86400);
        self::$db->prepare("INSERT INTO download_logs (link_id, ip, downloaded_at) VALUES (?, ?, ?)")
            ->execute([$linkId, '1.2.3.4', $old]);
        // Log récent
        self::$db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
            ->execute([$linkId, '5.6.7.8']);

        self::$db->exec("DELETE FROM download_logs WHERE downloaded_at < datetime('now', '-30 days')");

        $count = (int)self::$db->query("SELECT COUNT(*) FROM download_logs")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = self::$db->query("SELECT ip FROM download_logs")->fetch();
        $this->assertSame('5.6.7.8', $remaining['ip']);
    }

    public function testRecentActivityQuery(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name, created_by) VALUES (?,?,?,?,?)")
            ->execute(['tok-log3', '/tmp/c', 'file', 'c.mkv', 'alice']);
        $linkId = (int)self::$db->lastInsertId();

        self::$db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
            ->execute([$linkId, '9.9.9.9']);

        $rows = self::$db->query("
            SELECT dl.ip, l.name, l.token, l.created_by
            FROM download_logs dl
            JOIN links l ON dl.link_id = l.id
            ORDER BY dl.downloaded_at DESC
            LIMIT 50
        ")->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertSame('9.9.9.9', $rows[0]['ip']);
        $this->assertSame('alice', $rows[0]['created_by']);
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
vendor/bin/phpunit tests/DownloadLogsTest.php -v
```

Expected: FAIL — `testTableExists` échoue car la table n'existe pas encore.

- [ ] **Step 3 : Ajouter la migration v13 dans `db.php`**

Changer `$targetVersion = 12;` en `$targetVersion = 13;`

Ajouter après le bloc `if ($version < 12)` :

```php
    if ($version < 13) {
        // v13 : logs de téléchargement (30 jours de rétention)
        $db->query("
            CREATE TABLE IF NOT EXISTS download_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                link_id INTEGER NOT NULL,
                ip TEXT NOT NULL,
                downloaded_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->query("CREATE INDEX IF NOT EXISTS idx_dl_logs_link ON download_logs(link_id)");
        $db->query("CREATE INDEX IF NOT EXISTS idx_dl_logs_ts ON download_logs(downloaded_at DESC)");
        $db->query('PRAGMA user_version = 13');
    }
```

- [ ] **Step 4 : Vérifier que les tests passent**

```bash
vendor/bin/phpunit tests/DownloadLogsTest.php -v
```

Expected: 4 PASS

- [ ] **Step 5 : Insérer le log dans `download.php` aux deux points de `download_count`**

Dans `download.php`, les deux endroits où `download_count` est incrémenté (vers lignes 162 et 180).

**Point 1 — téléchargement fichier (vers ligne 162)** — remplacer :

```php
    if (!$subPath) {
        $stmt = $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id");
        $stmt->execute([':id' => $link['id']]);
    }
```

Par :

```php
    if (!$subPath) {
        $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id")
           ->execute([':id' => $link['id']]);
        $db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
           ->execute([(int)$link['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
        if (random_int(1, 100) === 1) {
            $db->exec("DELETE FROM download_logs WHERE downloaded_at < datetime('now', '-30 days')");
        }
    }
```

**Point 2 — accès dossier (vers ligne 180)** — appliquer la même modification :

```php
    if (!$subPath) {
        $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id")
           ->execute([':id' => $link['id']]);
        $db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
           ->execute([(int)$link['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
        if (random_int(1, 100) === 1) {
            $db->exec("DELETE FROM download_logs WHERE downloaded_at < datetime('now', '-30 days')");
        }
    }
```

- [ ] **Step 6 : Ajouter l'action `recent_activity` dans `admin.php`**

Dans le `switch ($action)` de `admin.php`, ajouter après `case 'purge_expired'` :

```php
            case 'recent_activity':
                $db = get_db();
                $userFilter = trim($input['user'] ?? '');
                if ($userFilter !== '') {
                    $stmt = $db->prepare("
                        SELECT dl.id, l.name, l.token, l.created_by, dl.ip, dl.downloaded_at
                        FROM download_logs dl
                        JOIN links l ON dl.link_id = l.id
                        WHERE l.created_by = ?
                        ORDER BY dl.downloaded_at DESC
                        LIMIT 50
                    ");
                    $stmt->execute([$userFilter]);
                } else {
                    $stmt = $db->query("
                        SELECT dl.id, l.name, l.token, l.created_by, dl.ip, dl.downloaded_at
                        FROM download_logs dl
                        JOIN links l ON dl.link_id = l.id
                        ORDER BY dl.downloaded_at DESC
                        LIMIT 50
                    ");
                }
                echo json_encode(['logs' => $stmt->fetchAll()]);
                break;
```

- [ ] **Step 7 : Ajouter la section HTML "Activité récente" dans `admin.php`**

Dans le HTML de `admin.php`, juste après la section Maintenance (et avant la section Utilisateurs), ajouter :

```html
    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
            <div class="card-title">Activité récente</div>
            <select id="activity-user-filter" onchange="loadRecentActivity()" style="background:var(--bg-input);border:1px solid var(--border-strong);border-radius:6px;color:var(--text);padding:.3rem .6rem;font-size:.8rem;font-family:inherit">
                <option value="">Tous les utilisateurs</option>
            </select>
        </div>
        <div id="activity-wrap" style="padding:.8rem 1.4rem">
            <div class="empty-msg">Chargement...</div>
        </div>
    </div>
```

- [ ] **Step 8 : Ajouter les fonctions JS `loadRecentActivity` dans `admin.php`**

Dans le `<script>` de `admin.php`, avant `loadUsers();` :

```js
async function loadRecentActivity() {
    const wrap = document.getElementById('activity-wrap');
    const userFilter = document.getElementById('activity-user-filter')?.value ?? '';
    try {
        const res = await api('recent_activity', { user: userFilter });
        if (!res.logs || res.logs.length === 0) {
            wrap.innerHTML = '<div class="empty-msg" style="padding:.8rem 0">Aucune activité enregistrée.</div>';
            return;
        }
        let html = '<table class="user-table"><thead><tr>';
        html += '<th>Fichier</th><th>Token</th><th>User</th><th>IP</th><th>Date</th>';
        html += '</tr></thead><tbody>';
        for (const log of res.logs) {
            const d = log.downloaded_at ? new Date(log.downloaded_at + 'Z').toLocaleString('fr-FR') : '-';
            html += '<tr>';
            html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + log.name + '">' + log.name + '</td>';
            html += '<td><a href="/dl/' + log.token + '" target="_blank" style="color:var(--blue);font-size:.8rem">' + log.token + '</a></td>';
            html += '<td style="font-size:.8rem;color:var(--text-dim)">' + (log.created_by || '—') + '</td>';
            html += '<td style="font-family:monospace;font-size:.8rem">' + log.ip + '</td>';
            html += '<td style="font-size:.78rem;color:var(--text-dim)">' + d + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;
    } catch (_) {
        wrap.innerHTML = '<div class="empty-msg">Erreur de chargement.</div>';
    }
}

async function populateActivityUserFilter() {
    const sel = document.getElementById('activity-user-filter');
    if (!sel) return;
    const res = await api('list_users');
    if (!res.users) return;
    for (const u of res.users) {
        const opt = document.createElement('option');
        opt.value = u.username;
        opt.textContent = u.username;
        sel.appendChild(opt);
    }
}
```

Et remplacer l'appel d'initialisation en bas du script :

```js
loadUsers();
loadTmdbStatus();
loadRecentActivity();
populateActivityUserFilter();
```

(Note : `loadTmdbStatus()` est déjà présent — s'assurer de ne pas le dupliquer.)

- [ ] **Step 9 : Vérifier tous les tests**

```bash
vendor/bin/phpunit -v
```

Expected: ~164+ tests, tous PASS (les tests existants + les 4 nouveaux fichiers)

- [ ] **Step 10 : Vérifier PHPStan**

```bash
vendor/bin/phpstan analyse
```

Expected: 0 erreurs

- [ ] **Step 11 : Commit**

```bash
git add db.php download.php admin.php tests/DownloadLogsTest.php
git commit -m "feat: logs de téléchargement + section activité récente dans l'admin"
```

---

## Vérification finale

- [ ] Recharger PHP-FPM pour invalider l'OPcache :

```bash
sudo systemctl reload php8.3-fpm
```

- [ ] Lancer la suite complète une dernière fois :

```bash
vendor/bin/phpunit -v && vendor/bin/phpstan analyse
```

Expected: tous PASS, 0 erreurs PHPStan.
