#!/usr/bin/env php
<?php
/**
 * TMDB poster matching via regex + API.
 *
 * Usage: php tools/tmdb-worker.php
 *
 * Flow:
 *   1. ?posters=1 (web, instant) inserts folders with poster_url=NULL
 *   2. This script finds NULLs, extracts titles with regex, searches TMDB
 *   3. Sets verified=60 (raw match) or verified=70 (auto-verified bulk)
 *   4. Entries below verified=90 are re-checked by /tmdb-scan Claude skill
 *   5. Respects human choices: __none__ = user disabled poster, never overwrite
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$TMDB_API_KEY = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
if (!$TMDB_API_KEY) {
    fwrite(STDERR, "Error: TMDB_API_KEY not configured in config.php\n");
    exit(1);
}

$VIDEO_EXTS = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];

// Two separate connections: reader (shared lock) and writer (exclusive lock).
// SQLite WAL can't promote shared→exclusive on the same connection when a
// statement cursor is still open, causing "database is locked" on every UPDATE.
$dbOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$dbRead = new PDO('sqlite:' . DB_PATH, null, null, $dbOpts);
$dbRead->exec('PRAGMA journal_mode=WAL');
$dbRead->exec('PRAGMA busy_timeout = 10000');

$db = new PDO('sqlite:' . DB_PATH, null, null, $dbOpts);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout = 30000');

// ── Phase 0: Discover folders recursively and populate folder_posters ──
// Crawls BASE_PATH, respects TMDB_MIN_DEPTH and HIDDEN_DIRS.
// Only inserts folders that don't already exist in the DB.
function discover_folders(PDO $db): int {
    $basePath = rtrim(BASE_PATH, '/');
    $minDepth = defined('TMDB_MIN_DEPTH') ? (int)TMDB_MIN_DEPTH : 0;
    $hiddenDirs = defined('HIDDEN_DIRS') ? HIDDEN_DIRS : [];
    $inserted = 0;

    $stmt = $db->prepare("INSERT OR IGNORE INTO folder_posters (path) VALUES (:p)");

    $iter = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            function (SplFileInfo $current, string $key, RecursiveDirectoryIterator $iterator) use ($hiddenDirs) {
                if (!$current->isDir()) return false;
                if (in_array($current->getFilename(), $hiddenDirs, true)) return false;
                if ($current->getFilename()[0] === '.') return false;
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $batch = 0;
    $db->beginTransaction();
    foreach ($iter as $dir) {
        $path = $dir->getRealPath() ?: $dir->getPathname();
        $rel = ltrim(str_replace($basePath, '', $path), '/');
        $depth = substr_count($rel, '/') + 1;
        if ($depth < $minDepth) continue;

        try {
            $stmt->execute([':p' => $path]);
            if ($stmt->rowCount() > 0) $inserted++;
        } catch (PDOException $e) {}

        if (++$batch % 200 === 0) {
            $db->commit();
            $db->beginTransaction();
        }
    }
    $db->commit();

    return $inserted;
}

$discovered = discover_folders($db);
if ($discovered > 0) {
    ai_log("DISCOVER | $discovered new folders found in " . BASE_PATH);
}

// ── Single lock file shared with web handler ──
// Web handler checks this file to know if worker is running.
// Stale lock (>15 min): kill old PID and break lock.
$cronLock = dirname(DB_PATH) . '/sharebox_tmdb_cron.lock';
@touch($cronLock); @chmod($cronLock, 0666);
if (file_exists($cronLock) && (time() - filemtime($cronLock)) > 900) {
    $stalePid = (int)@file_get_contents($cronLock);
    if ($stalePid > 1 && file_exists("/proc/$stalePid")) {
        ai_log("SCAN stale lock detected (>15min), killing old PID $stalePid");
        posix_kill($stalePid, SIGTERM);
        usleep(200000);
    } else {
        ai_log('SCAN stale lock detected (>15min), breaking it');
    }
    @unlink($cronLock);
    @touch($cronLock); @chmod($cronLock, 0666);
}
$cronFp = fopen($cronLock, 'w');
if (!$cronFp || !flock($cronFp, LOCK_EX | LOCK_NB)) {
    ai_log('SCAN skip — already running');
    exit(0);
}
fwrite($cronFp, (string)getmypid());
fflush($cronFp);

// ── Main loop: process until nothing left ──
$prevPendingCount = '';
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
do {
    // Touch lock to reset mtime — prevents stale detection during legitimate long runs
    @touch($cronLock);

    $rows = $dbRead->query("SELECT rowid, path, match_attempts FROM folder_posters WHERE poster_url IS NULL AND (match_attempts IS NULL OR match_attempts < 3)")->fetchAll();
    $pendingCount = count($rows);
    ai_log('SCAN start | pending=' . $pendingCount);

    if ($pendingCount === 0) {
        ai_log('SCAN | nothing pending, exiting');
        break;
    }

    // Safety: if count didn't decrease since last pass, we're stuck — exit
    // With progressive retry (match_attempts 0→1→2), pending count may stay the same
    // between passes, so also track the sum of match_attempts to detect progress.
    $attemptsSum = 0;
    foreach ($rows as $r) $attemptsSum += (int)($r['match_attempts'] ?? 0);
    $attemptsKey = $pendingCount . ':' . $attemptsSum;
    if ($attemptsKey === $prevPendingCount) {
        ai_log('SCAN stuck — pending=' . $pendingCount . ' attempts_sum=' . $attemptsSum . ', exiting to avoid infinite loop');
        break;
    }
    $prevPendingCount = $attemptsKey;

    // Group by parent directory, keep rowid + attempt level for DB writes
    $byDir = [];
    $rowIds = [];
    $rowAttempts = [];
    foreach ($rows as $row) {
        $dir = dirname($row['path']);
        $name = basename($row['path']);
        $byDir[$dir][] = $name;
        $rowIds[$dir . chr(0) . $name] = (int)$row['rowid'];
        $rowAttempts[$dir . chr(0) . $name] = (int)($row['match_attempts'] ?? 0);
    }

    $getRowId = function(string $dir, string $name) use (&$rowIds): ?int {
        $key = $dir . chr(0) . $name;
        if (isset($rowIds[$key])) return $rowIds[$key];
        $norm = @iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name;
        foreach ($rowIds as $k => $id) {
            if (!str_starts_with($k, $dir . chr(0))) continue;
            $dbName = substr($k, strlen($dir) + 1);
            if ((@iconv('UTF-8', 'ASCII//TRANSLIT', $dbName) ?: $dbName) === $norm) return $id;
        }
        return null;
    };

    // Filter: skip directories without media content
    // No explicit transaction — autocommit avoids WAL lock contention
    foreach ($byDir as $dir => $names) {
        if (!is_dir($dir)) { unset($byDir[$dir]); continue; }
        $items = @scandir($dir);
        if ($items === false) { unset($byDir[$dir]); continue; }
        $hasMedia = false;
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            if (is_dir($dir . '/' . $item)) { $hasMedia = true; break; }
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $VIDEO_EXTS, true)) { $hasMedia = true; break; }
        }
        if (!$hasMedia) {
            foreach ($names as $n) {
                $rid = $getRowId($dir, $n);
                if ($rid) {
                    try {
                        $db->prepare("UPDATE folder_posters SET match_attempts = 1 WHERE rowid = :id")->execute([':id' => $rid]);
                    } catch (PDOException $e) {
                        ai_log('DB error (no-media skip): ' . $e->getMessage());
                    }
                }
            }
            unset($byDir[$dir]);
        }
    }
    // (autocommit — no explicit commit needed)

    $totalFound = 0;
    $totalProcessed = 0;

    // ── Company/Studio detection for movies-type folders ──
    // If parent folder is type=movies and entry is a subfolder (not a video file),
    // try TMDB company search (e.g., "Disney", "DreamWorks" folders)
    foreach ($byDir as $dir => &$names) {
        // Check if parent is a movies folder
        $stmtType = $dbRead->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
        $stmtType->execute([':p' => $dir]);
        $typeRow = $stmtType->fetch();
        $isMoviesFolder = ($typeRow && ($typeRow['folder_type'] ?? 'series') === 'movies');

        if ($isMoviesFolder) {
            foreach ($names as $i => $n) {
                $fullPath = $dir . '/' . $n;
                // Skip if not a directory
                if (!is_dir($fullPath)) continue;

                // Try multi-source search (collections > wikimedia > company)
                ai_log('STUDIO try | ' . $n);
                $studioMatch = find_studio_artwork($n, $TMDB_API_KEY, $ctx);

                if ($studioMatch) {
                    $rowId = $getRowId($dir, $n);
                    if ($rowId) {
                        try {
                            $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 70, tmdb_type = :mt, updated_at = datetime('now') WHERE rowid = :id")
                               ->execute([
                                   ':id' => $rowId,
                                   ':u' => $studioMatch['poster'],
                                   ':i' => $studioMatch['id'],
                                   ':t' => $studioMatch['title'],
                                   ':o' => $studioMatch['overview'] ?? null,
                                   ':mt' => $studioMatch['type']
                               ]);
                            ai_log('STUDIO found | ' . $n . ' → ' . $studioMatch['title'] . ' (source=' . $studioMatch['type'] . ')');
                            $totalFound++;
                            unset($names[$i]); // Remove from normal processing
                        } catch (PDOException $e) {
                            ai_log('DB error (studio write): ' . $e->getMessage());
                        }
                    }
                }
                usleep(100000); // Rate limit (multiple API calls)
            }
            $names = array_values($names); // Re-index after unset
        }
    }
    unset($names); // Break reference

    // ── Build parent context map: if parent already matched, use its title for child searches ──
    $parentContext = [];
    foreach (array_keys($byDir) as $dir) {
        $stmtP = $dbRead->prepare("SELECT tmdb_id, title, tmdb_type FROM folder_posters WHERE path = :p AND poster_url IS NOT NULL AND poster_url != '__none__'");
        $stmtP->execute([':p' => $dir]);
        $pRow = $stmtP->fetch();
        if ($pRow) {
            $parentContext[$dir] = $pRow;
        }
    }

    foreach ($byDir as $dir => $names) {
        if (empty($names)) continue; // Skip if all entries were company matches
        ai_log('SCAN | dir=' . basename($dir) . ' entries=' . count($names));

        // Detect if dir has season-like subfolders → prefer TV results
        $preferTv = false;
        $dirItems = @scandir($dir);
        if ($dirItems) {
            foreach ($dirItems as $di) {
                if ($di[0] === '.' || !is_dir($dir . '/' . $di)) continue;
                if (preg_match('/^(s\d{1,2}|saison|season|saga|arc|part)/i', $di)) {
                    $preferTv = true;
                    break;
                }
            }
        }
        // Also prefer TV if parent is matched as TV
        if (isset($parentContext[$dir]) && ($parentContext[$dir]['tmdb_type'] ?? '') === 'tv') {
            $preferTv = true;
        }

        // Group files by extracted title to deduplicate TMDB calls
        $titleToFiles = [];
        $noTitle = [];
        foreach ($names as $n) {
            $meta = extract_title_year($n);
            $attempt = $rowAttempts[$dir . chr(0) . $n] ?? 0;

            if (!$meta['title']) {
                // Try parent context for entries with no extractable title
                if (isset($parentContext[$dir])) {
                    // Use parent title + child name as combined search
                    $childClean = preg_replace('/[._\[\](){}]+/', ' ', $n);
                    $childClean = preg_replace('/\b(multi|vff|bluray|1080p|2160p|x264|x265|hevc)\b.*/i', '', $childClean);
                    $childClean = trim($childClean);
                    if ($childClean !== '' && !preg_match('/^(s\d{1,2}|saison\s*\d+|season\s*\d+|e\d{2,4}|saga\s*\d+|arc\s*\d+|part\s*\d+)$/i', $childClean)) {
                        $combined = $parentContext[$dir]['title'] . ' ' . $childClean;
                        $meta = ['title' => $combined, 'year' => null];
                    }
                }
                if (!$meta['title']) { $noTitle[] = $n; continue; }
            }

            $key = $meta['title'] . '|' . ($meta['year'] ?? '');
            $titleToFiles[$key][] = ['name' => $n, 'title' => $meta['title'], 'year' => $meta['year'], 'attempt' => $attempt];
        }

        $found = 0;
        foreach ($titleToFiles as $key => $files) {
            $first = $files[0];
            $attempt = $first['attempt'];
            $searchTitle = $first['title'];
            $searchYear = $first['year'];

            // Per-entry TV preference: if the folder name itself contains S01-S99 pattern
            $entryPreferTv = $preferTv || preg_match('/\bS\d{1,2}\b/i', $first['name']);

            // ── Retry strategy based on attempt level ──
            // Attempt 0: full title, FR, multi+tv candidates
            // Attempt 1: short title (first half of words), FR, multi+tv+movie
            // Attempt 2: full title, no language (English fallback)
            if ($attempt === 1) {
                $words = explode(' ', $searchTitle);
                if (count($words) > 2) {
                    $searchTitle = implode(' ', array_slice($words, 0, (int)ceil(count($words) / 2)));
                }
            }

            $candidates = [];
            if ($attempt <= 1) {
                $candidates = tmdb_search_candidates($searchTitle, $searchYear, $TMDB_API_KEY, $ctx, 8);
            } else {
                // Attempt 2: English fallback — direct fetch without language param
                $encoded = urlencode($searchTitle);
                $urls = [
                    "https://api.themoviedb.org/3/search/multi?api_key={$TMDB_API_KEY}&query={$encoded}&page=1",
                ];
                foreach ($urls as $u) {
                    $data = tmdb_fetch($u, $ctx);
                    if ($data && !empty($data['results'])) {
                        foreach ($data['results'] as $r) {
                            if (empty($r['poster_path'])) continue;
                            $candidates[] = [
                                'id' => $r['id'],
                                'title' => $r['title'] ?? $r['name'] ?? '?',
                                'original_title' => $r['original_title'] ?? $r['original_name'] ?? null,
                                'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                                'type' => $r['media_type'] ?? ($r['first_air_date'] ?? false ? 'tv' : 'movie'),
                                'overview' => substr($r['overview'] ?? '', 0, 150),
                                'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                                'rating' => round((float)($r['vote_average'] ?? 0), 1),
                                'vote_count' => (int)($r['vote_count'] ?? 0),
                            ];
                            if (count($candidates) >= 8) break;
                        }
                    }
                    usleep(50000);
                }
            }

            // ── Score candidates and pick best ──
            $bestMatch = null;
            $bestScore = 0;
            foreach ($candidates as $c) {
                $score = tmdb_score_candidate($first['title'], $first['year'], $c, $entryPreferTv);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $c;
                }
            }

            $verified = tmdb_score_to_verified($bestScore);
            $match = null;
            if ($bestMatch && $verified > 0) {
                $match = [
                    'poster' => $bestMatch['poster'],
                    'id' => $bestMatch['id'],
                    'title' => $bestMatch['title'],
                    'overview' => $bestMatch['overview'] ?? null,
                    'year' => $bestMatch['year'],
                    'type' => $bestMatch['type'],
                    'rating' => $bestMatch['rating'] ?? 0,
                ];
            }

            foreach ($files as $f) {
                $rowId = $getRowId($dir, $f['name']);
                if (!$rowId) continue;
                if ($match) {
                    $found++;
                    try {
                        // Worker-matched entries: set ia_checked=1 to avoid permanent "IA pending" spinner.
                        // The verified score (40/60/80) already tells /tmdb-scan which entries need review.
                        $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = :v, tmdb_year = :y, tmdb_type = :mt, tmdb_rating = :r, ia_checked = 1, updated_at = datetime('now') WHERE rowid = :id")
                           ->execute([':id' => $rowId, ':u' => $match['poster'], ':i' => $match['id'], ':t' => $match['title'], ':o' => $match['overview'], ':v' => $verified, ':y' => $match['year'], ':mt' => $match['type'], ':r' => $match['rating']]);
                    } catch (PDOException $e) {
                        ai_log('DB error (match write): ' . $e->getMessage());
                    }
                } else {
                    // Increment match_attempts for next retry pass
                    $nextAttempt = $attempt + 1;
                    try {
                        $db->prepare("UPDATE folder_posters SET match_attempts = :a WHERE rowid = :id")
                           ->execute([':id' => $rowId, ':a' => $nextAttempt]);
                    } catch (PDOException $e) {
                        ai_log('DB error (attempts inc): ' . $e->getMessage());
                    }
                }
            }

            if ($match) {
                ai_log('MATCH | "' . $first['title'] . '" x' . count($files) . ' -> ' . $match['title'] . ' (id=' . $match['id'] . ' score=' . $bestScore . ' verified=' . $verified . ')');
            } elseif ($candidates) {
                ai_log('WEAK  | "' . $first['title'] . '" best_score=' . $bestScore . ' (below threshold, attempt=' . ($attempt+1) . ')');
            }
        }

        // Increment attempts for entries with no extractable title
        // Exception: bare season/episode codes (S01, Saison 3, etc.) — leave for propagation
        if ($noTitle) {
            foreach ($noTitle as $n) {
                if (preg_match('/^(s\d{1,2}|saison\s*\d+|season\s*\d+|e\d{2,4}|saga\s*\d+|arc\s*\d+|part\s*\d+|partie\s*\d+)$/i', trim($n))) {
                    continue; // propagation will handle
                }
                $rid = $getRowId($dir, $n);
                if ($rid) {
                    $curAttempt = $rowAttempts[$dir . chr(0) . $n] ?? 0;
                    try {
                        $db->prepare("UPDATE folder_posters SET match_attempts = :a WHERE rowid = :id")->execute([':id' => $rid, ':a' => $curAttempt + 1]);
                    } catch (PDOException $e) {
                        ai_log('DB error (no-title inc): ' . $e->getMessage());
                    }
                }
            }
        }

        $totalFound += $found;
        $totalProcessed += count($names);
        ai_log('SCAN | dir=' . basename($dir) . ' matched=' . $found . '/' . count($names));
    }

    // Auto-verify: entries in same dir with same tmdb_id (>3 = clearly same show)
    $unverified = $dbRead->query("SELECT path, tmdb_id FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0 OR verified = 60)")->fetchAll();
    $byDirTmdb = [];
    foreach ($unverified as $row) {
        $dir = dirname($row['path']);
        $tid = $row['tmdb_id'] ?? 0;
        if ($tid) $byDirTmdb[$dir][$tid][] = $row['path'];
    }
    $autoVerified = 0;
    foreach ($byDirTmdb as $dir => $groups) {
        foreach ($groups as $tid => $paths) {
            if (count($paths) > 3) {
                foreach ($paths as $p) {
                    try {
                        $db->prepare("UPDATE folder_posters SET verified = 70 WHERE path = :p")->execute([':p' => $p]);
                        $autoVerified++;
                    } catch (PDOException $e) {
                        ai_log('DB error (auto-verify): ' . $e->getMessage());
                    }
                }
            }
        }
    }
    if ($autoVerified > 0) ai_log('AUTO-VERIFY | ' . $autoVerified . ' entries (>3 same tmdb_id in same dir)');

    // Propagate: if a parent folder is matched, apply its poster to unmatched children
    // Use $db (writer) to see current verified scores — $dbRead has stale WAL snapshot
    $propagated = 0;
    $parentRows = $db->query("SELECT path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_type, tmdb_rating FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND verified >= 60")->fetchAll();
    $parentMap = [];
    foreach ($parentRows as $pr) {
        $parentMap[$pr['path']] = $pr;
    }
    // Use writer connection ($db) for propagation reads — $dbRead may have a stale WAL snapshot
    // and miss entries that were just matched in this same pass, causing propagation to overwrite
    // good matches with weaker parent matches.
    $stillPending = $db->query("SELECT path FROM folder_posters WHERE poster_url IS NULL")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($stillPending as $childPath) {
        $parentPath = dirname($childPath);
        if (isset($parentMap[$parentPath])) {
            $p = $parentMap[$parentPath];
            try {
                // Only propagate to entries that truly have no poster — the WHERE clause ensures
                // we don't overwrite a match set earlier in this same pass.
                $db->prepare("UPDATE folder_posters SET poster_url = ?, tmdb_id = ?, title = ?, overview = ?, tmdb_year = ?, tmdb_type = ?, tmdb_rating = ?, verified = 55, ia_checked = 1, updated_at = datetime('now') WHERE path = ? AND poster_url IS NULL")
                   ->execute([$p['poster_url'], $p['tmdb_id'], $p['title'], $p['overview'], $p['tmdb_year'], $p['tmdb_type'], $p['tmdb_rating'] ?? null, $childPath]);
                if ($db->prepare("SELECT changes()")->execute() && $db->query("SELECT changes()")->fetchColumn() > 0) {
                    $propagated++;
                }
            } catch (PDOException $e) {
                ai_log('DB error (propagate): ' . $e->getMessage());
            }
        }
    }

    if ($propagated > 0) ai_log('PROPAGATE | ' . $propagated . ' entries inherited from parent folder');

    ai_log('SCAN done | matched=' . $totalFound . '/' . $totalProcessed . ' auto_verified=' . $autoVerified . ' propagated=' . $propagated);

} while (true); // loop until no more pending entries

