# TMDB Poster Scanner

Autonomous scan — find unmatched entries, verify existing matches, fix false positives. No user interaction.

## Instructions

You are an autonomous TMDB poster matcher for ShareBox. Execute all steps without asking questions. Make your own decisions. You have full authority to update the database.

### Phase 1: Match pending entries

1. **Read config** — `cd /var/www/sharebox && php -r 'require "config.php"; ...'` to get DB_PATH and TMDB_API_KEY
2. **Query pending** — `folder_posters WHERE poster_url IS NULL` + `verified = -1` (recheck requests)
3. **For each entry**, extract the title from the path name:
   - `Black.Clover.iNTEGRALE.MULTi.1080p.BluRay.x264-AMB3R` → "Black Clover"
   - `Pokémon Saison 03 Voyage a Jotho` → "Pokémon" (season subfolder → use parent show)
   - `The.Batman.2022.MULTi.1080p.BluRay.x264` → "The Batman" (year: 2022)
   - Nintendo / ROMs / ISOs / non-video → skip, set `poster_url = '__none__'`
   - Collections of multiple films (e.g. "95 Animations") → skip
4. **Group by title** — deduplicate TMDB API calls
5. **Search TMDB** — `curl -s "https://api.themoviedb.org/3/search/multi?api_key=KEY&query=TITLE&language=fr&page=1"`
6. **Pick the best result** — use your judgment, don't blindly pick first result
7. **Batch update DB** in one PHP script

### Phase 2: Verify existing matches (false positive detection)

This is critical. Query ALL entries where `verified = 0 OR verified IS NULL` and `poster_url IS NOT NULL`.

For each entry, use ALL available context to verify the match is correct:

- **The folder/file name** — what the user actually has on disk
- **The parent directory name** — gives context (e.g. parent is "Naruto.INTEGRALE" → episodes inside are Naruto, NOT Naruto Shippuden)
- **Sibling files in the same directory** — list them with `ls` to understand the content (episode numbers, season info)
- **The matched TMDB title** — what we matched it to
- **The TMDB overview** — does the synopsis match what this content is?
- **The TMDB year** — does it align with the release year in the filename?
- **The TMDB type** (tv/movie) — a folder full of episodes should be a TV show, a single .mkv should be a movie
- **Episode numbering** — Naruto E001-E220 is Naruto classic (id=20), NOT Naruto Shippuden (id=31910). Shippuden starts at E001 too but in a separate folder.

**Verification process:**
1. Query unverified entries grouped by parent directory
2. For directories with many entries (episodes), `ls` the directory to see the full picture
3. Cross-check: does the matched title make sense for ALL files in that directory?
4. If a match is wrong, search TMDB for the correct title and update
5. If a match is correct, set `verified = 1`
6. Auto-verify in bulk: if >3 entries in the same dir share the same tmdb_id AND the match looks right, verify them all at once

**Common false positives to watch for:**
- "Naruto" → "Naruto Shippuden" (TMDB returns Shippuden first, but original Naruto is id=20)
- "Pokemon" → some random Pokemon movie instead of the main series (id=60572)
- "Gundam Seed" → wrong Gundam show
- French titles matching the wrong localized version
- A movie matching a TV show or vice versa

### Phase 3: Summary

Print a clear summary:
- Pending matched: N
- Pending skipped: N (with reasons)
- Pending failed: N
- False positives fixed: N
- Verified correct: N
- Total coverage: N/N (X%)

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
-- Match found:
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=1, updated_at=datetime('now') WHERE path LIKE '%/FOLDERNAME'

-- Skip (non-media):
UPDATE folder_posters SET poster_url='__none__', verified=1 WHERE path LIKE '%/FOLDERNAME'

-- No match:
UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts,0) + 1 WHERE path LIKE '%/FOLDERNAME'

-- Fix false positive:
UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=1 WHERE path LIKE '%/FOLDERNAME' AND poster_url IS NOT NULL

-- Verify correct:
UPDATE folder_posters SET verified=1 WHERE path LIKE '%/DIRNAME/%' AND tmdb_id=?
```

### Rules

- **Never ask the user anything.** Decide yourself. You know media.
- **Never delete data.** Only UPDATE. Use `__none__` for explicit skips.
- **Respect `__none__`** — if a user manually hid a poster, never overwrite.
- **Poster URL format**: `https://image.tmdb.org/t/p/w300/POSTER_PATH`
- **Rate limit**: 50ms between TMDB API calls
- **If $ARGUMENTS is provided**, only process entries matching that filter

$ARGUMENTS
