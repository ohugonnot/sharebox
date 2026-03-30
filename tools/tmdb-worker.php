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
$cronLock = __DIR__ . '/../data/sharebox_tmdb_cron.lock';
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

    foreach ($byDir as $dir => $names) {
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
                        $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 60, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now') WHERE rowid = :id")
                           ->execute([':id' => $rowId, ':u' => $match['poster'], ':i' => $match['id'], ':t' => $match['title'], ':o' => $match['overview'], ':y' => $match['year'], ':mt' => $match['type']]);
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
    $parentRows = $db->query("SELECT path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_type FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__'")->fetchAll();
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
                $db->prepare("UPDATE folder_posters SET poster_url = ?, tmdb_id = ?, title = ?, overview = ?, tmdb_year = ?, tmdb_type = ?, verified = 75, updated_at = datetime('now') WHERE path = ?")
                   ->execute([$p['poster_url'], $p['tmdb_id'], $p['title'], $p['overview'], $p['tmdb_year'], $p['tmdb_type'], $childPath]);
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

function ai_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    if (function_exists('poster_log')) poster_log($msg);
}
