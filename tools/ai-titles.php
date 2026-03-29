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
    echo "       php tools/ai-titles.php --cron      (runs both once)\n";
    echo "       php tools/ai-titles.php --daemon    (loop: poll DB every 10s)\n";
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
$verifyPath = null;
if ($arg === '--pending-path') {
    $pendingPath = realpath($argv[2] ?? '');
    if (!$pendingPath || !is_dir($pendingPath)) {
        fwrite(STDERR, "Error: invalid path for --pending-path\n");
        exit(1);
    }
    $verifyPath = $pendingPath; // verify same path after pending
    $arg = '--pending+verify'; // run both scoped to this path
}
if ($arg === '--verify-path') {
    $verifyPath = realpath($argv[2] ?? '');
    if (!$verifyPath || !is_dir($verifyPath)) {
        fwrite(STDERR, "Error: invalid path for --verify-path\n");
        exit(1);
    }
    $arg = '--verify';
}

// ── Daemon mode: loop forever, poll DB every 10s ──
if ($arg === '--daemon') {
    $lockFile = sys_get_temp_dir() . '/sharebox_ai_daemon.lock';
    $lockFp = fopen($lockFile, 'w');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Daemon already running\n");
        exit(1);
    }
    ai_log('DAEMON start');
    $idleLogged = false;
    while (true) {
        $didWork = false;

        // 1. Pending: regex+TMDB only (no Claude calls — cron handles AI)
        $rows = $db->query("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 1)")->fetchAll();
        if (!empty($rows)) {
            $didWork = true;
            $idleLogged = false;
            ai_log('DAEMON pending | ' . count($rows) . ' entries');
            processPendingEntries($rows, $db, '', $TMDB_API_KEY, $VIDEO_EXTS); // empty $aiBin = no Claude
        }

        // 2. Auto-verify duplicates only (no Claude verify — cron handles it)
        $vRows = $db->query("SELECT path, tmdb_id FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0)")->fetchAll();
        if (!empty($vRows)) {
            $byDirTmdb = [];
            foreach ($vRows as $row) {
                $dir = dirname($row['path']);
                $tid = $row['tmdb_id'] ?? 0;
                if ($tid === 0) continue;
                $byDirTmdb[$dir][$tid][] = $row['path'];
            }
            $autoVerified = 0;
            foreach ($byDirTmdb as $dir => $tmdbGroups) {
                foreach ($tmdbGroups as $tid => $paths) {
                    if (count($paths) > 3) {
                        foreach ($paths as $p) {
                            try { $db->prepare("UPDATE folder_posters SET verified = 1 WHERE path = :p")->execute([':p' => $p]); $autoVerified++; } catch (PDOException $e) {}
                        }
                    }
                }
            }
            if ($autoVerified > 0) { $didWork = true; $idleLogged = false; ai_log('DAEMON auto-verify | ' . $autoVerified . ' entries'); }
        }

        if (!$didWork && !$idleLogged) {
            ai_log('DAEMON idle', false);
            $idleLogged = true;
        }
        sleep(10);
    }
}

// Lock for --cron to prevent parallel runs (crontab + web trigger)
if ($arg === '--cron' || $arg === '--pending+verify') {
    $cronLock = __DIR__ . '/../data/sharebox_ai_cron.lock';
    $cronFp = fopen($cronLock, 'w');
    if (!$cronFp || !flock($cronFp, LOCK_EX | LOCK_NB)) {
        ai_log('CRON skip — already running');
        exit(0);
    }
}

$runModes = match($arg) {
    '--cron' => ['--pending', '--verify'],
    '--pending+verify' => ['--pending', '--verify'],
    default => [$arg],
};

