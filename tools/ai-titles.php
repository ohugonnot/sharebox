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
    // Fallback: check common install locations
    foreach (['/home/copain/.local/bin/claude', '/usr/local/bin/claude'] as $p) {
        if (is_executable($p)) { $AI_BIN = $p; break; }
    }
}
if (!$AI_BIN) {
    fwrite(STDERR, "Warning: claude CLI not found, will use regex fallback only\n");
}

// --pending-path /some/dir → pending limited to a path prefix (used by background trigger)
$pendingPath = null;
if ($arg === '--pending-path') {
    $pendingPath = realpath($argv[2] ?? '');
    if (!$pendingPath || !is_dir($pendingPath)) {
        fwrite(STDERR, "Error: invalid path for --pending-path\n");
        exit(1);
    }
    $arg = '--pending'; // fall through to --pending logic with filter
}

$runModes = ($arg === '--cron') ? ['--pending', '--verify'] : [$arg];

foreach ($runModes as $mode) {

if ($mode === '--pending') {
    // Find entries with poster_url IS NULL, skip those already tried 3+ times
    if ($pendingPath) {
        $stmt = $db->prepare("SELECT path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3) AND path LIKE :prefix");
        $stmt->execute([':prefix' => $pendingPath . '/%']);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query("SELECT path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)")->fetchAll();
    }
    ai_log('AI --pending start | entries=' . count($rows) . ($pendingPath ? ' path=' . basename($pendingPath) : ' (all)'));
    if (empty($rows)) {
        ai_log('AI --pending | nothing pending', false);
    } else {
        processPendingEntries($rows, $db, $AI_BIN, $TMDB_API_KEY, $VIDEO_EXTS);
    }
} elseif ($mode === '--all') {
    // Process all movies-tagged folders (full rescan, not just pending)
    $rows = $db->query("SELECT DISTINCT path FROM folder_posters WHERE folder_type = 'movies'")->fetchAll();
    if (empty($rows)) {
        ai_log('AI --all | no folders tagged as movies', false);
    } else {
        foreach ($rows as $row) {
            if (is_dir($row['path'])) {
                processFolder($row['path'], $db, $AI_BIN, $TMDB_API_KEY, $VIDEO_EXTS, false);
            }
        }
    }
} elseif ($mode === '--verify') {
    if (!$AI_BIN) {
        fwrite(STDERR, "Error: --verify requires AI (claude CLI not found)\n");
        exit(1);
    }
    // Find ALL unverified entries with a poster (across all shared folders)
    $rows = $db->query("SELECT path, poster_url, title, tmdb_id FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0)")->fetchAll();
    ai_log('AI --verify start | unverified=' . count($rows));

    // Auto-verify duplicates: if >3 entries in the same dir share the same tmdb_id,
    // it's clearly episodes of the same show — no need to ask AI
    $byDirTmdb = []; // dir => tmdb_id => [paths]
    foreach ($rows as $row) {
        $dir = dirname($row['path']);
        $tid = $row['tmdb_id'] ?? 0;
        if ($tid === 0) continue; // Don't group entries with unknown tmdb_id
        $byDirTmdb[$dir][$tid][] = $row['path'];
    }
    $autoVerified = 0;
    $skipPaths = [];
    foreach ($byDirTmdb as $dir => $tmdbGroups) {
        foreach ($tmdbGroups as $tid => $paths) {
            if (count($paths) > 3) {
                // All clearly the same show — auto-verify without AI
                foreach ($paths as $p) {
                    try {
                        $db->prepare("UPDATE folder_posters SET verified = 1 WHERE path = :p")->execute([':p' => $p]);
                        $autoVerified++;
                        $skipPaths[$p] = true;
                    } catch (PDOException $e) { /* ignore */ }
                }
            }
        }
    }
    if ($autoVerified > 0) {
        ai_log('AI auto-verify | ' . $autoVerified . ' entries (>3 same tmdb_id in same dir)');
    }

    // Remove auto-verified from the list before sending to AI
    $rows = array_filter($rows, fn($r) => !isset($skipPaths[$r['path']]));

    if (empty($rows)) {
        ai_log('AI --verify | all matches correct', false);
    } else {
        verifyEntries(array_values($rows), $db, $AI_BIN, $TMDB_API_KEY);
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
 * Log to stdout with timestamp (for ai-titles.log) and to poster.log.
 * Ensures ai-titles.log is parseable with same format as other logs.
 */
function ai_log(string $msg, bool $toPosterLog = true): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    if ($toPosterLog) poster_log($msg);
}

/**
 * Process pending entries (poster_url IS NULL) from any shared folder.
 * Groups by parent directory, filters out dirs without video content,
 * sends batch to AI, retries TMDB. Increments ai_attempts on miss.
 */
function processPendingEntries(array $rows, PDO $db, string $aiBin, string $apiKey, array $videoExts): void
{
    // Group entries by parent directory
    $byDir = [];
    foreach ($rows as $row) {
        $path = $row['path'];
        $dir = dirname($path);
        $name = basename($path);
        $byDir[$dir][] = $name;
    }

    // Filter: only keep directories that contain at least one video file or subfolder
    foreach ($byDir as $dir => $names) {
        if (!is_dir($dir)) { unset($byDir[$dir]); continue; }
        $hasMedia = false;
        $items = @scandir($dir);
        if ($items === false) { unset($byDir[$dir]); continue; }
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            // A subfolder counts (it's a series folder with subfolders)
            if (is_dir($dir . '/' . $item)) { $hasMedia = true; break; }
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $videoExts, true)) { $hasMedia = true; break; }
        }
        if (!$hasMedia) {
            // No media content — mark these entries as given up
            foreach ($names as $n) {
                try {
                    $db->prepare("UPDATE folder_posters SET ai_attempts = 3 WHERE path = :p")
                       ->execute([':p' => $dir . '/' . $n]);
                } catch (PDOException $e) { /* ignore */ }
            }
            unset($byDir[$dir]);
        }
    }

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    foreach ($byDir as $dir => $names) {
        ai_log('AI pending | dir=' . basename($dir) . ' files=' . count($names));

        // Ask AI for clean titles
        $titles = null;
        if ($aiBin) {
            $titles = askAI($names, $aiBin);
        }
        if ($titles === null) {
            if ($aiBin) { ai_log('AI askAI FAIL | dir=' . basename($dir) . ' → regex fallback'); }
            $titles = [];
            foreach ($names as $n) {
                $meta = extract_title_year($n);
                $titles[] = ['file' => $n, 'title' => $meta['title'], 'year' => $meta['year'], 'skip' => false];
            }
        }

        $found = 0;
        foreach ($titles as $t) {
            $name = $t['file'];
            $fullPath = $dir . '/' . $name;

            if ($t['skip'] ?? false) {
                ai_log('AI SKIP | ' . $name);
                try {
                    $db->prepare("INSERT INTO folder_posters (path, poster_url, title) VALUES (:p, '__none__', :t)
                                  ON CONFLICT(path) DO UPDATE SET poster_url = '__none__', title = :t, updated_at = datetime('now')")
                       ->execute([':p' => $fullPath, ':t' => $t['title'] ?? '']);
                } catch (PDOException $e) { poster_log('DB error SKIP | ' . $name . ' → ' . $e->getMessage()); }
                continue;
            }

            $title = $t['title'] ?? '';
            if (!$title) continue;

            $result = searchTMDB($title, $t['year'] ?? null, $apiKey, $ctx);
            if ($result) {
                ai_log('AI OK | ' . $name . ' → ' . $result['title'] . ' (id=' . $result['id'] . ')');
                $found++;
                try {
                    $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)
                                  ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, updated_at = datetime('now')")
                       ->execute([':p' => $fullPath, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview']]);
                } catch (PDOException $e) { poster_log('DB error OK | ' . $name . ' → ' . $e->getMessage()); }
            } else {
                ai_log('AI MISS | ' . $name . ' searched="' . $title . '"');
                // Increment ai_attempts so we give up after 3 tries
                try {
                    $db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE path = :p")
                       ->execute([':p' => $fullPath]);
                } catch (PDOException $e) { poster_log('DB error MISS | ' . $name . ' → ' . $e->getMessage()); }
            }

            usleep(250000);
        }

        ai_log('AI done | dir=' . basename($dir) . ' found=' . $found . '/' . count($names), false);
    }
}

/**
 * Verify unverified entries across all shared folders.
 * Sends {name, tmdb_title} pairs to AI, fixes bad matches.
 */
function verifyEntries(array $rows, PDO $db, string $aiBin, string $apiKey): void
{
    // Group by parent directory
    $byDir = [];
    foreach ($rows as $row) {
        $dir = dirname($row['path']);
        $byDir[$dir][] = [
            'file' => basename($row['path']),
            'tmdb_title' => $row['title'],
            'path' => $row['path'],
        ];
    }

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    foreach ($byDir as $dir => $pairs) {
        ai_log('AI verify | dir=' . basename($dir) . ' pairs=' . count($pairs));

        $verdicts = askAIVerify($pairs, $aiBin);
        if ($verdicts === null) {
            ai_log('AI verify FAIL | dir=' . basename($dir) . ' → skipping');
            continue;
        }

        // Build bad files set
        $badFiles = [];
        foreach ($verdicts as $v) {
            if (!($v['correct'] ?? true)) {
                $badFiles[$v['file']] = $v;
            }
        }

        // Mark non-bad as verified
        $confirmed = 0;
        foreach ($pairs as $p) {
            if (isset($badFiles[$p['file']])) continue;
            try {
                $stmt = $db->prepare("UPDATE folder_posters SET verified = 1 WHERE path = :p AND (verified IS NULL OR verified = 0)");
                $stmt->execute([':p' => $p['path']]);
                if ($stmt->rowCount() > 0) $confirmed++;
            } catch (PDOException $e) { poster_log('DB error verify-confirm | ' . $p['file'] . ' → ' . $e->getMessage()); }
        }

        // Fix bad matches
        $fixed = 0;
        foreach ($badFiles as $fileName => $v) {
            $fullPath = $dir . '/' . $fileName;
            $betterTitle = $v['suggested_title'] ?? '';
            $betterYear = $v['year'] ?? null;

            if (!$betterTitle) {
                ai_log('AI verify BAD | ' . $fileName . ' was="' . ($v['tmdb_title'] ?? '?') . '" no suggestion');
                continue;
            }

            ai_log('AI verify FIX | ' . $fileName . ' was="' . ($v['tmdb_title'] ?? '?') . '" → search="' . $betterTitle . '"');

            // Pass 2 : chercher tous les candidats TMDB puis demander à l'IA de choisir le bon
            $candidates = searchTMDBCandidates($betterTitle, $betterYear, $apiKey, $ctx);
            if (empty($candidates)) {
                ai_log('AI verify MISS | ' . $fileName . ' no TMDB candidates for "' . $betterTitle . '"');
            } elseif (count($candidates) === 1) {
                $result = $candidates[0];
                ai_log('AI verify OK | ' . $fileName . ' → ' . $result['title'] . ' (id=' . $result['id'] . ') single result');
                $fixed++;
                try {
                    $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified) VALUES (:p, :u, :i, :t, :o, 1)
                                  ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 1, updated_at = datetime('now')")
                       ->execute([':p' => $fullPath, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview']]);
                } catch (PDOException $e) { poster_log('DB error verify-fix | ' . $fileName . ' → ' . $e->getMessage()); }
            } else {
                ai_log('AI pick | ' . $fileName . ' candidates=' . count($candidates));
                $picked = askAIPickBest($fileName, $candidates, $aiBin);
                if ($picked !== null) {
                    $result = $candidates[$picked];
                    ai_log('AI picked | ' . $fileName . ' → ' . $result['title'] . ' (id=' . $result['id'] . ') idx=' . $picked);
                    $fixed++;
                    try {
                        $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified) VALUES (:p, :u, :i, :t, :o, 1)
                                      ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 1, updated_at = datetime('now')")
                           ->execute([':p' => $fullPath, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview']]);
                    } catch (PDOException $e) { poster_log('DB error pick | ' . $fileName . ' → ' . $e->getMessage()); }
                } else {
                    $result = $candidates[0];
                    ai_log('AI pick FAIL | ' . $fileName . ' fallback=' . $result['title'] . ' (id=' . $result['id'] . ')');
                    $fixed++;
                    try {
                        $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified) VALUES (:p, :u, :i, :t, :o, 0)
                                      ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 0, updated_at = datetime('now')")
                           ->execute([':p' => $fullPath, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview']]);
                    } catch (PDOException $e) { poster_log('DB error pick-fallback | ' . $fileName . ' → ' . $e->getMessage()); }
                }
            }

            usleep(250000);
        }

        ai_log('AI verify done | dir=' . basename($dir) . ' confirmed=' . $confirmed . ' bad=' . count($badFiles) . ' fixed=' . $fixed);
    }
}

/**
 * Process a single movies folder (used by --all mode).
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

    ai_log('AI folder | dir=' . basename($dirPath) . ' files=' . count($toProcess));

    // Ask AI for clean titles
    $titles = null;
    if ($aiBin) {
        $titles = askAI($toProcess, $aiBin);
    }
    if ($titles === null) {
        if ($aiBin) ai_log('AI askAI FAIL | dir=' . basename($dirPath) . ' → regex fallback');
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
            ai_log('AI SKIP | ' . $fileName, false);
            $skipped++;
            try {
                $db->prepare("INSERT INTO folder_posters (path, poster_url, title) VALUES (:p, '__none__', :t)
              ON CONFLICT(path) DO UPDATE SET poster_url = '__none__', title = :t, updated_at = datetime('now')")
                   ->execute([':p' => $fullPath, ':t' => $t['title'] ?? '']);
            } catch (PDOException $e) { poster_log('DB error folder-skip | ' . $fileName . ' → ' . $e->getMessage()); }
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
            ai_log('AI OK | ' . $fileName . ' → ' . $tmdbTitle . ' (id=' . $tmdbId . ')', false);
            $found++;
        } else {
            ai_log('AI MISS | ' . $fileName . ' searched="' . $title . '"', false);
        }

        try {
            $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)
              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, updated_at = datetime('now')")
               ->execute([':p' => $fullPath, ':u' => $posterUrlStr, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
        } catch (PDOException $e) { poster_log('DB error folder-write | ' . $fileName . ' → ' . $e->getMessage()); }

        usleep(250000); // 250ms TMDB rate limit
    }

    ai_log('AI folder done | dir=' . basename($dirPath) . ' found=' . $found . ' skipped=' . $skipped . ' missed=' . (count($toProcess) - $found - $skipped), false);
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
        // multi d'abord (séries + films), puis tv, puis movie en fallback
        $urls = [
            "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
            "https://api.themoviedb.org/3/search/tv?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
            "https://api.themoviedb.org/3/search/movie?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
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
 * Search TMDB and return ALL candidates (up to 8) for AI disambiguation.
 * @return array[] Array of {id, title, year, type, overview, poster}
 */
function searchTMDBCandidates(string $title, ?int $year, string $apiKey, $ctx): array
{
    $candidates = [];
    $seenIds = [];
    $encoded = urlencode($title);
    $urls = [
        "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
        "https://api.themoviedb.org/3/search/tv?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
        "https://api.themoviedb.org/3/search/movie?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
    ];
    if ($year) {
        $urls[] = "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query=" . urlencode($title . ' ' . $year) . "&language=fr&page=1";
    }
    foreach ($urls as $searchUrl) {
        $resp = @file_get_contents($searchUrl, false, $ctx);
        $data = $resp ? json_decode($resp, true) : null;
        if (!$data || empty($data['results'])) continue;
        foreach ($data['results'] as $r) {
            if (empty($r['poster_path']) || isset($seenIds[$r['id']])) continue;
            $seenIds[$r['id']] = true;
            $candidates[] = [
                'id' => $r['id'],
                'title' => $r['title'] ?? $r['name'] ?? '?',
                'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                'type' => $r['media_type'] ?? ($r['first_air_date'] ?? false ? 'tv' : 'movie'),
                'overview' => substr($r['overview'] ?? '', 0, 150),
                'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
            ];
            if (count($candidates) >= 15) break 2;
        }
        usleep(250000);
    }
    return $candidates;
}

/**
 * Extract a JSON array from AI text that may contain code fences or extra commentary.
 * Finds outermost [ ... ] bracket pair, ignoring surrounding prose.
 */
function extractJsonArray(string $text): ?array
{
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start === false || $end === false || $end <= $start) return null;
    $json = substr($text, $start, $end - $start + 1);
    $parsed = json_decode($json, true);
    return is_array($parsed) ? $parsed : null;
}

/**
 * Ask AI to pick the best TMDB candidate from a list.
 * Uses a temp file for the prompt (no shell injection risk).
 * @param string $fileName Original filename/folder name
 * @param array $candidates TMDB search results
 * @param string $aiBin Path to claude binary
 * @return int|null Selected candidate index (0-based) or null
 */
function askAIPickBest(string $fileName, array $candidates, string $aiBin): ?int
{
    $compact = array_map(fn($c, $i) => [
        'idx' => $i,
        'title' => $c['title'],
        'year' => $c['year'],
        'type' => $c['type'],
        'overview' => $c['overview'],
    ], $candidates, array_keys($candidates));

    $candidateList = json_encode($compact, JSON_UNESCAPED_UNICODE);

    $prompt = <<<PROMPT
Fichier/dossier torrent : "$fileName"

Choisis le résultat TMDB qui correspond le mieux. Réponds UNIQUEMENT {"idx": N} sans aucune explication.

Indices: INTEGRALE/COLLECTION = série principale (pas un film dérivé). S01/Saison = série TV. Année dans le nom = privilégier cette année.

Candidats :
$candidateList
PROMPT;

    $tmpFile = tempnam(sys_get_temp_dir(), 'ai_pick_');
    file_put_contents($tmpFile, $prompt);
    $cmd = escapeshellarg($aiBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    @unlink($tmpFile);

    if (!$output) return null;
    $envelope = json_decode($output, true);
    if (!$envelope || !isset($envelope['result'])) return null;

    $text = $envelope['result'];
    // Extraire {"idx": N} même si l'IA ajoute du texte ou des code fences autour
    if (preg_match('/\{\s*"idx"\s*:\s*(-?\d+)\s*\}/', $text, $m)) {
        $idx = (int)$m[1];
        return ($idx >= 0 && $idx < count($candidates)) ? $idx : null;
    }
    return null;
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
        ai_log('AI verifying' . (count($batches) > 1 ? ' batch ' . ($batchIdx + 1) . '/' . count($batches) : '') . '...', false);

        $fileList = json_encode($batch, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu reçois des paires {nom de fichier/dossier, titre TMDB matché automatiquement}.
Vérifie si chaque match est correct.

Retourne UNIQUEMENT un JSON array (sans markdown, sans code fences), avec pour chaque paire:
{"file": "nom original", "tmdb_title": "titre matché", "correct": true/false, "suggested_title": "meilleur titre pour TMDB", "year": 1999}

Règles:
- correct=true si le titre TMDB correspond au contenu du fichier/dossier (même si la graphie diffère, même si c'est le titre traduit)
- correct=false UNIQUEMENT si c'est clairement un mauvais match (film totalement différent, série sans rapport)

Cas spéciaux — NE PAS marquer comme incorrect:
- Les dossiers de saison (S01, S02, Season 1, Saison 2...) matchés à "Saison N" : c'est CORRECT, ce sont des posters de saison TMDB
- Les collections/sagas (INTEGRALE, COLLECTION, COMPLETE) matchées à un titre de saga : c'est CORRECT
- Un titre traduit (ex: "Despicable Me" → "Moi, moche et méchant") : c'est CORRECT
- Une légère différence de graphie ou de sous-titre : c'est CORRECT

Cas à marquer comme incorrect:
- Un dossier matché à un film/série complètement différent (ex: "Vol 1" → "Kill Bill")
- Un dossier générique (Films, Movie, Covers) matché à un contenu spécifique
- Une série matchée à un épisode spécial ou un film dérivé au lieu de la série principale

Si correct=false : suggested_title = le bon titre à chercher sur TMDB
Si correct=true : suggested_title peut être omis

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
        $parsed = extractJsonArray($text);
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
            ai_log('AI asking batch ' . ($batchIdx + 1) . '/' . count($batches) . '...', false);
        } else {
            ai_log('AI asking...', false);
        }

        $fileList = json_encode($batch, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu reçois une liste de noms de fichiers ou dossiers vidéo provenant de torrents.
Pour chaque entrée, extrais le titre propre du film ou de la série pour une recherche TMDB.

Retourne UNIQUEMENT un JSON array (sans markdown, sans code fences), avec pour chaque entrée:
{"file": "nom original", "title": "titre propre", "year": 1999, "skip": false}

Règles d'extraction:
- Retire les tags techniques : codec (x264, HEVC, AVC), résolution (1080p, 2160p, 4K), source (BluRay, WEB-DL, DVDRip), audio (AAC, DTS, FLAC, AC3), HDR, 10bit, etc.
- Retire les tags de release : groupe (-AMB3R, -QTZ, RCVR), REMUX, REPACK, REMASTERED
- Retire les tags site entre crochets : [Torrent911.com], [YGG], etc.
- Retire les tags langue : MULTI, VFF, VF, VOSTFR, FRENCH, MULTi, SUBFRENCH, TRUEFRENCH
- Retire la numérotation de classement en début : "N° 057 - ", "01 - ", etc.
- Retire les noms de studio en préfixe : "Walt Disney - ", "Pixar - ", etc.
- Retire les extensions de fichier : .mkv, .avi, .mp4, etc.
- GARDE les noms de collections : INTEGRALE, COLLECTION → extrais le titre de la série/collection
- GARDE les noms de saisons : "Season 1", "Saison 02", "S01" → extrais le titre de la série
- TRADUIS le titre en français quand c'est un film/série connu (ex: "Despicable Me" → "Moi, moche et méchant", "The Walking Dead" reste "The Walking Dead")
- year = année du film si détectable dans le nom, null sinon

Cas particuliers pour skip:
- skip=true UNIQUEMENT pour : bonus, making-of, featurettes, bandes-annonces, samples, fichiers NFO
- skip=false pour : courts-métrages, OVA, films, collections, intégrales, saisons, épisodes
- En cas de doute, skip=false (mieux vaut chercher que rater)

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
        $parsed = extractJsonArray($text);
        if (!is_array($parsed)) {
            fwrite(STDERR, "  Warning: could not parse AI response for batch " . ($batchIdx + 1) . "\n");
            return null;
        }

        $allResults = array_merge($allResults, $parsed);
    }

    return $allResults;
}
