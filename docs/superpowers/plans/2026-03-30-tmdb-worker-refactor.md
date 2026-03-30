# TMDB Worker Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Supprimer le comportement bugué du worker TMDB (hard cap à 3 tentatives, incrémentation inutile sur codes saison, code mort) et renommer `ai_attempts` → `match_attempts`.

**Architecture:** Migration DB v11 renomme la colonne. Le worker passe à 1 seule tentative (query `match_attempts = 0`), ne touche plus les codes saison nus, laisse la propagation parent→enfant faire son travail. Le skill est mis à jour pour ne plus filtrer sur `match_attempts`.

**Tech Stack:** PHP 8.3, SQLite (WAL), PHPUnit

---

## Fichiers modifiés

| Fichier | Action | Changement |
|---------|--------|------------|
| `db.php` | Modify | Migration v11 : rename `ai_attempts` → `match_attempts` |
| `tools/tmdb-worker.php` | Modify | Query `= 0`, bare season skip, remove triplé increment |
| `handlers/tmdb.php` | Modify | Rename toutes les occurrences `ai_attempts` → `match_attempts` |
| `tests/DatabaseTest.php` | Modify | Ajouter test colonne `match_attempts` |
| `.claude/commands/tmdb-scan.md` | Modify | Retirer référence `ai_attempts` + clarifier Phase 1 query |
| `tools/ai-titles.php` | Delete | Code mort, jamais appelé |

---

### Task 1 : Migration DB — renommer `ai_attempts` → `match_attempts`

**Files:**
- Modify: `db.php`
- Modify: `tests/DatabaseTest.php`

- [ ] **Step 1 : Écrire le test qui échoue**

Dans `tests/DatabaseTest.php`, après le test `testFolderPostersHasTmdbYearAndTypeColumns` (ligne ~208) :

```php
// ── 5e. folder_posters has match_attempts column (renamed from ai_attempts) ──

public function testFolderPostersHasMatchAttemptsColumn(): void
{
    $db = get_db();
    $cols = $this->getColumnNames($db, 'folder_posters');
    $this->assertContains('match_attempts', $cols);
    $this->assertNotContains('ai_attempts', $cols);
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
cd /var/www/sharebox && vendor/bin/phpunit tests/DatabaseTest.php --filter testFolderPostersHasMatchAttemptsColumn
```

Attendu : FAIL — `match_attempts` absent, `ai_attempts` présent.

- [ ] **Step 3 : Implémenter la migration v11 dans `db.php`**

Trouver la ligne `$targetVersion = 10;` et changer en `11`.

Ajouter après le bloc `if ($version < 10) { ... }` :

```php
if ($version < 11) {
    // v11 : rename ai_attempts → match_attempts (nom plus précis, pas lié à l'IA)
    $cols = array_column($db->query('PRAGMA table_info(folder_posters)')->fetchAll(), 'name');
    if (in_array('ai_attempts', $cols, true) && !in_array('match_attempts', $cols, true)) {
        $db->query('ALTER TABLE folder_posters RENAME COLUMN ai_attempts TO match_attempts');
    }
    $db->query('PRAGMA user_version = 11');
}
```

- [ ] **Step 4 : Vérifier que le test passe**

```bash
cd /var/www/sharebox && vendor/bin/phpunit tests/DatabaseTest.php --filter testFolderPostersHasMatchAttemptsColumn
```

Attendu : PASS.

- [ ] **Step 5 : Lancer la suite complète pour vérifier pas de régression**

```bash
cd /var/www/sharebox && vendor/bin/phpunit
```