foreach ($runModes as $mode) {

if ($mode === '--pending') {
    // Find entries with poster_url IS NULL, skip those already tried 3+ times
    if ($pendingPath) {
        $stmt = $db->prepare("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3) AND path LIKE :prefix");
        $stmt->execute([':prefix' => $pendingPath . '/%']);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)")->fetchAll();
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
    // Find unverified entries with a poster (scoped to path if --verify-path or --pending-path)
    if ($verifyPath) {
        $stmtV = $db->prepare("SELECT path, poster_url, title, tmdb_id, overview, tmdb_year, tmdb_type FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0) AND path LIKE :prefix");
        $stmtV->execute([':prefix' => $verifyPath . '/%']);
        $rows = $stmtV->fetchAll();
    } else {
        $rows = $db->query("SELECT path, poster_url, title, tmdb_id, overview, tmdb_year, tmdb_type FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0)")->fetchAll();
    }
    ai_log('AI --verify start | unverified=' . count($rows) . ($verifyPath ? ' path=' . basename($verifyPath) : ' (all)'));

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
    // Group entries by parent directory, keep rowid for DB writes (avoids NFC/NFD path mismatch)
    $byDir = [];
    $rowIds = [];  // dir+name => rowid
    foreach ($rows as $row) {
        $path = $row['path'];
        $dir = dirname($path);
        $name = basename($path);
        $byDir[$dir][] = $name;
        $rowIds[$dir . chr(0) . $name] = (int)$row['rowid'];
    }
    // Get rowid for a file, with fuzzy fallback for NFC/NFD mismatch
    $getRowId = function(string $dir, string $name) use (&$rowIds): ?int {
        $key = $dir . chr(0) . $name;
        if (isset($rowIds[$key])) return $rowIds[$key];
        $stripped = preg_replace('/[\x80-\xFF]+/', '', $name);
        foreach ($rowIds as $k => $id) {
            if (!str_starts_with($k, $dir . chr(0))) continue;
            $dbName = substr($k, strlen($dir) + 1);
            if (preg_replace('/[\x80-\xFF]+/', '', $dbName) === $stripped) return $id;
        }
        return null;
    };

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
                    $db->prepare("UPDATE folder_posters SET ai_attempts = 3 WHERE rowid = :id")
                       ->execute([':id' => $getRowId($dir, $n)]);
                } catch (PDOException $e) { /* ignore */ }
            }
            unset($byDir[$dir]);
        }
    }

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    foreach ($byDir as $dir => $names) {
        ai_log('PENDING | dir=' . basename($dir) . ' files=' . count($names));

        // ── Pass 1 : regex extract_title_year() + TMDB (gratuit, rapide) ──
        // Deduplicate: group files by extracted title, one TMDB call per unique title
        $titleToFiles = []; // "Rick and Morty" => [{name, year}, ...]
        $noTitle = [];
        foreach ($names as $n) {
            $meta = extract_title_year($n);
            if (!$meta['title']) { $noTitle[] = $n; continue; }
            $key = $meta['title'] . '|' . ($meta['year'] ?? '');
            $titleToFiles[$key][] = ['name' => $n, 'title' => $meta['title'], 'year' => $meta['year']];
        }

        $found = 0;
        $remaining = $noTitle;
        $tmdbCache = []; // title|year => result or null
        foreach ($titleToFiles as $key => $files) {
            $first = $files[0];
            $result = searchTMDB($first['title'], $first['year'], $apiKey, $ctx);
            usleep(50000);

            foreach ($files as $f) {
                $rowId = $getRowId($dir, $f['name']);
                if ($result && $rowId) {
                    $found++;
                    try {
                        $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 0, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now') WHERE rowid = :id")
                           ->execute([':id' => $rowId, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview'], ':y' => $result['year'] ?? null, ':mt' => $result['type'] ?? null]);
                    } catch (PDOException $e) {}
                } else {
                    $remaining[] = $f['name'];
                }
            }
            if ($result) {
                ai_log('REGEX OK | "' . $first['title'] . '" × ' . count($files) . ' → ' . $result['title'] . ' (id=' . $result['id'] . ')');
            }
        }
        ai_log('REGEX done | dir=' . basename($dir) . ' found=' . $found . '/' . count($names) . ' unique_titles=' . count($titleToFiles) . ' remaining=' . count($remaining));

        // ── Pass 2 : IA pour les restants (coûteux, plus intelligent) ──
        if (!empty($remaining) && $aiBin) {
            $titles = askAI($remaining, $aiBin);
            if ($titles === null) {
                ai_log('AI askAI FAIL | dir=' . basename($dir) . ' → giving up on ' . count($remaining) . ' files');
                // Increment ai_attempts for remaining so we don't retry forever
                foreach ($remaining as $n) {
                    try {
                        $db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE rowid = :id")
                           ->execute([':id' => $getRowId($dir, $n)]);
                    } catch (PDOException $e) {}
                }
            } else {
                // Dedup AI titles: group by title|year, one TMDB call per unique title
                $aiByTitle = []; // "title|year" => [{file, title, year, skip}, ...]
                $aiSkips = [];
                foreach ($titles as $t) {
                    if (!is_array($t) || !isset($t['file'])) continue;
                    if ($t['skip'] ?? false) { $aiSkips[] = $t; continue; }
                    $title = $t['title'] ?? '';
                    if (!$title) continue;
                    $key = $title . '|' . ($t['year'] ?? '');
                    $aiByTitle[$key][] = $t;
                }

                // Handle skips
                foreach ($aiSkips as $t) {
                    $rowId = $getRowId($dir, $t['file']);
                    ai_log('AI SKIP | ' . $t['file']);
                    if ($rowId) {
                        try { $db->prepare("UPDATE folder_posters SET poster_url = '__none__', title = :t, updated_at = datetime('now') WHERE rowid = :id")
                                 ->execute([':id' => $rowId, ':t' => $t['title'] ?? '']); } catch (PDOException $e) {}
                    }
                }

                // Search TMDB once per unique title, apply to all files
                $aiFound = 0;
                foreach ($aiByTitle as $key => $files) {
                    $first = $files[0];
                    $result = searchTMDB($first['title'], $first['year'] ?? null, $apiKey, $ctx);
                    usleep(50000);

                    foreach ($files as $t) {
                        $rowId = $getRowId($dir, $t['file']);
                        if ($result && $rowId) {
                            $aiFound++;
                            try {
                                $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 0, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now') WHERE rowid = :id")
                                   ->execute([':id' => $rowId, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview'], ':y' => $result['year'] ?? null, ':mt' => $result['type'] ?? null]);
                            } catch (PDOException $e) {}
                        } else {
                            if ($rowId) {
                                try {
                                    $db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE rowid = :id")
                                       ->execute([':id' => $rowId]);
                                } catch (PDOException $e) {}
                            }
                        }
                    }
                    if ($result) {
                        ai_log('AI OK | "' . $first['title'] . '" × ' . count($files) . ' → ' . $result['title'] . ' (id=' . $result['id'] . ')');
                    }
                }
                ai_log('AI done | dir=' . basename($dir) . ' ai_found=' . $aiFound . '/' . count($remaining) . ' unique_titles=' . count($aiByTitle));
            }
        } elseif (!empty($remaining)) {
            // Pas d'IA dispo — increment attempts pour ne pas boucler
            foreach ($remaining as $n) {
                try {
                    $db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE rowid = :id")
                       ->execute([':id' => $getRowId($dir, $n)]);
                } catch (PDOException $e) {}
            }
        }

        ai_log('PENDING done | dir=' . basename($dir) . ' total_found=' . $found . '/' . count($names), false);
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
            'tmdb_year' => $row['tmdb_year'] ?? null,
            'tmdb_type' => $row['tmdb_type'] ?? null,
            'tmdb_overview' => $row['overview'] ?? null,
            'path' => $row['path'],
        ];
    }

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    foreach ($byDir as $dir => $pairs) {
        ai_log('AI verify | dir=' . basename($dir) . ' pairs=' . count($pairs));

        $verdicts = askAIVerify($pairs, $aiBin);
        if ($verdicts === null) {
            // Parse failed — mark all as verified to stop retrying
            ai_log('AI verify FAIL | dir=' . basename($dir) . ' → auto-approving ' . count($pairs) . ' entries');
            foreach ($pairs as $p) {
                try { $db->prepare("UPDATE folder_posters SET verified = 1 WHERE path = :p")->execute([':p' => $p['path']]); } catch (PDOException $e) {}
            }
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

        // Fix bad matches: fetch all TMDB candidates first, then batch pick with one AI call
        $fixed = 0;
        $toPick = []; // items needing AI pick: [{file, candidates, path}, ...]
        foreach ($badFiles as $fileName => $v) {
            $fullPath = $dir . '/' . $fileName;
            $betterTitle = $v['suggested_title'] ?? '';
            $betterYear = isset($v['year']) ? (int)$v['year'] : null;

            if (!$betterTitle) {
                ai_log('AI verify BAD | ' . $fileName . ' no suggestion');
                continue;
            }

            $candidates = searchTMDBCandidates($betterTitle, $betterYear, $apiKey, $ctx);
            if (empty($candidates)) {
                ai_log('AI verify MISS | ' . $fileName . ' no TMDB candidates for "' . $betterTitle . '"');
            } elseif (count($candidates) === 1) {
                // Single result — no need for AI pick
                $result = $candidates[0];
                ai_log('AI verify OK | ' . $fileName . ' → ' . $result['title'] . ' (id=' . $result['id'] . ') single');
                $fixed++;
                try {
                    $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified, tmdb_year, tmdb_type) VALUES (:p, :u, :i, :t, :o, 1, :y, :mt)
                                  ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 1, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now')")
                       ->execute([':p' => $fullPath, ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview'], ':y' => $result['year'] ?? null, ':mt' => $result['type'] ?? null]);
                } catch (PDOException $e) {}
            } else {
                $toPick[] = ['file' => $fileName, 'candidates' => $candidates, 'path' => $fullPath];
            }
            usleep(50000);
        }

        // Pick best TMDB candidate for each bad match
        if (!empty($toPick) && $aiBin) {
            // Try batch first, fallback to individual picks
            $picks = [];
            if (count($toPick) > 1) {
                $pickBatches = array_chunk($toPick, 20);
                foreach ($pickBatches as $bi => $pickBatch) {
                    ai_log('AI batch-pick | ' . count($pickBatch) . ' files');
                    $picks = array_merge($picks, askAIBatchPick($pickBatch, $aiBin));
                }
            }
            // Fill in nulls with individual picks (batch failed or single item)
            foreach ($toPick as $i => $item) {
                if (!isset($picks[$i]) || $picks[$i] === null) {
                    $picks[$i] = askAIPickBest($item['file'], $item['candidates'], $aiBin);
                }
            }
            foreach ($toPick as $i => $item) {
                $pickedIdx = $picks[$i] ?? null;
                $result = ($pickedIdx !== null && isset($item['candidates'][$pickedIdx]))
                    ? $item['candidates'][$pickedIdx]
                    : $item['candidates'][0]; // fallback to first
                $verified = ($pickedIdx !== null) ? 1 : 0;
                ai_log('AI picked | ' . $item['file'] . ' → ' . $result['title'] . ' (id=' . $result['id'] . ')' . ($pickedIdx === null ? ' FALLBACK' : ''));
                $fixed++;
                try {
                    $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified, tmdb_year, tmdb_type) VALUES (:p, :u, :i, :t, :o, :v, :y, :mt)
                                  ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = :v, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now')")
                       ->execute([':p' => $item['path'], ':u' => $result['poster'], ':i' => $result['id'], ':t' => $result['title'], ':o' => $result['overview'], ':v' => $verified, ':y' => $result['year'] ?? null, ':mt' => $result['type'] ?? null]);
                } catch (PDOException $e) {}
            }
        }

        // Mark unfixed bad entries as verified=1 + ai_attempts=3 to stop all retrying
        $unfixed = count($badFiles) - $fixed;
        if ($unfixed > 0) {
            foreach ($badFiles as $fileName => $v) {
                $fullPath = $dir . '/' . $fileName;
                try { $db->prepare("UPDATE folder_posters SET verified = 1, ai_attempts = 3 WHERE path = :p")->execute([':p' => $fullPath]); } catch (PDOException $e) {}
            }
            ai_log('AI verify unfixed | ' . $unfixed . ' entries auto-approved + locked');
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
            $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_type) VALUES (:p, :u, :i, :t, :o, :y, :mt)
              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now')")
               ->execute([':p' => $fullPath, ':u' => $posterUrlStr, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview, ':y' => $posterUrl['year'] ?? null, ':mt' => $posterUrl['type'] ?? null]);
        } catch (PDOException $e) { poster_log('DB error folder-write | ' . $fileName . ' → ' . $e->getMessage()); }

        usleep(50000); // 250ms TMDB rate limit
    }

    ai_log('AI folder done | dir=' . basename($dirPath) . ' found=' . $found . ' skipped=' . $skipped . ' missed=' . (count($toProcess) - $found - $skipped), false);
    return count($toProcess);
}

// searchTMDB and searchTMDBCandidates — use shared functions from functions.php
function searchTMDB(string $title, ?int $year, string $apiKey, $ctx): ?array {
    ai_log('[TMDB] search "' . $title . '"' . ($year ? " ($year)" : ''), false);
    return tmdb_search($title, $year, $apiKey, $ctx, ['multi', 'tv', 'movie']);
}
function searchTMDBCandidates(string $title, ?int $year, string $apiKey, $ctx): array {
    ai_log('[TMDB] candidates "' . $title . '"' . ($year ? " ($year)" : ''), false);
    return tmdb_search_candidates($title, $year, $apiKey, $ctx);
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
/**
 * Ask AI to pick the best TMDB candidate for multiple files in one call.
 * @param array $items [{file, candidates, path}, ...]
 * @param string $aiBin Path to claude binary
 * @return array Index-aligned array of picked candidate indices (null if failed)
 */
function askAIBatchPick(array $items, string $aiBin): array
{
    $batch = [];
    foreach ($items as $item) {
        $compact = array_map(fn($c, $i) => [
            'idx' => $i, 'title' => $c['title'], 'year' => $c['year'],
            'type' => $c['type'], 'overview' => $c['overview'],
        ], $item['candidates'], array_keys($item['candidates']));
        $batch[] = ['file' => $item['file'], 'candidates' => $compact];
    }

    $batchJson = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $prompt = <<<PROMPT
Pour chaque fichier/dossier, choisis le résultat TMDB qui correspond le mieux parmi les candidats.
Réponds UNIQUEMENT un JSON array (sans markdown, sans code fences) :
[{"file": "nom", "idx": N}, ...]

Indices: INTEGRALE/COLLECTION = série principale. Année dans le nom = privilégier cette année. Animation Disney = chercher le film d'animation, pas le remake live-action.

$batchJson
PROMPT;

    $tmpFile = tempnam(sys_get_temp_dir(), 'ai_bpick_');
    file_put_contents($tmpFile, $prompt);
    $cmd = escapeshellarg($aiBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
    ai_log('[CLAUDE] batch-pick ' . count($items) . ' files', false);
    $output = shell_exec($cmd);
    @unlink($tmpFile);

    if (!$output) { ai_log('AI RAW | batch-pick → empty output'); return array_fill(0, count($items), null); }
    $envelope = json_decode($output, true);
    if (!$envelope || !isset($envelope['result'])) { ai_log('AI RAW | batch-pick → no result key'); return array_fill(0, count($items), null); }
    ai_log('[CLAUDE-OK] batch-pick → ' . substr($envelope['result'], 0, 200), false);

    $parsed = extractJsonArray($envelope['result']);
    if (!is_array($parsed)) { ai_log('AI RAW | batch-pick PARSE FAIL → ' . substr($envelope['result'], 0, 300)); return array_fill(0, count($items), null); }

    // Map results back by filename
    $byFile = [];
    foreach ($parsed as $p) {
        if (is_array($p) && isset($p['file'], $p['idx'])) {
            $byFile[$p['file']] = (int)$p['idx'];
        }
    }

    $result = [];
    foreach ($items as $item) {
        $idx = $byFile[$item['file']] ?? null;
        $result[] = ($idx !== null && $idx >= 0 && $idx < count($item['candidates'])) ? $idx : null;
    }
    return $result;
}

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
    ai_log('[CLAUDE] pick-best "' . $fileName . '" prompt_len=' . strlen($prompt) . ' file_len=' . filesize($tmpFile), false);
    $cmd = escapeshellarg($aiBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    @unlink($tmpFile);

    if (!$output) { ai_log('AI RAW | pick → empty output'); return null; }
    $envelope = json_decode($output, true);
    if (!$envelope || !isset($envelope['result'])) { ai_log('AI RAW | pick → no result key: ' . substr($output, 0, 200)); return null; }
    ai_log('[CLAUDE-OK] pick → ' . substr($envelope['result'], 0, 200), false);

    $text = $envelope['result'];
    // Extraire {"idx": N} même si l'IA ajoute du texte ou des code fences autour
    if (preg_match('/\{\s*"idx"\s*:\s*(-?\d+)\s*\}/', $text, $m)) {
        $idx = (int)$m[1];
        return ($idx >= 0 && $idx < count($candidates)) ? $idx : null;
    }
    ai_log('AI RAW | pick PARSE FAIL → ' . substr($text, 0, 200));
    return null;
}

/**
 * Ask AI to verify {filename, tmdb_title} pairs.
 * Returns array of {file, tmdb_title, correct, suggested_title, year} or null.
 */
function askAIVerify(array $pairs, string $aiBin): ?array
{
    // Build compact input: just file + matched title
    $input = array_map(fn($p) => [
        'file' => $p['file'],
        'tmdb_title' => $p['tmdb_title'],
        'tmdb_year' => $p['tmdb_year'] ?? null,
        'tmdb_type' => $p['tmdb_type'] ?? null,
        'tmdb_overview' => $p['tmdb_overview'] ?? null,
    ], $pairs);
    $batches = array_chunk($input, 50);
    $allResults = [];

    foreach ($batches as $batchIdx => $batch) {
        ai_log('AI verifying' . (count($batches) > 1 ? ' batch ' . ($batchIdx + 1) . '/' . count($batches) : '') . '...', false);

        $fileList = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $prompt = <<<PROMPT
Tu reçois des paires {nom de fichier/dossier, titre TMDB, année, type, résumé}.
Vérifie si chaque match est correct.

Retourne UNIQUEMENT un JSON array (sans markdown, sans code fences), avec pour chaque paire:
{"file": "nom original", "tmdb_title": "titre matché", "correct": true/false, "suggested_title": "meilleur titre pour TMDB", "year": 1999}

Règles:
- correct=true si le titre TMDB correspond au contenu du fichier/dossier
- Vérifie EN PRIORITÉ :
  1. Le titre correspond au contenu du fichier (même traduit, même graphie différente)
  2. L'année correspond si visible dans le nom du fichier
  3. Le type est cohérent (série vs film — un dossier avec S01/Season = série)
  4. Le résumé décrit bien le contenu attendu (si disponible)
- correct=false UNIQUEMENT si c'est clairement un mauvais match

Cas spéciaux — NE PAS marquer comme incorrect:
- Les dossiers de saison (S01, S02, Season 1, Saison 2...) matchés à "Saison N" : c'est CORRECT
- Les collections/sagas (INTEGRALE, COLLECTION, COMPLETE) matchées à un titre de saga : c'est CORRECT
- Un titre traduit (ex: "Despicable Me" → "Moi, moche et méchant") : c'est CORRECT
- Une légère différence de graphie ou de sous-titre : c'est CORRECT

Cas à marquer comme incorrect:
- Un dossier matché à un film/série complètement différent
- Un dossier générique (Films, Movie, Covers) matché à un contenu spécifique
- Une série matchée à un épisode spécial ou un film dérivé au lieu de la série principale
- Le résumé est incohérent avec le contenu du fichier

Si correct=false : suggested_title = le bon titre à chercher sur TMDB
Si correct=true : suggested_title peut être omis

Paires à vérifier:
$fileList
PROMPT;

        $tmpFile = tempnam(sys_get_temp_dir(), 'ai_verify_');
        file_put_contents($tmpFile, $prompt);

        $cmd = escapeshellarg($aiBin) . ' -p --model haiku --output-format json < ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        ai_log('[CLAUDE] verify batch ' . ($batchIdx + 1) . '/' . count($batches) . ' (' . count($batch) . ' pairs)', false);
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        if (!$output) { ai_log('AI RAW | verify batch ' . ($batchIdx + 1) . ' → empty output'); return null; }

        $envelope = json_decode($output, true);
        if (!$envelope || !isset($envelope['result'])) { ai_log('AI RAW | verify batch ' . ($batchIdx + 1) . ' → no result key: ' . substr($output, 0, 200)); return null; }
        ai_log('[CLAUDE-OK] verify batch ' . ($batchIdx + 1) . ' → ' . substr($envelope['result'], 0, 200), false);

        $text = $envelope['result'];
        $parsed = extractJsonArray($text);
        if (!is_array($parsed)) {
            ai_log('AI RAW | verify batch ' . ($batchIdx + 1) . ' PARSE FAIL → ' . substr($text, 0, 300));
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

        $fileList = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

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
        ai_log('[CLAUDE] askAI batch ' . ($batchIdx + 1) . '/' . count($batches) . ' (' . count($batch) . ' files)', false);
        $output = shell_exec($cmd);
        @unlink($tmpFile);

        if (!$output) { ai_log('AI RAW | askAI batch ' . ($batchIdx + 1) . ' → empty output'); return null; }

        $envelope = json_decode($output, true);
        if (!$envelope || !isset($envelope['result'])) { ai_log('AI RAW | askAI batch ' . ($batchIdx + 1) . ' → no result key: ' . substr($output, 0, 200)); return null; }
        ai_log('[CLAUDE-OK] askAI batch ' . ($batchIdx + 1) . ' → ' . substr($envelope['result'], 0, 200), false);

        $text = $envelope['result'];
        $parsed = extractJsonArray($text);
        if (!is_array($parsed)) {
            ai_log('AI RAW | askAI batch ' . ($batchIdx + 1) . ' PARSE FAIL → ' . substr($text, 0, 300));
            return null;
        }

        $allResults = array_merge($allResults, $parsed);
    }

    return $allResults;
}
