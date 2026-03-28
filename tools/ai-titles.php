#!/usr/bin/env php
<?php
/**
 * AI-powered movie title extraction for TMDB poster matching.
 *
 * Usage: php tools/ai-titles.php <folder-path>   (process one folder with AI)
 *        php tools/ai-titles.php --all            (AI pass on all movies-tagged folders)
 *        php tools/ai-titles.php --pending         (cron: AI retry where regex failed)
 *        php tools/ai-titles.php --verify          (cron: AI checks existing matches)
 *        php tools/ai-titles.php --cron            (runs --pending then --verify)
 *
 * Flow:
 *   1. ?posters=1 (inline, fast) does regex extract_title_year() + TMDB
 *   2. Files with no match get poster_url=NULL in DB
 *   3. --pending: finds NULLs, asks AI for better titles, retries TMDB
 *   4. --verify: sends {filename, matched_title} pairs to AI, fixes bad matches
 *   5. Respects human choices: __none__ = user said no poster, never overwrite
 *
 * AI adapter: currently uses Claude CLI (Haiku). To switch provider,
 * replace askAI() — same interface: filenames in, {file,title,year,skip}[] out.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

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
    echo "       php tools/ai-titles.php --pending   (cron: fix missing)\n";
    echo "       php tools/ai-titles.php --verify    (cron: fix bad matches)\n";
    echo "       php tools/ai-titles.php --cron      (runs both)\n";
    exit(0);
}

$db = get_db();

// Resolve AI provider (Claude CLI for now)
$AI_BIN = trim(shell_exec('which claude') ?? '');
if (!$AI_BIN) {
    fwrite(STDERR, "Warning: claude CLI not found, will use regex fallback only\n");
}

$runModes = ($arg === '--cron') ? ['--pending', '--verify'] : [$arg];

foreach ($runModes as $mode) {

if ($mode === '--all' || $mode === '--pending') {
    $rows = $db->query("SELECT DISTINCT path FROM folder_posters WHERE folder_type = 'movies'")->fetchAll();
    if (empty($rows)) {
        echo "No folders tagged as 'movies' found.\n";
        exit(0);
    }
    $pendingOnly = ($arg === '--pending');
    $total = 0;
    foreach ($rows as $row) {
        if (is_dir($row['path'])) {
            $total += processFolder($row['path'], $db, $AI_BIN, $TMDB_API_KEY, $VIDEO_EXTS, $pendingOnly);
        }
    }
    if ($pendingOnly && $total === 0) {
        echo "Nothing pending.\n";
    }
} elseif ($arg === '--verify') {
    if (!$AI_BIN) {
        fwrite(STDERR, "Error: --verify requires AI (claude CLI not found)\n");
        exit(1);
    }
    $rows = $db->query("SELECT DISTINCT path FROM folder_posters WHERE folder_type = 'movies'")->fetchAll();
    if (empty($rows)) {
        echo "No folders tagged as 'movies' found.\n";
        exit(0);
    }
    $total = 0;
    foreach ($rows as $row) {
        if (is_dir($row['path'])) {
            $total += verifyFolder($row['path'], $db, $AI_BIN, $TMDB_API_KEY, $VIDEO_EXTS);
        }
    }
    if ($total === 0) {
        echo "All matches look correct.\n";
    }
} else {
    $path = realpath($mode);
    if (!$path || !is_dir($path)) {
        fwrite(STDERR, "Error: '$mode' is not a valid directory\n");
        exit(1);
    }
    processFolder($path, $db, $AI_BIN, $TMDB_API_KEY, $VIDEO_EXTS, false);
}

} // end foreach $runModes

/**
 * Process a single movies folder.
 * @param bool $pendingOnly If true, only process files with poster_url=NULL (regex already tried)
 * @return int Number of files processed
 */