// ── Final propagation pass (runs even when nothing was pending) ──
// Use $db (writer) to get current state — $dbRead may have stale WAL snapshot
$parentRows = $db->query("SELECT path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_type, tmdb_rating FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND verified >= 60")->fetchAll();
$parentMap = [];
foreach ($parentRows as $pr) $parentMap[$pr['path']] = $pr;
$stillPending = $db->query("SELECT path FROM folder_posters WHERE poster_url IS NULL")->fetchAll(PDO::FETCH_COLUMN);
$finalPropagated = 0;
foreach ($stillPending as $childPath) {
    $parentPath = dirname($childPath);
    if (isset($parentMap[$parentPath])) {
        $p = $parentMap[$parentPath];
        try {
            $db->prepare("UPDATE folder_posters SET poster_url = ?, tmdb_id = ?, title = ?, overview = ?, tmdb_year = ?, tmdb_type = ?, tmdb_rating = ?, verified = 55, ia_checked = 1, updated_at = datetime('now') WHERE path = ? AND poster_url IS NULL")
               ->execute([$p['poster_url'], $p['tmdb_id'], $p['title'], $p['overview'], $p['tmdb_year'], $p['tmdb_type'], $p['tmdb_rating'] ?? null, $childPath]);
            $finalPropagated++;
        } catch (PDOException $e) {}
    }
}
if ($finalPropagated > 0) ai_log('FINAL PROPAGATE | ' . $finalPropagated . ' entries inherited from parent folder');

