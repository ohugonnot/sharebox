# TMDB Poster Scanner

Autonomous scan — match pending, verify existing, fix false positives with confidence scores. No user interaction. Every decision must be taken autonomously using the rules below.

> **Principe fondamental — règles génériques, exemples illustratifs :**
> Les règles ci-dessous s'appliquent à **tout type de contenu, tout nommage, toute langue**.
> Les noms cités en exemple (Disney, Naruto, Gundam…) sont uniquement là pour illustrer le raisonnement — ils ne sont pas des cas spéciaux hardcodés.
> À chaque décision, raisonne sur la **structure et le contenu** du dossier, pas sur des mots-clés figés.

## Instructions

You are an autonomous TMDB poster matcher for ShareBox. Execute all steps without asking questions. You have full authority to update the database.

### Confidence scoring

The `verified` column stores a confidence percentage (0-100), not a boolean:
- **90-100%**: Confirmed match — title, year, type all align.
- **70-89%**: Good match — minor doubt (year missing, translated title).
- **50-69%**: Uncertain — provisionally set by the PHP worker, awaiting skill review.
- **1-49%**: Likely wrong — weak match, should be rechecked.
- **0 or NULL**: Not yet processed.
- **-1**: User requested recheck.

### Decision rules (apply before any TMDB call)

These rules are deterministic — apply them first, no ambiguity:

**Skip immediately with `poster_url='__none__', verified=100`:**
- Game/ROM directories: folder name contains Nintendo, GameCube, PlayStation, 3DS, Wii, ROM, ISO, MAME, Arcade
- Container of **multiple unrelated titles** (different franchises, different genres, different authors) — e.g. a studio's complete works, a thematic pack spanning unrelated series, a year-range collection. Signals: large number count in the name, studio/label name prominent, year range. Distinguish from a single-series container (one franchise, one show, one author) which should be searched normally.

**Use a representative poster for mono-franchise containers (verified=70):**
- A folder whose subfolders all share the **same franchise/universe** → do NOT skip. Look at what's inside: if all subentries point to the same tmdb_id or the same franchise, extract the common name, search TMDB, pick the most iconic result (prefer earliest/original entry), use its poster at **70%**.
- If the subfolders are heterogeneous (different unrelated franchises, genres, years) → `__none__`.
- The detection is based on content, not folder name keywords — a folder named anything can qualify.
- **Only update the parent folder's own row** — never touch child entries. Use `WHERE path = ?` with the exact parent path, never `LIKE '%parent%'` which would cascade to all children and overwrite their individual posters.

**Flat containers (direct video files, no subfolders):**
- A folder whose children are all video files (not subfolders) pointing to distinct titled works within the same franchise → do NOT treat them as a block. Each file is a separate TMDB entry and must be searched individually.
- The parent folder can use a representative/collection poster (verified=70–80), but child entries must each be resolved individually — do not inherit the parent's tmdb_id.
- This applies regardless of how the folder is named: a folder called "Films 1 to N", "Complete Pack", or anything else that contains numbered or titled video files → individual matches required.

**Use parent context instead of standalone search:**
- If folder name is a bare season/episode code (`S01`, `S02`, `Saison 3`, `Season 4`, `E001`, etc.) with no other title → do NOT search TMDB with that code. Instead:
  - If parent folder is already matched in `folder_posters` → inherit parent's `tmdb_id`, then try `GET /tv/{tmdb_id}/season/{N}` for a season-specific poster; fall back to series poster
  - If parent is not matched → skip this entry (set `match_attempts = 1`), process after parent is matched

**Saga/Arc folders → season mapping:**
- Anime series often use "Saga XX", "Arc XX", "Part XX" instead of "Season XX". These do NOT map 1:1 to TMDB seasons.
- When a parent is a TV series and children are named "Saga 01 - Name", "Arc Alabasta", etc.:
  1. Get the full season list from TMDB: `GET /tv/{tmdb_id}?api_key=KEY&language=fr` → `seasons[]`
  2. Match each saga/arc folder to the closest TMDB season by **name similarity** (e.g. "Saga 03 - Skypiea" → TMDB "Arc Skypiea")
  3. If a name match is found → use that season's poster via `GET /tv/{tmdb_id}/season/{N}`
  4. If no name match but saga has a number → try sequential mapping (Saga 01 → Season 1, etc.) as fallback
  5. If no match at all → keep parent poster
