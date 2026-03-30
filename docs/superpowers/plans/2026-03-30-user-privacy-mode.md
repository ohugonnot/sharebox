# User Privacy Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-user "private mode" flag that isolates a user's file browser to their own folder and hides their links from others (and hides others' links from them).

**Architecture:** Two DB migrations add `users.private` and `links.created_by`. The private flag is stored in session at login. All enforcement happens server-side: `index.php` filters the links query, `ctrl.php` restricts the browse root and link ownership checks. Admin panel gets a checkbox in create/edit forms.

**Tech Stack:** PHP 8.3, SQLite (WAL), PHPUnit, inline JS in admin.php

**Spec:** `docs/superpowers/specs/2026-03-30-user-privacy-mode-design.md`

---

## Files Modified

| File | Change |
|------|--------|
| `db.php` | Migrations v9 + v10 — `users.private`, `links.created_by` |
| `login.php` | Store `$_SESSION['sharebox_private']` at login |
| `index.php` | Filter links query by privacy rules |
| `ctrl.php` | Restrict `browse` root; set `created_by` on create; own-links check on delete |
| `admin.php` | `create_user`/`update_user`/`list_users` handle `private`; HTML forms + JS checkbox |
| `tests/DatabaseTest.php` | Tests for new columns |
| `tests/PrivacyTest.php` | Tests for filtering and path restriction logic |

---

## Task 1: DB Migrations — users.private + links.created_by

**Files:**
- Modify: `db.php:101-189` (migration block)
- Test: `tests/DatabaseTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/DatabaseTest.php`:

```php
public function testUsersTableHasPrivateColumn(): void
{
    $db = get_db();
    $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
    $this->assertContains('private', $cols);
}

public function testUsersPrivateDefaultsToZero(): void
{
    $db = get_db();
    $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'user')")
       ->execute(['testprivuser', password_hash('pass', PASSWORD_BCRYPT)]);
    $row = $db->query("SELECT private FROM users WHERE username = 'testprivuser'")->fetch();
    $this->assertSame(0, (int)$row['private']);
    $db->prepare("DELETE FROM users WHERE username = ?")->execute(['testprivuser']);
}

public function testLinksTableHasCreatedByColumn(): void
{
    $db = get_db();
    $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
    $this->assertContains('created_by', $cols);
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
vendor/bin/phpunit tests/DatabaseTest.php --filter "testUsersTableHasPrivateColumn|testUsersPrivateDefaultsToZero|testLinksTableHasCreatedByColumn" --no-coverage
```

Expected: 3 FAILs (column not found)

- [ ] **Step 3: Add migrations to db.php**

In `db.php`, change `$targetVersion = 8;` to `$targetVersion = 10;` and add after the `if ($version < 8)` block:

```php
if ($version < 9) {
    // v9 : mode privé par utilisateur
    $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
    if (!in_array('private', $cols, true)) {
        $db->query("ALTER TABLE users ADD COLUMN private INTEGER NOT NULL DEFAULT 0");
    }
    $db->query('PRAGMA user_version = 9');
}

if ($version < 10) {
    // v10 : attribution des liens à leur créateur
    $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
    if (!in_array('created_by', $cols, true)) {
        $db->query("ALTER TABLE links ADD COLUMN created_by TEXT REFERENCES users(username)");
    }
    $db->query('PRAGMA user_version = 10');
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
vendor/bin/phpunit tests/DatabaseTest.php --filter "testUsersTableHasPrivateColumn|testUsersPrivateDefaultsToZero|testLinksTableHasCreatedByColumn" --no-coverage
```

Expected: 3 PASSes

- [ ] **Step 5: Run full test suite to check for regressions**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 6: Commit**

```bash
git add db.php tests/DatabaseTest.php
git commit -m "feat: DB migrations v9/v10 — users.private + links.created_by"
```

---

## Task 2: Store private flag in session at login

**Files:**
- Modify: `login.php:35-36`

- [ ] **Step 1: Update login.php session setup**