function processFolder(string $dirPath, PDO $db, string $aiBin, string $apiKey, array $videoExts, bool $pendingOnly): int
{
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

    if (empty($videoFiles)) return 0;

    // Find files that need processing
    $toProcess = [];
    foreach ($videoFiles as $vf) {
        $fullPath = $dirPath . '/' . $vf;
        $stmt = $db->prepare("SELECT poster_url FROM folder_posters WHERE path = :p");
        $stmt->execute([':p' => $fullPath]);
        $row = $stmt->fetch();

        if ($pendingOnly) {
            // Only files where regex tried and failed (row exists, poster_url IS NULL)
            if ($row && $row['poster_url'] === null) {
                $toProcess[] = $vf;
            }
        } else {
            // All files without a poster (no row, or poster_url IS NULL)
            // Skip __none__ (human said no) and files with a poster already
            if (!$row || $row['poster_url'] === null) {
                $toProcess[] = $vf;
            }
        }
    }

    if (empty($toProcess)) return 0;

    echo "\n=== " . basename($dirPath) . " ===\n";
    echo "  " . count($toProcess) . " files to process\n";

    // Ask AI for clean titles
    $titles = null;
    if ($aiBin) {
        $titles = askAI($toProcess, $aiBin);
    }
    if ($titles === null) {
        if ($aiBin) echo "  AI failed, falling back to regex.\n";
        $titles = [];
        foreach ($toProcess as $vf) {
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
        if (!$title) continue;

        $posterUrl = searchTMDB($title, $year, $apiKey, $ctx);
        $tmdbId = $posterUrl ? ($posterUrl['id'] ?? null) : null;
        $tmdbTitle = $posterUrl ? ($posterUrl['title'] ?? null) : null;
        $tmdbOverview = $posterUrl ? ($posterUrl['overview'] ?? null) : null;
        $posterUrlStr = $posterUrl ? $posterUrl['poster'] : null;

        if ($posterUrlStr) {
            echo "  OK    " . $fileName . " => " . $tmdbTitle . "\n";
            $found++;
        } else {
            echo "  MISS  " . $fileName . " (searched: " . $title . ")\n";
        }

        try {
            $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
               ->execute([':p' => $fullPath, ':u' => $posterUrlStr, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
        } catch (PDOException $e) { /* ignore */ }

        usleep(250000); // 250ms TMDB rate limit
    }

    echo "  Done: $found found, $skipped skipped, " . (count($toProcess) - $found - $skipped) . " missed\n";
    return count($toProcess);
}

/**
 * Search TMDB for a movie by title (and optional year).
 * Returns {poster, id, title, overview} or null.
 */
function searchTMDB(string $title, ?int $year, string $apiKey, $ctx): ?array
{
    $queries = [$title];
    if ($year) $queries[] = $title . ' ' . $year;

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
                        return [
                            'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                            'id' => $r['id'] ?? null,
                            'title' => $r['title'] ?? $r['name'] ?? null,
                            'overview' => $r['overview'] ?? null,
                        ];
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Verify existing matches in a movies folder.
 * Sends {filename, matched_tmdb_title} pairs to AI, asks if they're correct.
 * Fixes bad matches by re-searching TMDB with AI-suggested titles.
 * @return int Number of files checked
 */
function verifyFolder(string $dirPath, PDO $db, string $aiBin, string $apiKey, array $videoExts): int
{
    // Find video files with a poster (not __none__, not NULL) that haven't been verified
    $items = scandir($dirPath);
    $pairs = []; // [{file, tmdb_title, path}]
    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        if (is_dir($dirPath . '/' . $item)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, $videoExts, true)) continue;

        $fullPath = $dirPath . '/' . $item;
        $stmt = $db->prepare("SELECT poster_url, title, verified FROM folder_posters WHERE path = :p");
        $stmt->execute([':p' => $fullPath]);
        $row = $stmt->fetch();
        // Only verify files that have an auto-matched poster, not yet verified
        if (!$row || !$row['poster_url'] || $row['poster_url'] === '__none__') continue;
        if (!$row['title']) continue;
        if (!empty($row['verified'])) continue; // Already confirmed by AI

        $pairs[] = ['file' => $item, 'tmdb_title' => $row['title'], 'path' => $fullPath];
    }

    if (empty($pairs)) return 0;

    echo "\n=== VERIFY " . basename($dirPath) . " (" . count($pairs) . " matches) ===\n";

    // Ask AI to verify the matches
    $verdicts = askAIVerify($pairs, $aiBin);
    if ($verdicts === null) {
        echo "  AI verify failed, skipping.\n";
        return 0;
    }

    $fixed = 0;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    // Build set of files flagged as bad by AI (match by basename, tolerant)
    $badFiles = [];
    foreach ($verdicts as $v) {
        if (!($v['correct'] ?? true)) {
            $badFiles[$v['file']] = $v;
        }
    }

    // Mark all non-bad files as verified in one go
    $confirmed = 0;
    foreach ($pairs as $p) {
        if (isset($badFiles[$p['file']])) continue;
        try {
            $stmt = $db->prepare("UPDATE folder_posters SET verified = 1 WHERE path = :p AND (verified IS NULL OR verified = 0)");
            $stmt->execute([':p' => $p['path']]);
            if ($stmt->rowCount() > 0) $confirmed++;
        } catch (PDOException $e) { /* ignore */ }
    }

    // Process bad matches
    foreach ($badFiles as $fileName => $v) {

        $fileName = $v['file'];
        $fullPath = $dirPath . '/' . $fileName;
        $betterTitle = $v['suggested_title'] ?? '';
        $betterYear = $v['year'] ?? null;

        if (!$betterTitle) {
            echo "  BAD   " . $fileName . " (was: " . ($v['tmdb_title'] ?? '?') . ") — no suggestion\n";
            continue;
        }

        echo "  FIX   " . $fileName . " (was: " . ($v['tmdb_title'] ?? '?') . ") => searching: " . $betterTitle . "\n";

        // Re-search TMDB with the AI-suggested title
        $result = searchTMDB($betterTitle, $betterYear, $apiKey, $ctx);
        if ($result) {
            echo "        => " . $result['title'] . "\n";
            $fixed++;
            try {
                $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified) VALUES (:p, :u, :i, :t, :o, 1)")
                   ->execute([':p' => $fullPath, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview']]);
            } catch (PDOException $e) { /* ignore */ }
        } else {
            echo "        => still no match for: " . $betterTitle . "\n";
        }

        usleep(250000);
    }

    $bad = count(array_filter($verdicts, fn($v) => !($v['correct'] ?? true)));
    echo "  Verify done: $confirmed confirmed, $bad bad, $fixed fixed\n";
    return count($pairs);
}

/**
 * Ask AI to verify {filename, tmdb_title} pairs.
 * Returns array of {file, tmdb_title, correct, suggested_title, year} or null.
 */
function askAIVerify(array $pairs, string $aiBin): ?array
{
    // Build compact input: just file + matched title
    $input = array_map(fn($p) => ['file' => $p['file'], 'tmdb_title' => $p['tmdb_title']], $pairs);
    $batches = array_chunk($input, 50);
    $allResults = [];

    foreach ($batches as $batchIdx => $batch) {
        echo "  Verifying with AI" . (count($batches) > 1 ? " (batch " . ($batchIdx + 1) . "/" . count($batches) . ")" : "") . "...\n";

        $fileList = json_encode($batch, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu reçois des paires {nom de fichier vidéo, titre TMDB matché automatiquement}.
Vérifie si chaque match est correct (le titre TMDB correspond bien au film du fichier).

Retourne UNIQUEMENT un JSON array (sans markdown, sans code fences), avec pour chaque paire:
{"file": "nom original", "tmdb_title": "titre matché", "correct": true/false, "suggested_title": "meilleur titre pour TMDB", "year": 1999}

Règles:
- correct=true si le titre TMDB est bien le film du fichier (même si la graphie diffère légèrement)
- correct=false si c'est clairement un mauvais match (film différent, album musique, etc.)
- Si correct=false, suggested_title = le bon titre à chercher sur TMDB (avec caractères spéciaux si nécessaire, ex: WALL·E pas Wall-E)
- Si correct=true, suggested_title peut être omis ou vide
- year = année du film (utile pour désambiguïser)

Paires à vérifier:
$fileList
PROMPT;

        $tmpFile = tempnam(sys_get_temp_dir(), 'ai_verify_');
        file_put_contents($tmpFile, $prompt);

        $cmd = escapeshellarg($aiBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        if (!$output) return null;

        $envelope = json_decode($output, true);
        if (!$envelope || !isset($envelope['result'])) return null;

        $text = $envelope['result'];
        $text = preg_replace('/^```(?:json)?\s*/s', '', $text);
        $text = preg_replace('/\s*```$/s', '', $text);

        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            fwrite(STDERR, "  Warning: could not parse AI verify response\n");
            return null;
        }

        $allResults = array_merge($allResults, $parsed);
    }

    return $allResults;
}

// ═══════════════════════════════════════════════════════════════════════════════
// AI ADAPTER — swap this function to change provider
// Interface: string[] filenames in → {file, title, year, skip}[] out
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Ask AI to extract clean movie titles from filenames.
 * Current provider: Claude CLI (Haiku).
 * To switch to API: replace shell_exec with an HTTP call to any LLM API.
 *
 * @param string[] $fileNames Raw filenames
 * @param string $aiBin Path to claude binary
 * @return array|null Array of {file, title, year, skip} or null on failure
 */
function askAI(array $fileNames, string $aiBin): ?array
{
    $allResults = [];
    $batches = array_chunk($fileNames, 50);

    foreach ($batches as $batchIdx => $batch) {
        if (count($batches) > 1) {
            echo "  Asking AI (batch " . ($batchIdx + 1) . "/" . count($batches) . ")...\n";
        } else {
            echo "  Asking AI...\n";
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

        $tmpFile = tempnam(sys_get_temp_dir(), 'ai_prompt_');
        file_put_contents($tmpFile, $prompt);

        $cmd = escapeshellarg($aiBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        if (!$output) return null;

        $envelope = json_decode($output, true);
        if (!$envelope || !isset($envelope['result'])) return null;

        $text = $envelope['result'];
        $text = preg_replace('/^```(?:json)?\s*/s', '', $text);
        $text = preg_replace('/\s*```$/s', '', $text);

        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            fwrite(STDERR, "  Warning: could not parse AI response for batch " . ($batchIdx + 1) . "\n");
            return null;
        }

        $allResults = array_merge($allResults, $parsed);
    }

    return $allResults;
}
