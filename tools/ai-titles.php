#!/usr/bin/env php
<?php
/**
 * AI-powered movie title extraction for TMDB poster matching.
 * Uses Claude CLI (Haiku) to clean up filenames before TMDB search.
 *
 * Usage: php tools/ai-titles.php <folder-path>
 *        php tools/ai-titles.php --all   (process all movies-tagged folders)
 *
 * Must run as a user that has claude CLI configured (not www-data).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$CLAUDE_BIN = trim(shell_exec('which claude') ?? '');
if (!$CLAUDE_BIN) {
    fwrite(STDERR, "Error: claude CLI not found in PATH\n");
    exit(1);
}

$TMDB_API_KEY = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
if (!$TMDB_API_KEY) {
    fwrite(STDERR, "Error: TMDB_API_KEY not configured in config.php\n");
    exit(1);
}

$VIDEO_EXTS = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];

// ── Parse arguments ──
$arg = $argv[1] ?? '';
if (!$arg || $arg === '--help' || $arg === '-h') {
    echo "Usage: php tools/ai-titles.php <folder-path>\n";
    echo "       php tools/ai-titles.php --all\n";
    exit(0);
}

$db = get_db();

if ($arg === '--all') {
    $rows = $db->query("SELECT DISTINCT path FROM folder_posters WHERE folder_type = 'movies'")->fetchAll();
    if (empty($rows)) {
        echo "No folders tagged as 'movies' found.\n";
        exit(0);
    }
    foreach ($rows as $row) {
        if (is_dir($row['path'])) {
            processFolder($row['path'], $db, $CLAUDE_BIN, $TMDB_API_KEY, $VIDEO_EXTS);
        }
    }
} else {
    $path = realpath($arg);
    if (!$path || !is_dir($path)) {
        fwrite(STDERR, "Error: '$arg' is not a valid directory\n");
        exit(1);
    }
    processFolder($path, $db, $CLAUDE_BIN, $TMDB_API_KEY, $VIDEO_EXTS);
}

function processFolder(string $dirPath, PDO $db, string $claudeBin, string $apiKey, array $videoExts): void
{
    echo "\n=== " . basename($dirPath) . " ===\n";

    // List video files
    $items = scandir($dirPath);
    $videoFiles = [];
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        if (is_dir($dirPath . '/' . $item)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (in_array($ext, $videoExts, true)) {
            $videoFiles[] = $item;
        }
    }

    if (empty($videoFiles)) {
        echo "  No video files found.\n";
        return;
    }

    // Filter out already-cached files (with a poster or __none__)
    $uncached = [];
    foreach ($videoFiles as $vf) {
        $fullPath = $dirPath . '/' . $vf;
        $stmt = $db->prepare("SELECT poster_url FROM folder_posters WHERE path = :p");
        $stmt->execute([':p' => $fullPath]);
        $row = $stmt->fetch();
        if ($row && $row['poster_url']) {
            continue;
        }
        $uncached[] = $vf;
    }

    if (empty($uncached)) {
        echo "  All " . count($videoFiles) . " files already cached.\n";
        return;
    }

    echo "  " . count($uncached) . " files to process (out of " . count($videoFiles) . " total)\n";

    // Batch filenames to Claude Haiku for title extraction
    $titles = askClaude($uncached, $claudeBin);
    if ($titles === null) {
        echo "  Claude CLI failed, falling back to regex extraction.\n";
        $titles = [];
        foreach ($uncached as $vf) {
            $meta = extract_title_year($vf);
            $titles[] = ['file' => $vf, 'title' => $meta['title'], 'year' => $meta['year'], 'skip' => false];
        }
    }

    // Search TMDB for each title
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $found = 0;
    $skipped = 0;

    foreach ($titles as $t) {
        $fileName = $t['file'];
        $fullPath = $dirPath . '/' . $fileName;

        if ($t['skip'] ?? false) {
            echo "  SKIP  " . $fileName . "\n";
            $skipped++;
            try {
                $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, title) VALUES (:p, '__none__', :t)")
                   ->execute([':p' => $fullPath, ':t' => $t['title'] ?? '']);
            } catch (PDOException $e) { /* ignore */ }
            continue;
        }

        $title = $t['title'] ?? '';
        $year = $t['year'] ?? null;
        if (!$title) {
            echo "  EMPTY " . $fileName . "\n";
            continue;
        }

        // Search TMDB
        $posterUrl = null;
        $tmdbId = null;
        $tmdbTitle = null;
        $tmdbOverview = null;

        $queries = [$title];
        if ($year) {
            $queries[] = $title . ' ' . $year;
        }

        foreach ($queries as $q) {
            $encoded = urlencode($q);
            $urls = [
                "https://api.themoviedb.org/3/search/movie?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
                "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
            ];
            foreach ($urls as $searchUrl) {
                $resp = @file_get_contents($searchUrl, false, $ctx);
                $data = $resp ? json_decode($resp, true) : null;
                if ($data && !empty($data['results'])) {
                    foreach ($data['results'] as $r) {
                        if (!empty($r['poster_path'])) {
                            $posterUrl = 'https://image.tmdb.org/t/p/w300' . $r['poster_path'];
                            $tmdbId = $r['id'] ?? null;
                            $tmdbTitle = $r['title'] ?? $r['name'] ?? null;
                            $tmdbOverview = $r['overview'] ?? null;
                            break 3;
                        }
                    }
                }
            }
        }

        if ($posterUrl) {
            echo "  OK    " . $fileName . " => " . $tmdbTitle . "\n";
            $found++;
        } else {
            echo "  MISS  " . $fileName . " (searched: " . $title . ")\n";
        }

        try {
            $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
               ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
        } catch (PDOException $e) { /* ignore */ }

        usleep(250000); // 250ms rate limit for TMDB
    }

    echo "  Done: $found found, $skipped skipped, " . (count($uncached) - $found - $skipped) . " missed\n";
}