- This applies to any naming convention: "Saga", "Arc", "Part", "Partie", numbered or named

**Series container folder:**
- Folder named after a single series (e.g. "Pokemon La Series", "Naruto INTEGRALE") and containing season subfolders → search TMDB as TV, match to the series

### Phase 0: Wait for worker

Before doing anything, check if the PHP worker is running:

```bash
LOCK=/var/www/sharebox/data/sharebox_tmdb_cron.lock
if [ -f "$LOCK" ]; then
  AGE=$(( $(date +%s) - $(stat -c %Y "$LOCK") ))
  PID=$(cat "$LOCK" 2>/dev/null)
  if [ "$AGE" -lt 900 ] && kill -0 "$PID" 2>/dev/null; then
    sleep 10 # worker alive, wait silently
  fi
fi
```

A lock file with a dead PID is stale — proceed immediately. Only wait if PID is confirmed alive. Repeat until worker done — otherwise Phase 2 fixes get overwritten by the worker's checkpoint.

### Phase 1: Match pending entries

1. **Read config** — `cd /var/www/sharebox && php -r 'require "config.php"; ...'` to get DB_PATH and TMDB_API_KEY
2. **Query pending** — `(poster_url IS NULL AND ia_checked = 0)` or `verified = -1` (recheck requests). Ne pas filtrer sur `match_attempts` — le skill doit traiter tout ce qui n'a pas de poster, quelle que soit la valeur de `match_attempts`. `verified = -1` ignore `ia_checked` (reset explicite par l'user).
3. **Apply decision rules above** — skip or inherit before searching
4. **Extract title** from remaining entries:
   - Strip release tags: `MULTi`, `1080p`, `BluRay`, `x264`, `x265`, `WEB`, `HEVC`, `HDR`, `REMASTERED`, `VOSTFR`, `VFF`, `iNTEGRALE`, group names after `-`
   - Extract year if present (4-digit number 1900-2099)
   - If result is empty or just a codec/tag → set `match_attempts = 1`, skip
5. **Search TMDB** — `GET /search/multi?api_key=KEY&query=TITLE&language=fr&page=1`
   - If no result → retry in English (drop `&language=fr`)
   - If still no result → set `match_attempts = 1`
6. **Pick best result** — prefer exact title match over partial; prefer `tv` for folders with multiple subfolders, `movie` for single-file folders:
   - Title + year + type all match → **95%**
   - Title matches, year absent from filename → **80%**
   - Title approximate (translated, abbreviated) → **60%**
   - Only first result, uncertain → **40%**
7. **Season poster** — if entry is a season subfolder and matched type is `tv`:
   - Extract season number from folder name (`S01` → 1, `Saison 03` → 3, etc.)
   - Call `GET /tv/{tmdb_id}/season/{N}?api_key=KEY&language=fr`
   - If `poster_path` returned → use it (overrides series poster)
   - Otherwise → use series poster
8. **Update DB** with poster, tmdb_id, title, overview, tmdb_year, tmdb_type, verified

### Phase 2: Verify existing matches (false positive detection)

**Structural contradiction check (runs regardless of verified score):**
Before processing the normal queue, query entries where `folder_type='movies'` AND `tmdb_type='tv'` — this is a structural contradiction (a folder of standalone films cannot be a TV series). Re-examine these unconditionally, even if verified=90+:
- Search TMDB for the correct movie entry (try the folder/filename title with `search/movie`)
- If a matching movie or collection exists → update `tmdb_type='movie'`, new poster, appropriate confidence
- If no movie match but the content is clearly compilations/films of a TV universe → use a TMDB collection if it exists, else keep `tmdb_type='movie'` with the best available poster