Find the block at line 35-36 in `login.php`:
```php
$_SESSION['sharebox_user'] = $user['username'];
$_SESSION['sharebox_role'] = $user['role'];
```

Change to:
```php
$_SESSION['sharebox_user'] = $user['username'];
$_SESSION['sharebox_role'] = $user['role'];
$_SESSION['sharebox_private'] = (int)($user['private'] ?? 0);
```

Note: if admin changes a user's private flag while they are logged in, it takes effect on next login. This is acceptable.

- [ ] **Step 2: Confirm no test regression**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 3: Commit**

```bash
git add login.php
git commit -m "feat: store sharebox_private flag in session at login"
```

---

## Task 3: index.php — filter links by privacy rules

**Files:**
- Modify: `index.php:32`
- Test: `tests/PrivacyTest.php` (new file)

- [ ] **Step 1: Write failing test**

Create `tests/PrivacyTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sharebox_priv_');
        if (!defined('DB_PATH'))               define('DB_PATH', $tmp);
        if (!defined('BASE_PATH'))             define('BASE_PATH', '/tmp/privtest/');
        if (!defined('XACCEL_PREFIX'))         define('XACCEL_PREFIX', '/internal');
        if (!defined('DL_BASE_URL'))           define('DL_BASE_URL', '/dl/');
        if (!defined('STREAM_MAX_CONCURRENT')) define('STREAM_MAX_CONCURRENT', 4);
        if (!defined('STREAM_REMUX_ENABLED'))  define('STREAM_REMUX_ENABLED', false);
        if (!defined('STREAM_LOG'))            define('STREAM_LOG', false);
        if (!defined('BANDWIDTH_QUOTA_TB'))    define('BANDWIDTH_QUOTA_TB', 100);
        require_once __DIR__ . '/../db.php';
        self::$db = get_db();
    }

    public static function tearDownAfterClass(): void
    {
        $path = DB_PATH;
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM links");
        self::$db->query("DELETE FROM users WHERE username IN ('alice','bob','carol')");

        // alice: non-private, bob: private, carol: admin
        self::$db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?,?,?,?)")
            ->execute(['alice', 'x', 'user', 0]);
        self::$db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?,?,?,?)")
            ->execute(['bob', 'x', 'user', 1]);
        self::$db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?,?,?,?)")
            ->execute(['carol', 'x', 'admin', 0]);

        // alice creates link A, bob creates link B, legacy link (no owner)
        self::$db->prepare("INSERT INTO links (token, path, type, name, created_by) VALUES (?,?,?,?,?)")
            ->execute(['tok-alice', '/tmp/a', 'file', 'a.mkv', 'alice']);
        self::$db->prepare("INSERT INTO links (token, path, type, name, created_by) VALUES (?,?,?,?,?)")
            ->execute(['tok-bob', '/tmp/b', 'file', 'b.mkv', 'bob']);
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-legacy', '/tmp/c', 'file', 'c.mkv']);
    }

    /** Admin sees all 3 links */
    public function testAdminSeesAllLinks(): void
    {
        $links = self::$db->query("SELECT token FROM links ORDER BY token")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(3, $links);
    }

    /** Non-private user sees public links (alice's + legacy, NOT bob's) */
    public function testNonPrivateUserSeesPublicLinks(): void
    {
        $stmt = self::$db->prepare("
            SELECT l.token FROM links l
            LEFT JOIN users u ON l.created_by = u.username
            WHERE l.created_by IS NULL OR u.private = 0
            ORDER BY l.token
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('tok-alice', $tokens);
        $this->assertContains('tok-legacy', $tokens);
        $this->assertNotContains('tok-bob', $tokens);
    }

    /** Private user sees only their own links */
    public function testPrivateUserSeesOnlyOwnLinks(): void
    {
        $stmt = self::$db->prepare("SELECT token FROM links WHERE created_by = ? ORDER BY token");
        $stmt->execute(['bob']);
        $tokens = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['tok-bob'], $tokens);
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
vendor/bin/phpunit tests/PrivacyTest.php --no-coverage
```

