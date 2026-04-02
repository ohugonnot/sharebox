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

$db = get_db();

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
$prevPendingCount = -1;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
do {
    // Touch lock to reset mtime — prevents stale detection during legitimate long runs
    @touch($cronLock);

    $rows = $db->query("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (match_attempts IS NULL OR match_attempts = 0)")->fetchAll();
    $pendingCount = count($rows);
    ai_log('SCAN start | pending=' . $pendingCount);

    if ($pendingCount === 0) {
        ai_log('SCAN | nothing pending, exiting');
        break;
    }

    // Safety: if count didn't decrease since last pass, we're stuck — exit
    if ($pendingCount === $prevPendingCount) {
        ai_log('SCAN stuck — pending count unchanged (' . $pendingCount . '), exiting to avoid infinite loop');
        break;
    }
    $prevPendingCount = $pendingCount;

    // Group by parent directory, keep rowid for DB writes
    $byDir = [];
    $rowIds = [];
    foreach ($rows as $row) {
        $dir = dirname($row['path']);
        $name = basename($row['path']);
        $byDir[$dir][] = $name;
        $rowIds[$dir . chr(0) . $name] = (int)$row['rowid'];
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
    try { $db->beginTransaction(); } catch (PDOException $e) {}
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
    try { $db->commit(); } catch (PDOException $e) {}

    $totalFound = 0;
    $totalProcessed = 0;

    // ── Company/Studio detection for movies-type folders ──
    // If parent folder is type=movies and entry is a subfolder (not a video file),
    // try TMDB company search (e.g., "Disney", "DreamWorks" folders)
    foreach ($byDir as $dir => &$names) {
        // Check if parent is a movies folder
        $stmtType = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
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

    foreach ($byDir as $dir => $names) {
        if (empty($names)) continue; // Skip if all entries were company matches
        ai_log('SCAN | dir=' . basename($dir) . ' entries=' . count($names));

        // Group files by extracted title to deduplicate TMDB calls
        $titleToFiles = [];
        $noTitle = [];
        foreach ($names as $n) {
            $meta = extract_title_year($n);
            if (!$meta['title']) { $noTitle[] = $n; continue; }
            $key = $meta['title'] . '|' . ($meta['year'] ?? '');
            $titleToFiles[$key][] = ['name' => $n, 'title' => $meta['title'], 'year' => $meta['year']];
        }

        $found = 0;
        foreach ($titleToFiles as $key => $files) {
            $first = $files[0];
            $result = tmdb_fetch("https://api.themoviedb.org/3/search/multi?api_key={$TMDB_API_KEY}&query=" . urlencode($first['title']) . "&language=fr&page=1", $ctx);

            $match = null;
            if ($result && !empty($result['results'])) {
                foreach ($result['results'] as $r) {
                    if (!empty($r['poster_path'])) {
                        $match = [
                            'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                            'id' => $r['id'] ?? null,
                            'title' => $r['title'] ?? $r['name'] ?? null,
                            'overview' => $r['overview'] ?? null,
                            'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                            'type' => $r['media_type'] ?? ($r['first_air_date'] ?? false ? 'tv' : 'movie'),
                            'rating' => round((float)($r['vote_average'] ?? 0), 1),
                        ];
                        break;
                    }
                }
            }
            usleep(50000); // TMDB rate limit

            foreach ($files as $f) {
                $rowId = $getRowId($dir, $f['name']);
                if (!$rowId) continue;
                if ($match) {
                    $found++;
                    try {
                        $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 60, tmdb_year = :y, tmdb_type = :mt, tmdb_rating = :r, updated_at = datetime('now') WHERE rowid = :id")
                           ->execute([':id' => $rowId, ':u' => $match['poster'], ':i' => $match['id'], ':t' => $match['title'], ':o' => $match['overview'], ':y' => $match['year'], ':mt' => $match['type'], ':r' => $match['rating']]);
                    } catch (PDOException $e) {
                        ai_log('DB error (match write): ' . $e->getMessage());
                    }
                } else {
                    try {
                        $db->prepare("UPDATE folder_posters SET match_attempts = 1 WHERE rowid = :id")
                           ->execute([':id' => $rowId]);
                    } catch (PDOException $e) {
                        ai_log('DB error (attempts inc): ' . $e->getMessage());
                    }
                }
            }

            if ($match) {
                ai_log('MATCH | "' . $first['title'] . '" x' . count($files) . ' -> ' . $match['title'] . ' (id=' . $match['id'] . ')');
            }
        }

        // Increment attempts for entries with no extractable title
        // Exception: bare season/episode codes (S01, Saison 3, etc.) — leave match_attempts=0
        // so propagation can handle them once the parent is matched.
        if ($noTitle) {
            try { $db->beginTransaction(); } catch (PDOException $e) {}
            foreach ($noTitle as $n) {
                if (preg_match('/^(s\d{1,2}|saison\s*\d+|season\s*\d+|e\d{2,4})$/i', trim($n))) {
                    // Bare season/episode code — skip silently, propagation will handle
                    continue;
                }
                $rid = $getRowId($dir, $n);
                if ($rid) {
                    try {
                        $db->prepare("UPDATE folder_posters SET match_attempts = 1 WHERE rowid = :id")->execute([':id' => $rid]);
                    } catch (PDOException $e) {
                        ai_log('DB error (no-title inc): ' . $e->getMessage());
                    }
                }
            }
            try { $db->commit(); } catch (PDOException $e) {}
        }

        $totalFound += $found;
        $totalProcessed += count($names);
        ai_log('SCAN | dir=' . basename($dir) . ' matched=' . $found . '/' . count($names));
    }

    // Auto-verify: entries in same dir with same tmdb_id (>3 = clearly same show)
    $unverified = $db->query("SELECT path, tmdb_id FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0 OR verified = 60)")->fetchAll();
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
    $propagated = 0;
    $parentRows = $db->query("SELECT path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_type, tmdb_rating FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__'")->fetchAll();
    $parentMap = [];
    foreach ($parentRows as $pr) {
        $parentMap[$pr['path']] = $pr;
    }
    $stillPending = $db->query("SELECT path FROM folder_posters WHERE poster_url IS NULL")->fetchAll(PDO::FETCH_COLUMN);

    try { $db->beginTransaction(); } catch (PDOException $e) {}
    foreach ($stillPending as $childPath) {
        $parentPath = dirname($childPath);
        if (isset($parentMap[$parentPath])) {
            $p = $parentMap[$parentPath];
            try {
                $db->prepare("UPDATE folder_posters SET poster_url = ?, tmdb_id = ?, title = ?, overview = ?, tmdb_year = ?, tmdb_type = ?, tmdb_rating = ?, verified = 75, updated_at = datetime('now') WHERE path = ?")
                   ->execute([$p['poster_url'], $p['tmdb_id'], $p['title'], $p['overview'], $p['tmdb_year'], $p['tmdb_type'], $p['tmdb_rating'] ?? null, $childPath]);
                $propagated++;
            } catch (PDOException $e) {
                ai_log('DB error (propagate): ' . $e->getMessage());
            }
        }
    }
    try { $db->commit(); } catch (PDOException $e) { ai_log('DB error (propagate commit): ' . $e->getMessage()); }

    if ($propagated > 0) ai_log('PROPAGATE | ' . $propagated . ' entries inherited from parent folder');

    ai_log('SCAN done | matched=' . $totalFound . '/' . $totalProcessed . ' auto_verified=' . $autoVerified . ' propagated=' . $propagated);

} while (true); // loop until no more pending entries

// ── Checkpoint WAL + backup DB after scan ──
// Ensures the backup captures the post-scan state, not the pre-scan NULL state.
$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
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
