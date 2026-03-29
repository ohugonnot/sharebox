#!/usr/bin/env php
<?php
/**
 * TMDB poster matching via regex + API.
 *
 * Usage: php tools/ai-titles.php --cron      (scan all pending entries)
 *        php tools/ai-titles.php --pending   (same as --cron)
 *
 * Flow:
 *   1. ?posters=1 (web, instant) inserts folders with poster_url=NULL
 *   2. This script finds NULLs, extracts titles with regex, searches TMDB
 *   3. Entries that regex can't resolve stay NULL for /tmdb-scan Claude skill
 *   4. Respects human choices: __none__ = user disabled poster, never overwrite
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$TMDB_API_KEY = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
if (!$TMDB_API_KEY) {
    fwrite(STDERR, "Error: TMDB_API_KEY not configured in config.php\n");
    exit(1);
}

$VIDEO_EXTS = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];

$arg = $argv[1] ?? '--cron';
if ($arg === '--help' || $arg === '-h') {
    echo "Usage: php tools/ai-titles.php --cron\n";
    exit(0);
}

$db = get_db();

// Lock to prevent parallel runs
$adminLock = sys_get_temp_dir() . '/sharebox_tmdb_scan.lock';
$cronLock = __DIR__ . '/../data/sharebox_ai_cron.lock';
@touch($cronLock); @chmod($cronLock, 0666);
$cronFp = fopen($cronLock, 'w');
if (!$cronFp || !flock($cronFp, LOCK_EX | LOCK_NB)) {
    ai_log('SCAN skip — already running');
    exit(0);
}
@touch($adminLock);
register_shutdown_function(function() use ($adminLock) {
    @unlink($adminLock);
});

// Find entries with poster_url IS NULL, skip those already tried 3+ times
$rows = $db->query("SELECT rowid, path FROM folder_posters WHERE poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)")->fetchAll();
ai_log('SCAN start | pending=' . count($rows));

if (empty($rows)) {
    ai_log('SCAN | nothing pending');
    exit(0);
}

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
                try { $db->prepare("UPDATE folder_posters SET ai_attempts = 3 WHERE rowid = :id")->execute([':id' => $rid]); } catch (PDOException $e) {}
            }
        }
        unset($byDir[$dir]);
    }
}

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
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
                    $db->prepare("UPDATE folder_posters SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 0, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now') WHERE rowid = :id")
                       ->execute([':id' => $rowId, ':u' => $match['poster'], ':i' => $match['id'], ':t' => $match['title'], ':o' => $match['overview'], ':y' => $match['year'], ':mt' => $match['type']]);
                } catch (PDOException $e) {}
            } else {
                try {
                    $db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE rowid = :id")
                       ->execute([':id' => $rowId]);
                } catch (PDOException $e) {}
            }
        }

        if ($match) {
            ai_log('MATCH | "' . $first['title'] . '" x' . count($files) . ' -> ' . $match['title'] . ' (id=' . $match['id'] . ')');
        }
    }

    // Increment attempts for entries with no extractable title
    foreach ($noTitle as $n) {
        $rid = $getRowId($dir, $n);
        if ($rid) {
            try { $db->prepare("UPDATE folder_posters SET ai_attempts = COALESCE(ai_attempts, 0) + 1 WHERE rowid = :id")->execute([':id' => $rid]); } catch (PDOException $e) {}
        }
    }

    $totalFound += $found;
    $totalProcessed += count($names);
    ai_log('SCAN | dir=' . basename($dir) . ' matched=' . $found . '/' . count($names));
}

// Auto-verify: entries in same dir with same tmdb_id (>3 = clearly same show)
$unverified = $db->query("SELECT path, tmdb_id FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified IS NULL OR verified = 0)")->fetchAll();
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
                try { $db->prepare("UPDATE folder_posters SET verified = 1 WHERE path = :p")->execute([':p' => $p]); $autoVerified++; } catch (PDOException $e) {}
            }
        }
    }
}
if ($autoVerified > 0) ai_log('AUTO-VERIFY | ' . $autoVerified . ' entries (>3 same tmdb_id in same dir)');

ai_log('SCAN done | matched=' . $totalFound . '/' . $totalProcessed . ' auto_verified=' . $autoVerified);

// ── Helpers ──

function ai_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    if (function_exists('poster_log')) poster_log($msg);
}