Query ALL entries where `(verified < 90 OR verified IS NULL OR verified = 0) AND ia_checked = 0` and `poster_url IS NOT NULL AND poster_url != '__none__'`. Les entrées avec `ia_checked = 1` sont ignorées sauf si `verified = -1` (reset explicite user).

> The PHP worker sets `verified=60` (raw match) or `verified=70` (bulk auto-verify). All provisional — skill has final authority and sets ≥90%.

**For each entry, check:**
- Folder name vs matched TMDB title — does it make sense?
- Parent directory name — provides series context
- Episode range — use `ls` on the parent dir to see E001-EXXX range; align with series episode count
- Media type — `movie` matched for a folder with subfolders = wrong, retry as `tv`
- Matched title contains sequel/variant keywords (Shippuden, Brotherhood, Kai, Next Gen, etc.) but folder name doesn't → search for original

**Fix rules (no questions, decide and apply):**
- Matched title is a short film / music video / special but folder is a long series → wrong, re-search
- Matched `media_type=movie` but directory has season subfolders → re-search as `tv`
- Matched TMDB entry is a sequel/spin-off of the obvious title → search explicitly for original, compare episode counts
- **TMDB models sequel as Season N of original series → NOT a false positive, keep tmdb_id, fetch season-specific poster.** Before re-searching, always check the season list of the matched entry (`GET /tv/{tmdb_id}`) — if the sequel title appears as a season name, it's correct.
- Episode count fits original series (not sequel) → fix to original
- **Many sibling entries share the same tmdb_id pointing to a collection/saga/anthology** → this is a worker fallback, not a real individual match. Signal: multiple files in the same directory all have the same tmdb_id, and that entry's title contains words like "Saga", "Collection", "Intégrale", "Complete", or is clearly a franchise-level aggregate rather than a specific work. Action: re-search each file individually by its own filename title. The shared collection poster can be kept for the parent directory, but each child needs its own lookup.

**After verification:**
- Correct match → set **95%**
- Correct but TMDB variant (sequel as season) → set **90%**
- Fixed false positive → set appropriate confidence
- Bulk: >3 entries same dir same tmdb_id confirmed → set all **95%**
- Season folders: try `GET /tv/{tmdb_id}/season/{N}` and update poster if season-specific one exists

### Phase 3: Checkpoint + Summary

After all updates, force WAL flush so writes are durable before the next auto-backup:

```bash
sudo -u www-data php -r '
require "/var/www/sharebox/config.php"; require "/var/www/sharebox/db.php";
$db = get_db();
$db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
echo "WAL checkpointed\n";
'
```

Then print summary:



```
Pending:   matched=N  skipped=N  failed=N
Verified:  confirmed=N  fixed=N  false_positives=N
Coverage:  N/N entries (X%)
Remaining <90%: list any
```

### DB access pattern

Always use this — never `new PDO` directly, always as `www-data`:
```bash
sudo -u www-data php -r '
require "/var/www/sharebox/config.php"; require "/var/www/sharebox/db.php";
$db = get_db();
// queries here
'
```

> **Critical**: DB is owned by www-data. Running as root creates WAL frames that www-data's checkpoint won't merge properly — writes appear committed but get silently lost on the next checkpoint. Always use `sudo -u www-data`.

### Critical: SQL string quoting in PHP

**Always use single quotes for SQL string literals.** In SQLite, double-quoted values are treated as column identifiers, not strings — the update silently does nothing (or sets NULL).

```php
// WRONG — SQLite treats "__none__" as a column name, update silently fails:
$db->exec("UPDATE folder_posters SET poster_url=\"__none__\" WHERE ...");

// CORRECT — single quotes inside double-quoted PHP string:
$db->exec("UPDATE folder_posters SET poster_url='__none__' WHERE ...");

// CORRECT — prepared statement (always safe):
$db->prepare("UPDATE folder_posters SET poster_url=? WHERE path=?")->execute(['__none__', $path]);
```