/**
 * Send filenames to Claude Haiku CLI for intelligent title extraction.
 * Batches in groups of 50 to stay within token limits.
 * Returns array of {file, title, year, skip} or null on failure.
 */
function askClaude(array $fileNames, string $claudeBin): ?array
{
    $allResults = [];
    $batches = array_chunk($fileNames, 50);

    foreach ($batches as $batchIdx => $batch) {
        if (count($batches) > 1) {
            echo "  Asking Claude (batch " . ($batchIdx + 1) . "/" . count($batches) . ")...\n";
        } else {
            echo "  Asking Claude...\n";
        }

        $fileList = json_encode($batch, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu reçois une liste de noms de fichiers vidéo. Pour chaque fichier, extrais le titre propre du film pour une recherche TMDB.

Retourne UNIQUEMENT un JSON array (sans markdown, sans code fences), avec pour chaque fichier:
{"file": "nom original", "title": "titre propre du film", "year": 1999, "skip": false}

Règles:
- Retire les tags site ([Torrent911.com], [YGG], etc.)
- Retire les tags techniques (DVDRIP, x264, FRENCH, BluRay, etc.)
- Retire la numérotation (01 - , 02. , etc.)
- Retire les noms de studio en préfixe (Walt Disney - , Pixar - , etc.)
- skip=true pour les bonus, making-of, extras, bandes-annonces, featurettes, samples
- year=null si pas d'année détectable
- Le titre doit être en français si le nom original est en français

Fichiers:
$fileList
PROMPT;

        // Write prompt to temp file to avoid shell escaping issues with long content
        $tmpFile = tempnam(sys_get_temp_dir(), 'claude_prompt_');
        file_put_contents($tmpFile, $prompt);

        $cmd = escapeshellarg($claudeBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        if (!$output) return null;

        $envelope = json_decode($output, true);
        if (!$envelope || !isset($envelope['result'])) return null;

        $text = $envelope['result'];
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/s', '', $text);
        $text = preg_replace('/\s*```$/s', '', $text);

        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            fwrite(STDERR, "  Warning: could not parse Claude response for batch " . ($batchIdx + 1) . "\n");
            return null;
        }

        $allResults = array_merge($allResults, $parsed);
    }

    return $allResults;
}