Attendu : tous les tests passent (la migration s'exécute, les autres tests continuent de fonctionner).

- [ ] **Step 6 : Commit**

```bash
cd /var/www/sharebox && git add db.php tests/DatabaseTest.php
git commit -m "feat: DB migration v11 — rename ai_attempts to match_attempts"
```

---

### Task 2 : Mettre à jour `handlers/tmdb.php`

**Files:**
- Modify: `handlers/tmdb.php`

- [ ] **Step 1 : Remplacer toutes les occurrences dans handlers/tmdb.php**

Il y a 4 occurrences à changer :

**Ligne ~232** — condition pending count :
```php
// Avant :
$stmtNull = $db->prepare("SELECT COUNT(*) FROM folder_posters WHERE path LIKE :prefix AND poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)");
// Après :
$stmtNull = $db->prepare("SELECT COUNT(*) FROM folder_posters WHERE path LIKE :prefix AND poster_url IS NULL AND (match_attempts IS NULL OR match_attempts = 0)");
```

**Ligne ~319** — INSERT on conflict (reset lors du scan) :
```php
// Avant :
$db->prepare("INSERT INTO folder_posters (path) VALUES (:p) ON CONFLICT(path) DO UPDATE SET poster_url = NULL, tmdb_id = NULL, title = NULL, overview = NULL, verified = 0, ai_attempts = 0, updated_at = datetime('now')")
// Après :
$db->prepare("INSERT INTO folder_posters (path) VALUES (:p) ON CONFLICT(path) DO UPDATE SET poster_url = NULL, tmdb_id = NULL, title = NULL, overview = NULL, verified = 0, match_attempts = 0, updated_at = datetime('now')")
```

**Ligne ~377** — log message recheck :
```php
// Avant :
poster_log('AI recheck | ' . $name . ' → reset to pending (verified=-1, poster=NULL, ai_attempts=0)');
// Après :
poster_log('AI recheck | ' . $name . ' → reset to pending (verified=-1, poster=NULL, match_attempts=0)');
```

**Lignes ~378-381** — INSERT recheck :
```php
// Avant :
$db->prepare("INSERT INTO folder_posters (path, poster_url, verified, ai_attempts) VALUES (:p, NULL, -1, 0)
              ON CONFLICT(path) DO UPDATE SET poster_url = NULL, verified = -1,
              ai_attempts = CASE WHEN poster_url = '__none__' THEN ai_attempts ELSE 0 END")
// Après :
$db->prepare("INSERT INTO folder_posters (path, poster_url, verified, match_attempts) VALUES (:p, NULL, -1, 0)
              ON CONFLICT(path) DO UPDATE SET poster_url = NULL, verified = -1,
              match_attempts = CASE WHEN poster_url = '__none__' THEN match_attempts ELSE 0 END")
```

- [ ] **Step 2 : Vérifier qu'il ne reste aucune occurrence de `ai_attempts` dans handlers/tmdb.php**

```bash
grep -n "ai_attempts" /var/www/sharebox/handlers/tmdb.php
```

Attendu : aucun résultat.

- [ ] **Step 3 : Lancer les tests**

```bash
cd /var/www/sharebox && vendor/bin/phpunit
```

Attendu : tous les tests passent.

- [ ] **Step 4 : Commit**

```bash
cd /var/www/sharebox && git add handlers/tmdb.php
git commit -m "refactor: rename ai_attempts → match_attempts in handlers/tmdb.php"
```

---

### Task 3 : Mettre à jour `tools/tmdb-worker.php`

**Files:**
- Modify: `tools/tmdb-worker.php`

Trois changements :
1. Query pending : `ai_attempts < 3` → `match_attempts = 0 OR match_attempts IS NULL`
2. No-media skip : `ai_attempts = 3` → `match_attempts = 1`
3. Bare season codes : ne pas incrémenter du tout (laisser la propagation gérer)
4. Failed TMDB : `ai_attempts += 1` → `match_attempts = 1` (une seule tentative)
5. No-title non-season : `ai_attempts += 1` → `match_attempts = 1`

- [ ] **Step 1 : Changer la query pending (ligne 61)**

```php
// Avant :
$rows = $db->query("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)")->fetchAll();
// Après :
$rows = $db->query("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (match_attempts IS NULL OR match_attempts = 0)")->fetchAll();
```

- [ ] **Step 2 : Changer le no-media skip (ligne 117)**

```php
// Avant :
$db->prepare("UPDATE folder_posters SET ai_attempts = 3 WHERE rowid = :id")->execute([':id' => $rid]);
// Après :
$db->prepare("UPDATE folder_posters SET match_attempts = 1 WHERE rowid = :id")->execute([':id' => $rid]);
```

- [ ] **Step 3 : Changer le failed TMDB increment (ligne 180)**

```php
// Avant :
$db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE rowid = :id")
   ->execute([':id' => $rowId]);
// Après :
$db->prepare("UPDATE folder_posters SET match_attempts = 1 WHERE rowid = :id")
   ->execute([':id' => $rowId]);
```

- [ ] **Step 4 : Changer le no-title handling (lignes 194-206)**

Actuellement le bloc `$noTitle` incrémente `ai_attempts` sur TOUS les no-title. Il faut séparer :
- Code saison nu (`S01`, `S02`, `Saison 3`...) → **skip silencieux** (match_attempts reste 0, la propagation parent→enfant s'en occupera)
- Vraiment sans titre → `match_attempts = 1`

Remplacer le bloc entier (lignes 193-207) :

```php
// Increment attempts for entries with no extractable title
// Exception: bare season/episode codes (S01, Saison 3, etc.) — leave match_attempts=0
// so propagation can handle them once the parent is matched.
if ($noTitle) {
    try { $db->beginTransaction(); } catch (PDOException $e) {}
    foreach ($noTitle as $n) {
        if (preg_match('/^(s\d{1,2}|saison\s*\d+|season\s*\d+|e\d{2,4})$/i', trim($n))) {
            // Bare season/episode code — skip silently, propagation will handle
            continue;
        }
        $rid = $getRowId($dir, $n);
        if ($rid) {
            try {
                $db->prepare("UPDATE folder_posters SET match_attempts = 1 WHERE rowid = :id")->execute([':id' => $rid]);
            } catch (PDOException $e) {
                ai_log('DB error (no-title inc): ' . $e->getMessage());
            }
        }
    }
    try { $db->commit(); } catch (PDOException $e) {}
}
```

- [ ] **Step 5 : Vérifier qu'il ne reste aucune occurrence de `ai_attempts` dans le worker**

```bash
grep -n "ai_attempts" /var/www/sharebox/tools/tmdb-worker.php
```

Attendu : aucun résultat.

- [ ] **Step 6 : Test rapide en CLI (dry run)**

```bash
sudo -u www-data php -r '
require "/var/www/sharebox/functions.php";
$cases = ["S01", "Saison 3", "Season 12", "E001", "Les.Simpson.1989.MULTI.1080p", "Random.Junk"];
foreach ($cases as $c) {
    $isSeason = preg_match("/^(s\d{1,2}|saison\s*\d+|season\s*\d+|e\d{2,4})$/i", trim($c));
    $title = extract_title_year($c)["title"];
    echo str_pad($c, 35) . " isSeason=" . (int)$isSeason . " title=" . ($title ?: "(empty)") . "\n";
}
'
```

Attendu :
```
S01                                 isSeason=1 title=(empty)
Saison 3                            isSeason=1 title=(empty)
Season 12                           isSeason=1 title=(empty)
E001                                isSeason=1 title=(empty)
Les.Simpson.1989.MULTI.1080p        isSeason=0 title=Les Simpson
Random.Junk                         isSeason=0 title=Random Junk
```

- [ ] **Step 7 : Lancer les tests**

```bash
cd /var/www/sharebox && vendor/bin/phpunit
```

Attendu : tous les tests passent.

- [ ] **Step 8 : Commit**

```bash
cd /var/www/sharebox && git add tools/tmdb-worker.php
git commit -m "fix: worker — 1 attempt max, skip bare season codes, rename ai_attempts"
```

---

### Task 4 : Supprimer `tools/ai-titles.php`

**Files:**
- Delete: `tools/ai-titles.php`

- [ ] **Step 1 : Vérifier qu'il n'est appelé nulle part**

```bash
grep -rn "ai-titles\|ai_titles" /var/www/sharebox --include="*.php" --include="*.js" --include="*.sh" --include="*.md"
```

Attendu : aucune référence active (uniquement dans le fichier lui-même si grep le trouve).

- [ ] **Step 2 : Supprimer le fichier**

```bash
rm /var/www/sharebox/tools/ai-titles.php
```

- [ ] **Step 3 : Lancer les tests**

```bash
cd /var/www/sharebox && vendor/bin/phpunit
```

Attendu : tous les tests passent.

- [ ] **Step 4 : Commit**

```bash
cd /var/www/sharebox && git add -u tools/ai-titles.php
git commit -m "chore: remove ai-titles.php (dead code, replaced by tmdb-worker.php)"
```

---

### Task 5 : Mettre à jour le skill `.claude/commands/tmdb-scan.md`

**Files:**
- Modify: `.claude/commands/tmdb-scan.md`

- [ ] **Step 1 : Mettre à jour le DB update pattern "No match"**

Trouver :
```
-- No match (retry later):
UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts,0) + 1 WHERE path=?
```

Remplacer par :
```
-- No match (worker already tried once — skill sets match_attempts=1 to mark as attempted):
UPDATE folder_posters SET match_attempts = 1 WHERE path=?
```

- [ ] **Step 2 : Mettre à jour la Phase 1 query**

Trouver dans Phase 1 :
```
2. **Query pending** — `poster_url IS NULL` or `verified = -1` (recheck requests)
```

Remplacer par :
```
2. **Query pending** — `poster_url IS NULL` or `verified = -1` (recheck requests). Ne pas filtrer sur `match_attempts` — le skill doit traiter tout ce qui n'a pas de poster, quelle que soit la valeur de `match_attempts`.
```

- [ ] **Step 3 : Mettre à jour le commentaire dans "No match" de Phase 1 étape 5**

Trouver :
```
   - If still no result → increment `ai_attempts`
```

Remplacer par :
```
   - If still no result → set `match_attempts = 1`
```

- [ ] **Step 4 : Vérifier qu'il ne reste aucune occurrence de `ai_attempts` dans le skill**

```bash
grep -n "ai_attempts" /var/www/sharebox/.claude/commands/tmdb-scan.md
```

Attendu : aucun résultat.

- [ ] **Step 5 : Commit**

```bash
cd /var/www/sharebox && git add .claude/commands/tmdb-scan.md
git commit -m "docs: update tmdb-scan skill — rename ai_attempts, clarify match_attempts semantics"
```

---

### Task 6 : Vérification finale

- [ ] **Step 1 : Suite de tests complète**

```bash
cd /var/www/sharebox && vendor/bin/phpunit && vendor/bin/phpstan analyse
```

Attendu : tous les tests passent, PHPStan level 5 sans erreur.

- [ ] **Step 2 : Vérifier qu'il ne reste aucune occurrence de `ai_attempts` dans tout le projet**

```bash
grep -rn "ai_attempts" /var/www/sharebox --include="*.php" --include="*.md" --include="*.js"
```

Attendu : aucun résultat.

- [ ] **Step 3 : Reload PHP-FPM**

```bash
systemctl reload php8.3-fpm
```

- [ ] **Step 4 : Test smoke en production**

```bash
sudo -u www-data php -r '
require "/var/www/sharebox/config.php"; require "/var/www/sharebox/db.php";
$db = get_db();
$cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), "name");
echo "match_attempts present: " . (in_array("match_attempts", $cols) ? "YES" : "NO") . "\n";
echo "ai_attempts present: " . (in_array("ai_attempts", $cols) ? "YES (bug)" : "NO (ok)") . "\n";
$pending = $db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NULL AND (match_attempts IS NULL OR match_attempts = 0)")->fetchColumn();
echo "Entries pending for worker: $pending\n";
'
```

Attendu :
```
match_attempts present: YES
ai_attempts present: NO (ok)
Entries pending for worker: 0
```