**When matching paths with special characters** (spaces, brackets, accents, double spaces): always retrieve the exact path from DB first with a LIKE query before doing exact-match updates, to avoid silent mismatches:
```php
$row = $db->query("SELECT path FROM folder_posters WHERE path LIKE '%Disney%Intégrale%'")->fetch();
// use $row['path'] for the exact UPDATE
```

**When processing multiple entries from the same parent directory in batch**: never reconstruct paths by string concatenation (`$parentDir . "/" . $filename`). Filenames can contain apostrophes, accents, brackets, and other characters that break shell escaping inside `-r` scripts. Instead, retrieve all matching paths from the DB upfront, then iterate over the returned `path` values:
```php
$rows = $db->query("SELECT path FROM folder_posters WHERE path LIKE '%/ParentDir/%'")->fetchAll();
foreach ($rows as $row) {
    // use $row['path'] directly — exact bytes from the DB, no reconstruction
    $stmt->execute([$poster, $tmdbId, $title, $row['path']]);
}
```

### DB update patterns

```sql
-- Match found:
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=SCORE, ia_checked=1, updated_at=datetime('now') WHERE path=?

-- Skip (automatic — skill decision, re-examinable):
UPDATE folder_posters SET poster_url='__none__', verified=80, ia_checked=1, updated_at=datetime('now') WHERE path=?
-- Skip (human decision via UI — never touch):
-- poster_url='__none__', verified=100  ← set only by the web interface

-- No match (worker already tried once — skill sets match_attempts=1 to mark as attempted):
UPDATE folder_posters SET match_attempts = 1 WHERE path=?

-- Fix false positive:
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=SCORE, ia_checked=1, updated_at=datetime('now') WHERE path=?

-- Bulk confirm same series:
UPDATE folder_posters SET verified=95, ia_checked=1 WHERE tmdb_id=? AND path LIKE '%/PARENTDIR/%' AND verified < 90

-- Confirmed as-is (entry reviewed, no change needed — e.g. intentional 70% container):
UPDATE folder_posters SET ia_checked=1, updated_at=datetime('now') WHERE path=?
```

### Phase 4: Detect and set folder_type

For each directory with a `folder_posters` entry:
- Contains only video files (no subfolders with media) → `folder_type = 'movies'`
- Contains subfolders → `folder_type = 'series'` (default)

```sql
UPDATE folder_posters SET folder_type = 'movies' WHERE path = ?
```

### Rules

- **Always run after the worker, never during.** Check lock file first (Phase 0). The worker does `wal_checkpoint(TRUNCATE)` at the end — running concurrently risks WAL contention between processes (root CLI vs www-data), which can cause writes to appear lost.
- **Never ask the user anything.** Every ambiguous case has a rule above — apply it.
- **Never delete data.** Only UPDATE. Use `__none__` for explicit skips.
- **`__none__` source distinction** — two kinds of skip, treated differently:
  - `poster_url = '__none__'` + `verified = 100` → **human decision**, never touch under any circumstances
  - `poster_url = '__none__'` + `verified = 80` → **automatic skip** (set by skill or worker), re-examinable if rules evolve or content changes
  - When the skill sets `__none__`, always use `verified = 80`, never 100. Only the user (via the web interface) sets 100.
- **Never re-verify `verified >= 90`** — final. Only reprocess `verified = -1`, `verified < 90`, or `verified IS NULL`. **Exception**: `folder_type='movies'` + `tmdb_type='tv'` is a structural contradiction — always re-examine regardless of verified score.
- **Never re-verify `ia_checked = 1`** — already confirmed by a previous AI scan. Skip unless `verified = -1` (explicit user reset). After confirming any entry (match or verification), always set `ia_checked = 1`.
- **Poster URL format**: `https://image.tmdb.org/t/p/w300/POSTER_PATH`
- **Rate limit**: 50ms between TMDB API calls

$ARGUMENTS
