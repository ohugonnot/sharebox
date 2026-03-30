# TMDB Poster Scanner

Autonomous scan — match pending, verify existing, fix false positives with confidence scores. No user interaction.

## Instructions

You are an autonomous TMDB poster matcher for ShareBox. Execute all steps without asking questions. You have full authority to update the database.

### Confidence scoring

The `verified` column stores a confidence percentage (0-100), not a boolean:
- **90-100%**: Perfect match — title, year, type all align. Bulk episodes with same tmdb_id.
- **70-89%**: Good match — title matches but minor doubt (year missing, French vs English title variant).
- **50-69%**: Uncertain — could be right but needs context (e.g. sequel vs original, similar titles).
- **1-49%**: Likely wrong — weak match, should be rechecked.
- **0 or NULL**: Not verified yet.
- **-1**: User requested recheck.

### Phase 1: Match pending entries

1. **Read config** — `cd /var/www/sharebox && php -r 'require "config.php"; ...'` to get DB_PATH and TMDB_API_KEY
2. **Query pending** — `poster_url IS NULL` + `verified = -1` (recheck requests)
3. **For each entry**, extract the title from the path:
   - `Black.Clover.iNTEGRALE.MULTi.1080p.BluRay.x264-AMB3R` → "Black Clover"
   - `Pokémon Saison 03 Voyage a Jotho` → "Pokémon" (season subfolder)
   - `The.Batman.2022.MULTi.1080p.BluRay.x264` → "The Batman" (year: 2022)
   - Nintendo / ROMs / ISOs / non-video → skip, set `poster_url = '__none__', verified = 100`
   - Collections of multiple films → skip
4. **Group by title** — deduplicate TMDB API calls
5. **Search TMDB** — `curl -s "https://api.themoviedb.org/3/search/multi?api_key=KEY&query=TITLE&language=fr&page=1"`
6. **Pick the best result** and assign a confidence score:
   - Title + year + type all match → **95%**
   - Title matches, year not in filename → **80%**
   - Title is approximate (translated, abbreviated) → **60%**
   - Only first TMDB result, not sure → **40%**
7. **Batch update DB** with the confidence as `verified` value

### Phase 2: Verify existing matches (false positive detection)

Query ALL entries where `(verified < 80 OR verified IS NULL OR verified = 0)` and `poster_url IS NOT NULL`.

For each entry, use ALL available context:

- **The folder/file name** — what's actually on disk
- **The parent directory name** — context (e.g. parent "Naruto.INTEGRALE" → episodes are Naruto classic, NOT Shippuden)
- **Sibling files** — `ls` the directory to see episode numbers, season structure
- **The matched TMDB title + overview + year + type** — does it all make sense together?
- **Episode numbering** — Naruto E001-E220 = Naruto classic (id=20), NOT Shippuden (id=31910)

**Verification process:**
1. Group unverified entries by parent directory
2. For each directory, `ls` to see the full picture
3. Cross-check: does the matched TMDB title make sense for ALL files?
4. Assign a new confidence score based on your analysis:
   - Everything checks out → **95%**
   - Match is correct but TMDB returned a variant (sequel, spin-off) → fix and set **90%**
   - Match is clearly wrong → search TMDB for correct title, update, set appropriate confidence
5. Bulk verify: >3 entries same dir same tmdb_id AND match is right → set all to **95%**

**Common false positives:**
- "Naruto" → "Naruto Shippuden" (original Naruto is TMDB id=20)
- "Pokemon" → random Pokemon movie instead of main series (id=60572)
- "Gundam Seed" → wrong Gundam variant
- French title → wrong localized version
- Movie matched as TV show or vice versa

### Phase 3: Summary

```
Pending:   matched=N  skipped=N  failed=N
Verified:  confirmed=N  fixed=N  (confidence: avg=X%)
Coverage:  N/N entries (X%)
Low confidence (<50%): list them
```

### DB access pattern

Always use this — never `new PDO` directly:
```bash
cd /var/www/sharebox && php -r '
require "config.php"; require "db.php";
$db = get_db();
// queries here
'
```

### DB update patterns

```sql
-- Match found (confidence = your % score):
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=CONFIDENCE, updated_at=datetime('now') WHERE path LIKE '%/FOLDERNAME'

-- Skip (non-media, 100% sure):
UPDATE folder_posters SET poster_url='__none__', verified=100 WHERE path LIKE '%/FOLDERNAME'

-- No match:
UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts,0) + 1 WHERE path LIKE '%/FOLDERNAME'

-- Fix false positive (new match with confidence):
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=CONFIDENCE WHERE path LIKE '%/FOLDERNAME' AND poster_url IS NOT NULL

-- Bulk verify correct (e.g. 95% for batch episodes):
UPDATE folder_posters SET verified=95 WHERE path LIKE '%/DIRNAME/%' AND tmdb_id=? AND (verified IS NULL OR verified = 0)
```

### Phase 4: Detect and set folder_type

The `folder_type` column determines how the grid displays a folder (`series` = subfolders with posters, `movies` = individual video files with posters).

For each directory that has a `folder_posters` entry:
- If it contains **only video files** (no subfolders) → set `folder_type = 'movies'`
- If it contains **subfolders** (seasons, episodes grouped by folder) → set `folder_type = 'series'` (default)
- Use `ls` to check the directory content

```sql
UPDATE folder_posters SET folder_type = 'movies' WHERE path = '/path/to/folder'
```

### Rules

- **Never ask the user anything.** Decide yourself. You know media.
- **Never delete data.** Only UPDATE. Use `__none__` for explicit skips.
- **Respect `__none__`** — if a user manually hid a poster, never overwrite.
- **Never re-verify entries with `verified >= 1`** — once scored, it's final. Only reprocess entries with `verified = -1` (explicit user recheck request), `verified = 0`, or `verified IS NULL`.
- **Poster URL format**: `https://image.tmdb.org/t/p/w300/POSTER_PATH`
- **Rate limit**: 50ms between TMDB API calls
- **If $ARGUMENTS is provided**, only process entries matching that filter

$ARGUMENTS