Expected: FAILs on `testNonPrivateUserSeesPublicLinks` and `testPrivateUserSeesOnlyOwnLinks` (columns don't exist yet in this fresh DB — actually they will exist after Task 1, so they should pass the schema but the INSERT with `private` column should work). If Task 1 is done, these tests should already pass. Run to confirm.

- [ ] **Step 3: Update index.php links query**

In `index.php`, find line 32:
```php
$links = $db->query("SELECT * FROM links ORDER BY created_at DESC")->fetchAll();
```

Replace with:

```php
$currentUser = $_SESSION['sharebox_user'] ?? '';
$currentRole = $_SESSION['sharebox_role'] ?? 'user';
$currentPrivate = (int)($_SESSION['sharebox_private'] ?? 0);

if ($currentRole === 'admin') {
    $links = $db->query("SELECT * FROM links ORDER BY created_at DESC")->fetchAll();
} elseif ($currentPrivate === 1) {
    $stmt = $db->prepare("SELECT * FROM links WHERE created_by = ? ORDER BY created_at DESC");
    $stmt->execute([$currentUser]);
    $links = $stmt->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT l.* FROM links l
        LEFT JOIN users u ON l.created_by = u.username
        WHERE l.created_by IS NULL OR u.private = 0
        ORDER BY l.created_at DESC
    ");
    $stmt->execute();
    $links = $stmt->fetchAll();
}
```

- [ ] **Step 4: Run full test suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 5: Commit**

```bash
git add index.php tests/PrivacyTest.php
git commit -m "feat: filter links by privacy mode in index.php"
```

---

## Task 4: ctrl.php — restrict browse root for private users

**Files:**
- Modify: `ctrl.php:43-50` (browse case)
- Test: `tests/PrivacyTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/PrivacyTest.php`:

```php
/** Private user cannot traverse above their subfolder */
public function testPrivateUserPathIsRestricted(): void
{
    // Simulate: BASE_PATH = /tmp/privtest/, private user = bob
    // bob's root = /tmp/privtest/bob/
    // Attempting to access /tmp/privtest/alice/ should fail the within-check
    $base = BASE_PATH; // /tmp/privtest/
    $userRoot = $base . 'bob/';
    $attemptedPath = $base . 'alice/testfile.txt';

    // is_path_within is defined in functions.php
    require_once __DIR__ . '/../functions.php';

    $this->assertFalse(is_path_within($attemptedPath, $userRoot));
    $this->assertTrue(is_path_within($base . 'bob/subdir', $userRoot));
}
```

- [ ] **Step 2: Run test to confirm behaviour**

```bash
vendor/bin/phpunit tests/PrivacyTest.php::testPrivateUserPathIsRestricted --no-coverage
```

Expected: PASS (is_path_within already works — this validates the logic we're about to use)

- [ ] **Step 3: Update ctrl.php browse case**

In `ctrl.php`, find the `case 'browse':` block. After the opening of the case (around line 43), insert before the `$fullPath = realpath(...)` line:

```php
case 'browse':
    $relPath = $_GET['path'] ?? '';

    // Restrict private users to their own subfolder
    $browseBase = BASE_PATH;
    if (($_SESSION['sharebox_role'] ?? '') !== 'admin'
        && (int)($_SESSION['sharebox_private'] ?? 0) === 1) {
        $username = $_SESSION['sharebox_user'] ?? '';
        $browseBase = BASE_PATH . $username . '/';
    }

    $fullPath = realpath($browseBase . $relPath);

    if (!is_path_within($fullPath, $browseBase)) {
        http_response_code(403);
        echo json_encode(['error' => 'Chemin interdit']);
        exit;
    }
    // ... rest of browse case unchanged
```

Note: the existing `is_path_within($fullPath, BASE_PATH)` check must also be updated to use `$browseBase` instead of `BASE_PATH`. Find both occurrences in the browse case and replace `BASE_PATH` with `$browseBase`.

- [ ] **Step 4: Run full test suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 5: Commit**

```bash
git add ctrl.php
git commit -m "feat: restrict browse root to user subfolder for private users"
```

---

## Task 5: ctrl.php — set created_by on link creation + restrict delete

**Files:**
- Modify: `ctrl.php:88-145` (create case), `ctrl.php:151-171` (delete case)

- [ ] **Step 1: Update create case — add created_by to INSERT**

In `ctrl.php`, find the INSERT in the `case 'create':` block (around line 132):

```php
$stmt = $db->prepare("
    INSERT INTO links (token, path, type, name, password_hash, expires_at)
    VALUES (:token, :path, :type, :name, :password_hash, :expires_at)
");
$stmt->execute([
    ':token' => $token,
    ':path' => $fullPath,
    ':type' => $type,
    ':name' => $name,
    ':password_hash' => $passwordHash,
    ':expires_at' => $expiresAt,
]);
```

Replace with:

```php
$createdBy = $_SESSION['sharebox_user'] ?? null;
$stmt = $db->prepare("
    INSERT INTO links (token, path, type, name, password_hash, expires_at, created_by)
    VALUES (:token, :path, :type, :name, :password_hash, :expires_at, :created_by)
");
$stmt->execute([
    ':token'        => $token,
    ':path'         => $fullPath,
    ':type'         => $type,
    ':name'         => $name,
    ':password_hash'=> $passwordHash,
    ':expires_at'   => $expiresAt,
    ':created_by'   => $createdBy,
]);
```

- [ ] **Step 2: Update delete case — restrict to own links for non-admin**

In `ctrl.php`, find the `case 'delete':` DELETE statement (around line 167):

```php
$db = get_db();
$stmt = $db->prepare("DELETE FROM links WHERE id = :id");
$stmt->execute([':id' => $id]);
```

Replace with:

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
```

- [ ] **Step 3: Run full test suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 4: Commit**

```bash
git add ctrl.php
git commit -m "feat: set created_by on link creation, restrict delete to own links"
```

---

## Task 6: admin.php backend — private field in create/update/list

**Files:**
- Modify: `admin.php:67-110` (create_user), `admin.php:112-150` (update_user), `admin.php:48-65` (list_users)

- [ ] **Step 1: Update list_users**

Find the `case 'list_users':` handler. The SELECT query for users likely doesn't include `private`. Ensure it returns all columns (or explicitly add `private`). If it's `SELECT id, username, role, created_at`, change to:

```php
$stmt = $db->query("SELECT id, username, role, private, created_at FROM users ORDER BY created_at ASC");
```

- [ ] **Step 2: Update create_user**

In `case 'create_user':`, after the `$role` line (line 70):
```php
$role = in_array($input['role'] ?? '', ['admin', 'user']) ? $input['role'] : 'user';
```

Add:
```php
$private = isset($input['private']) && $input['private'] ? 1 : 0;
```

Change the INSERT (around line 93):
```php
$stmt = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
$stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role]);
```

To:
```php
$stmt = $db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?, ?, ?, ?)");
$stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $private]);
```

- [ ] **Step 3: Update update_user**

In `case 'update_user':`, after the `$newRole` line (around line 126):
```php
$newRole = in_array($input['role'] ?? '', ['admin', 'user']) ? $input['role'] : $user['role'];
```

Add:
```php
$newPrivate = array_key_exists('private', $input) ? ($input['private'] ? 1 : 0) : (int)$user['private'];
```

Change the role update (around line 129):
```php
$db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);
```

To:
```php
$db->prepare("UPDATE users SET role = ?, private = ? WHERE id = ?")->execute([$newRole, $newPrivate, $userId]);
```

- [ ] **Step 4: Run full test suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 5: Commit**

```bash
git add admin.php
git commit -m "feat: admin.php — private field in create/update/list_users"
```

---

## Task 7: admin.php frontend — checkbox "Mode privé" in create/edit modals

**Instruction:** Use the `/frontend-design` skill for this task to match the existing UI style.

**Context for frontend-design:** The admin.php UI uses dark theme with CSS variables (`--bg-card`, `--accent: #f0a030`, `--text`, `--border`). Modals have class `.modal`, fields have `.modal-field` with `<label>` + input. There's already a role `<select>` in both create and edit modals. Add a checkbox field "Mode privé" below the role select in both modals.

