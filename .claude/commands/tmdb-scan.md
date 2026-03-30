# TMDB Poster Scanner

Autonomous scan — match pending, verify existing, fix false positives with confidence scores. No user interaction. Every decision must be taken autonomously using the rules below.

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
- Multi-title collection: folder name contains `COLLECTION` with a year range like `(2010-2024)`, or `Pack` grouping multiple distinct series (e.g. "Pack Gundam"), or `Intégrale` of a studio (e.g. "Intégrale Disney 95 Animations")
- Collection of films by a single franchise where individual films have distinct TMDB entries (e.g. "Pokemon Films 1 à 22") → skip the container, not the individual films

**Use parent context instead of standalone search:**
- If folder name is a bare season/episode code (`S01`, `S02`, `Saison 3`, `Season 4`, `E001`, etc.) with no other title → do NOT search TMDB with that code. Instead:
  - If parent folder is already matched in `folder_posters` → inherit parent's `tmdb_id`, then try `GET /tv/{tmdb_id}/season/{N}` for a season-specific poster; fall back to series poster
  - If parent is not matched → skip this entry (increment `ai_attempts`), process after parent is matched

**Series container folder:**
- Folder named after a single series (e.g. "Pokemon La Series", "Naruto INTEGRALE") and containing season subfolders → search TMDB as TV, match to the series

### Phase 0: Wait for worker

Before doing anything, check if the PHP worker is running:

```bash
LOCK=/var/www/sharebox/data/sharebox_tmdb_cron.lock
# Lock exists and is recent (< 15 min) → worker is running
if [ -f "$LOCK" ] && [ $(( $(date +%s) - $(stat -c %Y "$LOCK") )) -lt 900 ]; then
  echo "Worker running, waiting..."; sleep 5; # re-check
fi
```

Repeat until lock is gone or stale. Only proceed once worker has finished — otherwise Phase 2 fixes get overwritten by the worker's checkpoint.

### Phase 1: Match pending entries

1. **Read config** — `cd /var/www/sharebox && php -r 'require "config.php"; ...'` to get DB_PATH and TMDB_API_KEY
2. **Query pending** — `poster_url IS NULL` or `verified = -1` (recheck requests)
3. **Apply decision rules above** — skip or inherit before searching
4. **Extract title** from remaining entries:
   - Strip release tags: `MULTi`, `1080p`, `BluRay`, `x264`, `x265`, `WEB`, `HEVC`, `HDR`, `REMASTERED`, `VOSTFR`, `VFF`, `iNTEGRALE`, group names after `-`
   - Extract year if present (4-digit number 1900-2099)
   - If result is empty or just a codec/tag → increment `ai_attempts`, skip
5. **Search TMDB** — `GET /search/multi?api_key=KEY&query=TITLE&language=fr&page=1`
   - If no result → retry in English (drop `&language=fr`)
   - If still no result → increment `ai_attempts`
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

Query ALL entries where `(verified < 90 OR verified IS NULL OR verified = 0)` and `poster_url IS NOT NULL AND poster_url != '__none__'`.

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
- TMDB models sequel as Season N of original series → NOT a false positive, keep tmdb_id
- Episode count fits original series (not sequel) → fix to original

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

### DB update patterns

```sql
-- Match found:
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=SCORE, updated_at=datetime('now') WHERE path=?

-- Skip (non-media / collection):
UPDATE folder_posters SET poster_url='__none__', verified=100, updated_at=datetime('now') WHERE path=?

-- No match (retry later):
UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts,0) + 1 WHERE path=?

-- Fix false positive:
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=SCORE, updated_at=datetime('now') WHERE path=?

-- Bulk confirm same series:
UPDATE folder_posters SET verified=95 WHERE tmdb_id=? AND path LIKE '%/PARENTDIR/%' AND verified < 90
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
- **Respect `__none__`** — if `poster_url = '__none__'`, never overwrite.
- **Never re-verify `verified >= 90`** — final. Only reprocess `verified = -1`, `verified < 90`, or `verified IS NULL`.
- **Poster URL format**: `https://image.tmdb.org/t/p/w300/POSTER_PATH`
- **Rate limit**: 50ms between TMDB API calls

$ARGUMENTS
