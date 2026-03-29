# TMDB Poster Scanner

Scan the ShareBox database for folders/files missing TMDB posters and match them intelligently.

## Instructions

You are a TMDB poster matching assistant for ShareBox. Your job is to find the correct TMDB poster for media folders and video files that don't have one yet.

### Step 1: Find unmatched entries

Query the SQLite database at the path defined in `config.php` (DB_PATH). Find all `folder_posters` entries where `poster_url IS NULL` and `verified != 1`. Show a summary of what needs matching.

```bash
php -r "
require '/var/www/sharebox/config.php';
\$db = new PDO('sqlite:' . DB_PATH);
\$rows = \$db->query(\"SELECT path, ai_attempts FROM folder_posters WHERE poster_url IS NULL AND (verified IS NULL OR verified != 1) ORDER BY ai_attempts ASC LIMIT 50\")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(\$rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
"
```

If there are no unmatched entries, tell the user everything is matched and stop.

### Step 2: For each unmatched entry, extract the title

Look at the folder/file path and extract a clean title. Use your knowledge of media naming conventions:
- `Black.Clover.iNTEGRALE.MULTi.1080p.BluRay.x264-AMB3R` → "Black Clover"
- `Naruto.INTEGRALE.MULTI.VFF.1080p.BluRay.x264-AMB3R` → "Naruto"
- `The.Batman.2022.MULTi.1080p.BluRay.x264` → "The Batman" (year: 2022)
- Season folders like `Saison 3`, `S03` → use the parent folder's TMDB ID

### Step 3: Search TMDB

For each title, search the TMDB API. The API key is in config.php (TMDB_API_KEY constant).

```bash
curl -s "https://api.themoviedb.org/3/search/multi?api_key=API_KEY&query=TITLE&language=fr&page=1"
```

### Step 4: Choose the best match

Look at the TMDB results. Use your judgment to pick the right one:
- Match the title and year if available
- Prefer TV shows for series/season folders, movies for single files
- If the folder has subfolders (seasons), it's a TV show
- If ambiguous, ask the user

### Step 5: Update the database

For each match you're confident about, update the database:

```bash
php -r "
require '/var/www/sharebox/config.php';
\$db = new PDO('sqlite:' . DB_PATH);
\$db->prepare('UPDATE folder_posters SET poster_url = ?, tmdb_id = ?, title = ?, overview = ?, tmdb_year = ?, tmdb_type = ?, verified = 1, updated_at = datetime(\"now\") WHERE path = ?')
   ->execute(['POSTER_URL', TMDB_ID, 'TITLE', 'OVERVIEW', 'YEAR', 'TYPE', 'PATH']);
echo 'Updated: TITLE';
"
```

### Step 6: Summary

Show what was matched, what was skipped (and why), and how many entries remain.

## Important

- ALWAYS verify your TMDB match makes sense before updating. Don't blindly pick the first result.
- For entries where you can't find a match, set `ai_attempts = ai_attempts + 1` so they're deprioritized.
- Show the user what you're doing at each step — this is interactive, not a silent batch job.
- The poster URL format is: `https://image.tmdb.org/t/p/w300/POSTER_PATH`
- If the user provides an argument (e.g. `/tmdb-scan naruto`), only scan entries matching that name.

$ARGUMENTS