- [ ] **Step 1: Add checkbox to create modal HTML** (`admin.php` around line 711, after the role field)

```html
<div class="modal-field">
    <label class="checkbox-label">
        <input type="checkbox" id="create-private">
        <span>Mode privé</span>
        <span class="hint">L'utilisateur ne voit que son propre contenu</span>
    </label>
</div>
```

- [ ] **Step 2: Add checkbox to edit modal HTML** (`admin.php` around line 733, after the role field)

```html
<div class="modal-field">
    <label class="checkbox-label">
        <input type="checkbox" id="edit-private">
        <span>Mode privé</span>
        <span class="hint">L'utilisateur ne voit que son propre contenu</span>
    </label>
</div>
```

- [ ] **Step 3: Add CSS for checkbox-label** (in the `<style>` block of admin.php)

```css
.checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    cursor: pointer;
    font-size: .85rem;
    color: var(--text);
}
.checkbox-label input[type="checkbox"] {
    margin-top: .15rem;
    accent-color: var(--accent);
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    cursor: pointer;
}
.checkbox-label .hint {
    display: block;
    font-size: .72rem;
    color: var(--text-dim);
    margin-top: .15rem;
}
```

- [ ] **Step 4: Update createUser() JS function** to send `private` field

Find the `createUser()` function in admin.php's `<script>` block. Add `private: document.getElementById('create-private').checked` to the payload, and reset it on modal close:

```js
async function createUser() {
    const username = document.getElementById('create-username').value.trim();
    const password = document.getElementById('create-password').value;
    const role = document.getElementById('create-role').value;
    const isPrivate = document.getElementById('create-private').checked;
    // ... existing validation ...
    const res = await apiFetch('create_user', { username, password, role, private: isPrivate });
    // ... existing handling ...
}
```

Also reset the checkbox when `closeModals()` is called (or when the create modal opens).

- [ ] **Step 5: Update openEditModal() JS function** to populate `edit-private` checkbox

Find where the edit modal is populated (the function that sets `edit-id`, `edit-username`, `edit-role`). Add:

```js
document.getElementById('edit-private').checked = !!user.private;
```

- [ ] **Step 6: Update updateUser() JS function** to send `private` field

```js
async function updateUser() {
    const id = document.getElementById('edit-id').value;
    const password = document.getElementById('edit-password').value;
    const role = document.getElementById('edit-role').value;
    const isPrivate = document.getElementById('edit-private').checked;
    const res = await apiFetch('update_user', { id: parseInt(id), password, role, private: isPrivate });
    // ... existing handling ...
}
```

- [ ] **Step 7: Run full test suite**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: all green

- [ ] **Step 8: Commit**

```bash
git add admin.php
git commit -m "feat: admin panel — Mode privé checkbox in user create/edit modals"
```

---

## Verification End-to-End

- [ ] `systemctl reload php8.3-fpm` after PHP changes
- [ ] Login as admin → create user `alice` (non-privé) + user `bob` (privé)
- [ ] Login as `alice` → create a share link → visible dans la liste
- [ ] Login as `bob` → create a share link → visible pour `bob` seulement
- [ ] Login as `alice` → link de `bob` absent de la liste
- [ ] Login as `bob` → link de `alice` absent, file browser limité à `/home/storage/users/bob/`
- [ ] Login as `alice` → file browser accès complet à `/home/storage/users/`
- [ ] Login as admin → tous les liens visibles
- [ ] Tenter de supprimer le lien d'`alice` en étant `bob` → 403
- [ ] `vendor/bin/phpunit --no-coverage` → tout vert
- [ ] `vendor/bin/phpstan analyse` → level 5 sans erreur
