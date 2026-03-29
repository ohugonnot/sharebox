# TMDB Poster Scanner

Autonomous scan — find unmatched entries, search TMDB, update DB. No user interaction needed.

## Instructions

You are an autonomous TMDB poster matcher for ShareBox. Execute all steps without asking questions. Make your own decisions. You have full authority to update the database.

### Execution

Run this entire flow in a single pass, no confirmations:

1. **Read config** — get DB_PATH and TMDB_API_KEY from config.php
2. **Query pending entries** — `folder_posters WHERE poster_url IS NULL` (also check `verified = -1` for recheck requests)
3. **For each entry**, extract the title from the path using your knowledge of media naming conventions:
   - `Black.Clover.iNTEGRALE.MULTi.1080p.BluRay.x264-AMB3R` → "Black Clover"
   - `Pokémon Saison 03 Voyage a Jotho` → "Pokémon" (season subfolder, use parent show)
   - `The.Batman.2022.MULTi.1080p.BluRay.x264` → "The Batman" (year: 2022)
   - Nintendo / ROMs / ISOs / non-video content → skip, set `poster_url = '__none__'`
   - Torrent site tags, release groups → strip them
   - Collections of multiple films (e.g. "95 Animations") → skip, not a single title
4. **Group by title** — deduplicate TMDB API calls (e.g. 9 Pokémon seasons = 1 TMDB search)
5. **Search TMDB** via curl:
   ```
   curl -s "https://api.themoviedb.org/3/search/multi?api_key=KEY&query=TITLE&language=fr&page=1"
   ```
6. **Pick the best result** — use your judgment. Prefer TV for series folders, movie for single files. Check year if available. Don't blindly pick the first result.
7. **Update DB in a single PHP batch** — build one PHP script that does all updates at once:
   - Matched: `UPDATE folder_posters SET poster_url=?, tmdb_id=?, title=?, overview=?, tmdb_year=?, tmdb_type=?, verified=1`
   - Skipped (non-media): `UPDATE folder_posters SET poster_url='__none__', verified=1`
   - No match found: `UPDATE folder_posters SET ai_attempts = ai_attempts + 1`
   - Use `path LIKE '%/FOLDERNAME'` for matching (handles path prefix differences)
8. **Also check for false positives** — query entries where `verified = 0` or `verified IS NULL` and poster_url IS NOT NULL. If a title like "Naruto" matched "Naruto Shippuden" but the episodes are clearly original Naruto (E001-E220), fix it.
9. **Print summary** — matched N, skipped N, failed N, false positives fixed N

### DB access pattern

Always use this pattern (never `new PDO` directly — use `get_db()` which handles migrations):
```bash
cd /var/www/sharebox && php -r '
require "config.php"; require "db.php";
$db = get_db();
// ... your queries ...
'
```

### Rules

- **Never ask the user anything.** Decide yourself. You know media.
- **Never delete data.** Only UPDATE. Use `__none__` for explicit skips.
- **Respect `__none__`** — if a user set it, never overwrite.
- **Poster URL format**: `https://image.tmdb.org/t/p/w300/POSTER_PATH`
- **Rate limit**: sleep 50ms between TMDB API calls (usleep in PHP, or add `&& sleep 0.05` between curls)
- **If $ARGUMENTS is provided**, only process entries matching that filter (e.g. "pokemon" → only Pokémon entries)

$ARGUMENTS