// ── Season/Saga poster lookup (runs once after all matching is done) ──
// For TV entries with season-numbered or saga/arc subfolders, fetch season-specific poster from TMDB
$seasonUpdated = 0;
// Use $db (writer) to see entries propagated earlier in this run — $dbRead has stale WAL snapshot
$seasonRows = $db->query("
    SELECT fp.path, fp.tmdb_id, fp.poster_url
    FROM folder_posters fp
    WHERE fp.tmdb_type = 'tv' AND fp.tmdb_id IS NOT NULL AND fp.poster_url IS NOT NULL AND fp.poster_url != '__none__'
")->fetchAll();

// Cache TMDB season lists per tmdb_id to avoid redundant API calls
$tmdbSeasonCache = [];

foreach ($seasonRows as $sr) {
    $dirPath = $sr['path'];
    if (!is_dir($dirPath)) continue;
    $children = @scandir($dirPath);
    if (!$children) continue;

    foreach ($children as $child) {
        if ($child[0] === '.' || !is_dir($dirPath . '/' . $child)) continue;

        // Extract season number from folder name (classic patterns)
        $seasonNum = null;
        $sagaName = null;
        if (preg_match('/(?:^|\.)S(\d{1,2})(?:\.|$)/i', $child, $m)) {
            $seasonNum = (int)$m[1];
        } elseif (preg_match('/(?:saison|season)\s*(\d+)/i', $child, $m)) {
            $seasonNum = (int)$m[1];
        } elseif (preg_match('/(?:saga|arc|part|partie)\s*(\d+)(?:\s*[-–:]\s*(.+))?/i', $child, $m)) {
            // Saga/Arc pattern: "Saga 03 - Skypiea", "Arc 1 - East Blue", "Part 2"
            $sagaNum = (int)$m[1];
            $sagaName = isset($m[2]) ? trim($m[2]) : null;
        } elseif (preg_match('/(?:saga|arc|part|partie)\s*[-–:]\s*(.+)/i', $child, $m)) {
            // Named saga without number: "Arc Skypiea", "Saga Alabasta"
            $sagaName = trim($m[1]);
        }

        if ($seasonNum === null && !isset($sagaNum) && $sagaName === null) continue;

        $childPath = realpath($dirPath . '/' . $child) ?: ($dirPath . '/' . $child);

        // Check if this child is in DB with the same tmdb_id as parent
        $childRow = $db->prepare("SELECT poster_url FROM folder_posters WHERE path = ? AND tmdb_id = ?");
        $childRow->execute([$childPath, $sr['tmdb_id']]);
        $existing = $childRow->fetch();
        if (!$existing) continue;

        // For saga/arc folders, resolve to TMDB season number via name matching
        if ($seasonNum === null && (isset($sagaNum) || $sagaName !== null)) {
            $tmdbId = $sr['tmdb_id'];
            if (!isset($tmdbSeasonCache[$tmdbId])) {
                $showUrl = "https://api.themoviedb.org/3/tv/{$tmdbId}?api_key={$TMDB_API_KEY}&language=fr";
                $showData = tmdb_fetch($showUrl, $ctx);
                usleep(50000);
                $tmdbSeasonCache[$tmdbId] = $showData['seasons'] ?? [];
            }
            $tmdbSeasons = $tmdbSeasonCache[$tmdbId];

            // Try name similarity matching first
            if ($sagaName) {
                $bestSim = 0;
                $bestSeason = null;
                $normSaga = mb_strtolower($sagaName);
                foreach ($tmdbSeasons as $ts) {
                    $tsName = mb_strtolower($ts['name'] ?? '');
                    if ($tsName === '') continue;
                    similar_text($normSaga, $tsName, $pct);
                    if ($pct > $bestSim && $pct > 40) {
                        $bestSim = $pct;
                        $bestSeason = $ts['season_number'];
                    }
                }
                if ($bestSeason !== null) {
                    $seasonNum = $bestSeason;
                    ai_log('SAGA->SEASON | "' . $child . '" matched to season ' . $seasonNum . ' by name (sim=' . round($bestSim) . '%)');
                }
            }

            // Fallback: sequential mapping (Saga 1 → Season 1, etc.)
            if ($seasonNum === null && isset($sagaNum)) {
                // Filter out specials (season 0)
                $realSeasons = array_filter($tmdbSeasons, fn($s) => ($s['season_number'] ?? 0) > 0);
                if ($sagaNum <= count($realSeasons)) {
                    $seasonNum = $sagaNum;
                    ai_log('SAGA->SEASON | "' . $child . '" sequential fallback to season ' . $seasonNum);
                }
            }

            if ($seasonNum === null) continue; // Could not resolve saga to season
        }

        // Fetch season-specific poster from TMDB
        $seasonUrl = "https://api.themoviedb.org/3/tv/{$sr['tmdb_id']}/season/{$seasonNum}?api_key={$TMDB_API_KEY}&language=fr";
        $seasonData = tmdb_fetch($seasonUrl, $ctx);
        usleep(50000); // rate limit

        if ($seasonData && !empty($seasonData['poster_path'])) {
            $seasonPoster = 'https://image.tmdb.org/t/p/w300' . $seasonData['poster_path'];
            if ($seasonPoster !== $existing['poster_url']) {
                try {
                    $db->prepare("UPDATE folder_posters SET poster_url = ?, updated_at = datetime('now') WHERE path = ?")
                       ->execute([$seasonPoster, $childPath]);
                    $seasonUpdated++;
                } catch (PDOException $e) {
                    ai_log('DB error (season poster): ' . $e->getMessage());
                }
            }
        }
    }
}
if ($seasonUpdated > 0) ai_log('SEASON | ' . $seasonUpdated . ' entries got season-specific poster (incl. saga/arc)');

// ── GC: remove entries for paths that no longer exist ──
$gcRows = $db->query("SELECT rowid, path FROM folder_posters ORDER BY RANDOM() LIMIT 1000")->fetchAll();
$gcRemoved = 0;
$db->beginTransaction();
foreach ($gcRows as $gr) {
    if (!file_exists($gr['path'])) {
        $db->prepare("DELETE FROM folder_posters WHERE rowid = ?")->execute([$gr['rowid']]);
        $gcRemoved++;
    }
}
$db->commit();
if ($gcRemoved > 0) ai_log('GC | removed ' . $gcRemoved . ' orphan entries');

// ── Checkpoint WAL + backup DB after scan ──
// Ensures the backup captures the post-scan state, not the pre-scan NULL state.
try { $db->exec('PRAGMA wal_checkpoint(PASSIVE)'); } catch (PDOException $e) { ai_log('CHECKPOINT skip: ' . $e->getMessage()); }
$dbFile = DB_PATH;
$backupFile = dirname($dbFile) . '/share.db.bak';
if (filesize($dbFile) > 4096) {
    @copy($dbFile, $backupFile);
    ai_log('BACKUP updated after scan');
}

// ── Helpers ──

/**
 * Multi-source search for studio artwork (collections > wikimedia > company)
 * Returns best match with poster URL or null
 */
function find_studio_artwork(string $studioName, string $apiKey, $ctx): ?array {
    $query = urlencode($studioName);

    // 1. Try TMDB Collections (best artwork quality)
    $collUrl = "https://api.themoviedb.org/3/search/collection?api_key={$apiKey}&query={$query}&language=fr&page=1";
    $collResult = tmdb_fetch($collUrl, $ctx);
    if ($collResult && !empty($collResult['results'])) {
        foreach ($collResult['results'] as $r) {
            if (!empty($r['poster_path'])) {
                return [
                    'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                    'id' => $r['id'] ?? 0,
                    'title' => $r['name'] ?? $studioName,
                    'overview' => $r['overview'] ?? null,
                    'type' => 'collection',
                ];
            }
        }
    }

    // 2. Try Wikimedia Commons (high-quality logos)
    $wikiResult = find_wikimedia_logo($studioName, $ctx);
    if ($wikiResult) return $wikiResult;

    // 3. Fallback to TMDB Company (logo only, often landscape format)
    $compUrl = "https://api.themoviedb.org/3/search/company?api_key={$apiKey}&query={$query}&page=1";
    $compResult = tmdb_fetch($compUrl, $ctx);
    if ($compResult && !empty($compResult['results'])) {
        foreach ($compResult['results'] as $c) {
            if (!empty($c['logo_path'])) {
                return [
                    'poster' => 'https://image.tmdb.org/t/p/w300' . $c['logo_path'],
                    'id' => $c['id'] ?? 0,
                    'title' => $c['name'] ?? $studioName,
                    'overview' => null,
                    'type' => 'company',
                ];
            }
        }
    }

    return null;
}

/**
 * Search Wikimedia Commons for studio logo
 * Returns first suitable result or null
 */
function find_wikimedia_logo(string $studioName, $ctx): ?array {
    $searchQuery = urlencode($studioName . ' logo');
    $searchUrl = "https://commons.wikimedia.org/w/api.php?action=query&list=search&srsearch={$searchQuery}&srnamespace=6&format=json&srlimit=3";

    $searchResp = @file_get_contents($searchUrl, false, $ctx);
    $searchData = $searchResp ? json_decode($searchResp, true) : null;

    if (!$searchData || empty($searchData['query']['search'])) {
        return null;
    }

    // Try to get first suitable image
    foreach ($searchData['query']['search'] as $item) {
        $title = $item['title'] ?? '';
        if (!$title) continue;

        $infoUrl = "https://commons.wikimedia.org/w/api.php?action=query&titles=" . urlencode($title) . "&prop=imageinfo&iiprop=url|size&format=json";
        $infoResp = @file_get_contents($infoUrl, false, $ctx);
        $infoData = $infoResp ? json_decode($infoResp, true) : null;

        if (!$infoData || empty($infoData['query']['pages'])) continue;

        $page = reset($infoData['query']['pages']);
        if (empty($page['imageinfo'][0]['url'])) continue;

        $imageUrl = $page['imageinfo'][0]['url'];
        $width = $page['imageinfo'][0]['width'] ?? 0;
        $height = $page['imageinfo'][0]['height'] ?? 0;

        // Skip tiny images or very wide landscape logos
        if ($width < 200 || $height < 100) continue;
        if ($width > 0 && $height > 0 && ($width / $height) > 3) continue;

        return [
            'poster' => $imageUrl,
            'id' => 0,
            'title' => $studioName . ' (Wikimedia)',
            'overview' => 'Logo depuis Wikimedia Commons',
            'type' => 'wikimedia',
        ];
    }

    return null;
}

function ai_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    if (function_exists('poster_log')) poster_log($msg);
}
