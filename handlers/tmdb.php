<?php
/**
 * TMDB poster search & cache handler.
 * Endpoints:
 *   ?posters=1           → JSON map {folderName: posterUrl} for all subfolders
 *   ?tmdb_search=Name    → JSON array of TMDB results for manual selection
 *   ?tmdb_set (POST)     → Save a poster choice: {folder, poster_url, tmdb_id, title}
 */
header('Content-Type: application/json; charset=utf-8');

$apiKey = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
if (!$apiKey) {
    echo json_encode(['error' => 'TMDB_API_KEY not configured']);
    exit;
}

// ── Batch poster lookup for all subfolders ──
// On cherche des posters TMDB pour chaque sous-dossier dont le nom ressemble à un titre
// de film/série. On skip les noms d'organisation (Season, Saison, OVA, Bonus, Extras...).
if (isset($_GET['posters'])) {
    if (!is_dir($resolvedPath)) { echo '{}'; exit; }
    poster_log('POSTERS request | ' . basename($resolvedPath) . ' | path=' . $resolvedPath);

    // Noms de dossiers qui ne sont PAS des titres de média → pas de fetch TMDB
    $skipPattern = '/^(season\s*\d|saison\s*\d|s\d+e?\d*|vol\s*\d+|part\s*\d+|tome\s*\d+'
        . '|ova|oav|bonus|extras?|special|specials|featurettes?|behind.the.scenes|deleted.scenes'
        . '|interviews?|trailers?|nc|op|ed|ost|soundtrack|subs?|subtitles?|vostfr|vf|multi'
        . '|disc\s*\d|cd\s*\d|dvd|blu-?ray|\d{1,2}$'
        . '|covers?|images?|photos?|samples?|nfo'
        . '|wii|wiiu|switch|nds|3ds|cia|gba|n64|snes|nes|psp|ps[1-4]|xbox'
        . '|bdmv|clipinf|playlist|stream$|meta|backup|certificate'
        . ')/iu';
    // Sous-ensemble du skipPattern : dossiers de saison (on peut leur trouver un poster TMDB via le parent)
    $seasonPattern = '/(?:^|\b)(?:season|saison|s)[\s._-]*(\d+)/i';

    $result = [];

    // Watch history : chemins vus par l'user courant
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $watchUser = $_SESSION['sharebox_user'] ?? null;
    $watchedPaths = [];
    if ($watchUser) {
        $wStmt = $db->prepare("SELECT path FROM watch_history WHERE user = :u");
        $wStmt->execute([':u' => $watchUser]);
        $watchedPaths = array_flip($wStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $items = scandir($resolvedPath);
    $folders = [];
    foreach ($items as $item) {
        if ($item[0] === '.' || !is_dir($resolvedPath . '/' . $item)) continue;
        $folders[] = $item;
    }

    // Don't bail early if this is a movies folder (video files need poster fetch too)
    $stmtTypeEarly = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
    $stmtTypeEarly->execute([':p' => $resolvedPath]);
    $typeRowEarly = $stmtTypeEarly->fetch();
    $isMoviesEarly = ($typeRowEarly && ($typeRowEarly['folder_type'] ?? 'series') === 'movies');
    if (empty($folders) && !$isMoviesEarly) { echo json_encode(['posters' => [], 'pending' => 0]); exit; }

    // ── Season subfolders : poster via parent tmdb_id + /tv/{id}/season/{n} ──
    // Le dossier parent (= $resolvedPath) doit avoir un tmdb_id en cache
    $parentTmdbId = null;
    $stmtParent = $db->prepare("SELECT tmdb_id FROM folder_posters WHERE path = :p AND tmdb_id IS NOT NULL");
    $stmtParent->execute([':p' => $resolvedPath]);
    $parentRow = $stmtParent->fetch();
    if ($parentRow) $parentTmdbId = (int)$parentRow['tmdb_id'];
    poster_log('POSTERS scan | folders=' . count($folders) . ' parentTmdbId=' . ($parentTmdbId ?: 'none'));

    $seasonFolders = []; // folders handled as seasons (excluded from normal flow)
    if ($parentTmdbId) {
        foreach ($folders as $f) {
            if (preg_match($seasonPattern, $f, $m)) {
                $seasonNum = (int)$m[1];
                $fullPath = $resolvedPath . '/' . $f;
                // Check cache — si le tmdb_id matche le parent, c'est un vrai poster de saison
                // Sinon c'est un vieux match foireux (ex: "S01" → série chinoise) → re-fetch
                $stmt = $db->prepare("SELECT poster_url, overview, tmdb_id FROM folder_posters WHERE path = :p");
                $stmt->execute([':p' => $fullPath]);
                $row = $stmt->fetch();
                if ($row && (int)($row['tmdb_id'] ?? 0) === $parentTmdbId) {
                    if ($row['poster_url'] === '__none__') {
                        $result[$f] = ['hidden' => true];
                    } elseif ($row['poster_url']) {
                        $result[$f] = ['poster' => $row['poster_url'], 'confidence' => (int)($row['verified'] ?? 0)];
                        if ($row['overview']) $result[$f]['overview'] = $row['overview'];
                    }
                    $seasonFolders[] = $f;
                    continue;
                }
                // Fetch season poster from TMDB
                poster_log('SEASON fetch | ' . $f . ' num=' . $seasonNum . ' parentId=' . $parentTmdbId . (($row ? ' stale_id=' . ($row['tmdb_id'] ?? 'null') : ' no_cache')));
                usleep(50000); // rate limit TMDB
                $seasonUrl = "https://api.themoviedb.org/3/tv/{$parentTmdbId}/season/{$seasonNum}?api_key={$apiKey}&language=fr";
                $sCtx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
                $sResp = @file_get_contents($seasonUrl, false, $sCtx);
                $sData = $sResp ? json_decode($sResp, true) : null;
                $posterUrl = null;
                $seasonTitle = null;
                $seasonOverview = null;
                if ($sData && !empty($sData['poster_path'])) {
                    $posterUrl = 'https://image.tmdb.org/t/p/w300' . $sData['poster_path'];
                    $seasonTitle = $sData['name'] ?? null;
                    $seasonOverview = $sData['overview'] ?? null;
                }
                try {
                    $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_type) VALUES (:p, :u, :i, :t, :o, :y, :mt)
                              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, tmdb_year = :y, tmdb_type = :mt, updated_at = datetime('now')")
                       ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $parentTmdbId, ':t' => $seasonTitle, ':o' => $seasonOverview, ':y' => null, ':mt' => 'tv']);
                    poster_log('SEASON DB write | ' . $f . ' → ' . ($posterUrl ? $seasonTitle : 'NULL'));
                } catch (PDOException $e) { poster_log('SEASON DB error | ' . $f . ' → ' . $e->getMessage()); }
                if ($posterUrl) {
                    $result[$f] = ['poster' => $posterUrl];
                    if ($seasonOverview) $result[$f]['overview'] = $seasonOverview;
                }
                $seasonFolders[] = $f;
            }
        }
    }

    // ── Phase 1 : séparer cached / uncached ──
    $cached = [];
    $uncached = [];
    // Track poster URLs to detect duplicates (e.g. 170 episodes with same poster)
    $posterCount = []; // poster_url => count

    // Filter eligible folders first (skip seasons + skipPattern), then batch-query DB
    $eligibleFolders = [];
    foreach ($folders as $f) {
        if (in_array($f, $seasonFolders, true) || preg_match($skipPattern, $f)) continue;
        $eligibleFolders[] = $f;
    }

    $folderDbRows = [];
    if (!empty($eligibleFolders)) {
        $folderPaths = array_map(fn($f) => realpath($resolvedPath . '/' . $f) ?: ($resolvedPath . '/' . $f), $eligibleFolders);
        $ph = implode(',', array_fill(0, count($folderPaths), '?'));
        $stmt = $db->prepare("SELECT path, poster_url, overview, verified, tmdb_year, tmdb_rating FROM folder_posters WHERE path IN ($ph)");
        $stmt->execute($folderPaths);
        foreach ($stmt->fetchAll() as $row) {
            $folderDbRows[$row['path']] = $row;
        }
    }

    $hiddenCount = 0; $posterHitCount = 0; $pendingCount = 0;
    foreach ($eligibleFolders as $f) {
        $fullPath = realpath($resolvedPath . '/' . $f) ?: ($resolvedPath . '/' . $f);
        $row = $folderDbRows[$fullPath] ?? null;
        if ($row) {
            if ($row['poster_url'] === '__none__') {
                $cached[$f] = ['hidden' => true];
                $hiddenCount++;
            } elseif ($row['poster_url']) {
                $cached[$f] = ['poster' => $row['poster_url']];
                if ($row['overview']) $cached[$f]['overview'] = $row['overview'];
                if ($row['tmdb_year']) $cached[$f]['year'] = $row['tmdb_year'];
                if ($row['tmdb_rating'] > 0) $cached[$f]['rating'] = (float)$row['tmdb_rating'];
                $posterCount[$row['poster_url']] = ($posterCount[$row['poster_url']] ?? 0) + 1;
                $posterHitCount++;
            } elseif ((int)($row['verified'] ?? 0) === -1) {
                // poster_url NULL + verified=-1 → user requested AI recheck
                $cached[$f] = ['pending_ai' => true];
                $pendingCount++;
            }
            // poster_url NULL + verified=0 → déjà cherché, pas trouvé. Le cron IA s'en charge.
        } else {
            $uncached[] = $f;
        }
    }
    poster_log('POSTERS cache | eligible=' . count($eligibleFolders) . ' cached=' . $posterHitCount . ' hidden=' . $hiddenCount . ' pending_ai=' . $pendingCount . ' uncached=' . count($uncached) . ' seasons=' . count($seasonFolders));

    $result = array_merge($result, $cached);

    // ── INSERT all uncached folders as NULL (no TMDB calls, instant) ──
    if (!empty($uncached)) {
        try { $db->beginTransaction(); } catch (PDOException $e) {}
        foreach ($uncached as $f) {
            $fullPath = realpath($resolvedPath . '/' . $f) ?: ($resolvedPath . '/' . $f);
            try {
                $db->prepare("INSERT OR IGNORE INTO folder_posters (path) VALUES (:p)")->execute([':p' => $fullPath]);
            } catch (PDOException $e) {}
        }
        try { $db->commit(); } catch (PDOException $e) {}
    }

    // ── Video file posters (movies-type folders) — DB read only ──
    $stmtType = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
    $stmtType->execute([':p' => $resolvedPath]);
    $typeRow = $stmtType->fetch();
    $isMovies = ($typeRow && ($typeRow['folder_type'] ?? 'series') === 'movies');


    if ($isMovies) {
        $videoExts = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];
        $videoFiles = [];
        foreach ($items as $item) {
            if ($item[0] === '.' || is_dir($resolvedPath . '/' . $item)) continue;
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $videoExts, true)) $videoFiles[] = $item;
        }

        // Batch query DB
        $videoPaths = array_map(fn($vf) => $resolvedPath . '/' . $vf, $videoFiles);
        $videoDbRows = [];
        if (!empty($videoPaths)) {
            $ph = implode(',', array_fill(0, count($videoPaths), '?'));
            $stmt = $db->prepare("SELECT path, poster_url, overview, verified, tmdb_year, tmdb_rating FROM folder_posters WHERE path IN ($ph)");
            $stmt->execute($videoPaths);
            foreach ($stmt->fetchAll() as $row) $videoDbRows[$row['path']] = $row;
        }

        $videoUncached = [];
        foreach ($videoFiles as $vf) {
            $fullPath = $resolvedPath . '/' . $vf;
            $row = $videoDbRows[$fullPath] ?? null;
            if ($row) {
                if ($row['poster_url'] === '__none__') {
                    $result[$vf] = ['hidden' => true];
                } elseif ($row['poster_url']) {
                    $result[$vf] = ['poster' => $row['poster_url'], 'confidence' => (int)($row['verified'] ?? 0)];
                    if ($row['overview']) $result[$vf]['overview'] = $row['overview'];
                    if ($row['tmdb_year']) $result[$vf]['year'] = $row['tmdb_year'];
                    if ($row['tmdb_rating'] > 0) $result[$vf]['rating'] = (float)$row['tmdb_rating'];
                    if (isset($watchedPaths[$fullPath])) $result[$vf]['watched'] = true;
                } elseif ((int)($row['verified'] ?? 0) === -1) {
                    $result[$vf] = ['pending_ai' => true];
                }
            } else {
                $videoUncached[] = $vf;
            }
        }

        // INSERT all uncached video files as NULL (no TMDB calls)
        if (!empty($videoUncached)) {
            try { $db->beginTransaction(); } catch (PDOException $e) {}
            foreach ($videoUncached as $vf) {
                try {
                    $db->prepare("INSERT OR IGNORE INTO folder_posters (path) VALUES (:p)")
                       ->execute([':p' => $resolvedPath . '/' . $vf]);
                } catch (PDOException $e) {}
            }
            try { $db->commit(); } catch (PDOException $e) {}
        }
    }

    // Count NULLs for JS polling (daemon handles the actual work)
    $stmtNull = $db->prepare("SELECT COUNT(*) FROM folder_posters WHERE path LIKE :prefix AND poster_url IS NULL AND (match_attempts IS NULL OR match_attempts = 0)");
    $stmtNull->execute([':prefix' => $resolvedPath . '/%']);
    $nullCount = (int)$stmtNull->fetchColumn();

    // Ensure worker is running (start if not) — same lock file as the worker
    if ($nullCount > 0) {
        $workerLock = dirname(DB_PATH) . '/sharebox_tmdb_cron.lock';
        @chmod($workerLock, 0666);
        $lockFp = @fopen($workerLock, 'c'); // 'c': create if missing, no truncate
        if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
            // Lock acquired = worker not running, start it
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            $scriptPath = realpath(__DIR__ . '/../tools/tmdb-worker.php');
            exec('/usr/bin/php ' . escapeshellarg($scriptPath) . ' >> ' . escapeshellarg(dirname(DB_PATH) . '/tmdb-worker.log') . ' 2>&1 &');
            poster_log('WORKER started by web request');
        } else {
            if ($lockFp) fclose($lockFp);
            poster_log('WORKER already running');
        }
    }

    $json = json_encode(['posters' => $result, 'pending' => $nullCount], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        poster_log('JSON ERROR | ' . json_last_error_msg());
        $json = json_encode(['posters' => [], 'pending' => $nullCount]);
    }
    poster_log('POSTERS response | result=' . count($result) . ' pending=' . $nullCount . ' json_len=' . strlen($json));
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// ── Search TMDB for a folder name (manual selection) ──
if (isset($_GET['tmdb_search'])) {
    $searchName = trim($_GET['tmdb_search']);
    if (!$searchName) { echo '[]'; exit; }

    $searchType = $_GET['tmdb_type'] ?? 'multi';
    if (!in_array($searchType, ['multi', 'tv', 'movie', 'company'], true)) $searchType = 'multi';
    poster_log('SEARCH manual | query="' . $searchName . '" type=' . $searchType);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $results = [];

    // ── Multi-source search for studios/companies ──
    if ($searchType === 'company') {
        // 1. TMDB Collections (often have nice artwork for studio collections)
        $query = urlencode($searchName);
        $collUrl = "https://api.themoviedb.org/3/search/collection?api_key={$apiKey}&query={$query}&language=fr&page=1";
        $collResp = @file_get_contents($collUrl, false, $ctx);
        $collData = $collResp ? json_decode($collResp, true) : null;
        if ($collData && !empty($collData['results'])) {
            foreach (array_slice($collData['results'], 0, 3) as $r) {
                if (empty($r['poster_path'])) continue;
                $results[] = [
                    'id' => $r['id'],
                    'title' => ($r['name'] ?? '?') . ' (Collection)',
                    'year' => '',
                    'type' => 'collection',
                    'poster' => 'https://image.tmdb.org/t/p/w200' . $r['poster_path'],
                    'poster_w300' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                    'overview' => $r['overview'] ?? '',
                    'rating' => 0,
                ];
            }
        }

        // 2. TMDB Movies/TV (documentaries, branded content)
        $multiUrl = "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$query}&language=fr&page=1";
        $multiResp = @file_get_contents($multiUrl, false, $ctx);
        $multiData = $multiResp ? json_decode($multiResp, true) : null;
        if ($multiData && !empty($multiData['results'])) {
            foreach (array_slice($multiData['results'], 0, 3) as $r) {
                if (empty($r['poster_path'])) continue;
                $results[] = [
                    'id' => $r['id'],
                    'title' => $r['title'] ?? $r['name'] ?? '?',
                    'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                    'type' => $r['media_type'] ?? 'movie',
                    'poster' => 'https://image.tmdb.org/t/p/w200' . $r['poster_path'],
                    'poster_w300' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                    'overview' => $r['overview'] ?? '',
                    'rating' => round((float)($r['vote_average'] ?? 0), 1),
                ];
            }
        }

        // 3. Wikimedia Commons (high-quality logos)
        $wikiResults = search_wikimedia_logos($searchName, $ctx);
        $results = array_merge($results, $wikiResults);

        // 4. TMDB Company (logos, as fallback)
        $compUrl = "https://api.themoviedb.org/3/search/company?api_key={$apiKey}&query={$query}&page=1";
        $compResp = @file_get_contents($compUrl, false, $ctx);
        $compData = $compResp ? json_decode($compResp, true) : null;
        if ($compData && !empty($compData['results'])) {
            foreach (array_slice($compData['results'], 0, 2) as $r) {
                if (empty($r['logo_path'])) continue;
                $results[] = [
                    'id' => $r['id'],
                    'title' => ($r['name'] ?? '?') . ' (Logo)',
                    'year' => $r['origin_country'] ?? '',
                    'type' => 'company',
                    'poster' => 'https://image.tmdb.org/t/p/w200' . $r['logo_path'],
                    'poster_w300' => 'https://image.tmdb.org/t/p/w300' . $r['logo_path'],
                    'overview' => '',
                    'rating' => 0,
                ];
            }
        }

        // Limit total results
        $results = array_slice($results, 0, 12);
    } else {
        // ── Standard TMDB search (multi/tv/movie) ──
        $query = urlencode($searchName);
        $url = "https://api.themoviedb.org/3/search/{$searchType}?api_key={$apiKey}&query={$query}&language=fr&page=1";
        $resp = @file_get_contents($url, false, $ctx);
        $data = $resp ? json_decode($resp, true) : null;

        if ($data && !empty($data['results'])) {
            foreach (array_slice($data['results'], 0, 8) as $r) {
                if (empty($r['poster_path'])) continue;
                $results[] = [
                    'id' => $r['id'],
                    'title' => $r['title'] ?? $r['name'] ?? '?',
                    'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                    'type' => $r['media_type'] ?? ($searchType === 'multi' ? '?' : $searchType),
                    'poster' => 'https://image.tmdb.org/t/p/w200' . $r['poster_path'],
                    'poster_w300' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                    'overview' => $r['overview'] ?? '',
                    'rating' => round((float)($r['vote_average'] ?? 0), 1),
                ];
            }
        }
    }

    echo json_encode($results);
    exit;
}

// ── Set poster for a folder ──
if (isset($_GET['tmdb_set']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $folder = $input['folder'] ?? '';
    $posterUrl = $input['poster_url'] ?? '';
    $tmdbId = (int)($input['tmdb_id'] ?? 0);
    $title = $input['title'] ?? '';
    $overview = $input['overview'] ?? '';
    $year = $input['year'] ?? null;
    $rating = isset($input['rating']) && $input['rating'] > 0 ? round((float)$input['rating'], 1) : null;

    if (!$folder) { http_response_code(400); echo json_encode(['error' => 'missing folder']); exit; }

    $safeName   = basename($folder);
    $dbPath     = $resolvedPath . '/' . $safeName;
    $realPath   = realpath($dbPath);
    if (!$realPath || !is_path_within($realPath, $resolvedPath)) { http_response_code(403); echo json_encode(['error' => 'path not allowed']); exit; }
    if (!is_dir($dbPath) && !is_file($dbPath)) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
    $fullPath = $realPath; // realpath pour cohérence avec les lookups (résout les symlinks NVMe)

    try {
        if (!$posterUrl) {
            // Reset poster to NULL for re-fetch, keep folder_type
            poster_log('SET reset | ' . $folder . ' → clearing poster for re-fetch');
            $db->prepare("INSERT INTO folder_posters (path) VALUES (:p) ON CONFLICT(path) DO UPDATE SET poster_url = NULL, tmdb_id = NULL, title = NULL, overview = NULL, verified = 0, match_attempts = 0, updated_at = datetime('now')")
               ->execute([':p' => $fullPath]);
        } else {
            poster_log('SET poster | ' . $folder . ' → ' . ($title ?: '?') . ' (id=' . $tmdbId . ') ' . ($posterUrl === '__none__' ? '__none__' : 'poster') . ' verified=100');
            $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, tmdb_year, tmdb_rating, verified) VALUES (:p, :u, :i, :t, :o, :y, :r, 100)
              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, tmdb_year = :y, tmdb_rating = :r, verified = 100, updated_at = datetime('now')")
               ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $title, ':o' => $overview, ':y' => $year, ':r' => $rating]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        poster_log('SET error | ' . $folder . ' → ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'db error']);
    }
    exit;
}

// ── Set folder type (series/movies) ──
if (isset($_GET['folder_type_set']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $folder = $input['folder'] ?? '';
    $type = $input['folder_type'] ?? 'series';

    if (!$folder) { http_response_code(400); echo json_encode(['error' => 'missing folder']); exit; }
    if (!in_array($type, ['series', 'movies'], true)) { http_response_code(400); echo json_encode(['error' => 'invalid type']); exit; }

    $safeName2 = basename($folder);
    $dbPath2   = $resolvedPath . '/' . $safeName2;
    $realPath2 = realpath($dbPath2);
    if (!$realPath2 || !is_path_within($realPath2, $resolvedPath)) { http_response_code(403); echo json_encode(['error' => 'path not allowed']); exit; }
    if (!is_dir($dbPath2)) { http_response_code(404); echo json_encode(['error' => 'folder not found']); exit; }
    $fullPath = $realPath2; // use realpath to match what download.php resolves when navigating in

    try {
        poster_log('TYPE set | ' . $folder . ' → ' . $type);
        $db->prepare("INSERT INTO folder_posters (path, folder_type) VALUES (:p, :t)
                      ON CONFLICT(path) DO UPDATE SET folder_type = :t, updated_at = datetime('now')")
           ->execute([':p' => $fullPath, ':t' => $type]);
        echo json_encode(['success' => true, 'folder_type' => $type]);
    } catch (PDOException $e) {
        poster_log('DB error TYPE set | ' . $folder . ' → ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'db error']);
    }
    exit;
}

// ── Request AI recheck for a file/folder poster ──
if (isset($_GET['ai_recheck']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';

    if (!$name) { http_response_code(400); echo json_encode(['error' => 'missing name']); exit; }

    $fullPath = realpath($resolvedPath . '/' . $name);
    if (!$fullPath || !is_path_within($fullPath, $resolvedPath)) { http_response_code(403); echo json_encode(['error' => 'path not allowed']); exit; }
    if (!is_dir($fullPath) && !is_file($fullPath)) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    try {
        // Mark as pending AI (verified=-1) so cron re-processes it
        // INSERT si pas d'entrée, UPDATE si existante
        // __none__ + verified=100 = choix humain définitif → jamais toucher
        // __none__ + verified<100 = skip automatique (skill) → réinitialisable
        poster_log('AI recheck | ' . $name . ' → reset to pending (verified=-1, poster=NULL, match_attempts=0)');
        $db->prepare("INSERT INTO folder_posters (path, poster_url, verified, match_attempts, ia_checked) VALUES (:p, NULL, -1, 0, 0)
                      ON CONFLICT(path) DO UPDATE SET poster_url = CASE WHEN poster_url = '__none__' AND verified = 100 THEN '__none__' ELSE NULL END,
                      verified = CASE WHEN poster_url = '__none__' AND verified = 100 THEN verified ELSE -1 END,
                      match_attempts = CASE WHEN poster_url = '__none__' AND verified = 100 THEN match_attempts ELSE 0 END,
                      ia_checked = CASE WHEN poster_url = '__none__' AND verified = 100 THEN ia_checked ELSE 0 END")
           ->execute([':p' => $fullPath]);
        // Launch cron if not already running
        $scriptPath = realpath(__DIR__ . '/../tools/tmdb-worker.php');
        $lockFile = dirname(DB_PATH) . '/sharebox_tmdb_cron.lock';
        $lockFp = @fopen($lockFile, 'c');
        if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
            flock($lockFp, LOCK_UN); fclose($lockFp);
            exec('/usr/bin/php ' . escapeshellarg($scriptPath) . ' >> ' . escapeshellarg(dirname(DB_PATH) . '/tmdb-worker.log') . ' 2>&1 &');
            poster_log('AI cron triggered by recheck');
        } else {
            if ($lockFp) fclose($lockFp);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        poster_log('DB error AI recheck | ' . $name . ' → ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'db error']);
    }
    exit;
}

// ── Reload all posters in current directory ──
if (isset($_GET['tmdb_reload']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Supprimer toutes les entrées sauf __none__ humain (verified=100) pour ce dossier
        // __none__ automatique (verified<100) est supprimé aussi pour permettre une re-détection
        $stmt = $db->prepare("DELETE FROM folder_posters WHERE path LIKE :prefix AND NOT (poster_url = '__none__' AND verified = 100)");
        $stmt->execute([':prefix' => $resolvedPath . '/%']);
        $deleted = $stmt->rowCount();
        // Supprimer aussi l'entrée du dossier lui-même (pour re-fetch le parent)
        $stmt2 = $db->prepare("DELETE FROM folder_posters WHERE path = :p AND NOT (poster_url = '__none__' AND verified = 100)");
        $stmt2->execute([':p' => $resolvedPath]);
        $deleted += $stmt2->rowCount();
        poster_log('RELOAD all | ' . basename($resolvedPath) . ' | deleted=' . $deleted . ' entries');
        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } catch (PDOException $e) {
        poster_log('RELOAD error | ' . basename($resolvedPath) . ' → ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'db error']);
    }
    exit;
}

/**
 * Search Wikimedia Commons for studio logos
 * Returns array of results formatted like TMDB results
 */
function search_wikimedia_logos(string $studioName, $ctx): array {
    $results = [];

    // Search for logo files on Wikimedia Commons
    $searchQuery = urlencode($studioName . ' logo');
    $searchUrl = "https://commons.wikimedia.org/w/api.php?action=query&list=search&srsearch={$searchQuery}&srnamespace=6&format=json&srlimit=3";

    $searchResp = @file_get_contents($searchUrl, false, $ctx);
    $searchData = $searchResp ? json_decode($searchResp, true) : null;

    if (!$searchData || empty($searchData['query']['search'])) {
        return [];
    }

    // Get image URLs for each result
    foreach ($searchData['query']['search'] as $item) {
        $title = $item['title'] ?? '';
        if (!$title) continue;

        // Get image info
        $infoUrl = "https://commons.wikimedia.org/w/api.php?action=query&titles=" . urlencode($title) . "&prop=imageinfo&iiprop=url|size&format=json";
        $infoResp = @file_get_contents($infoUrl, false, $ctx);
        $infoData = $infoResp ? json_decode($infoResp, true) : null;

        if (!$infoData || empty($infoData['query']['pages'])) continue;

        $page = reset($infoData['query']['pages']);
        if (empty($page['imageinfo'][0]['url'])) continue;

        $imageUrl = $page['imageinfo'][0]['url'];
        $width = $page['imageinfo'][0]['width'] ?? 0;
        $height = $page['imageinfo'][0]['height'] ?? 0;

        // Skip very small images (likely icons)
        if ($width < 200 || $height < 100) continue;

        // Skip very wide images (landscape logos don't work well in grid)
        if ($width > 0 && $height > 0 && ($width / $height) > 3) continue;

        // Clean title for display
        $displayTitle = str_replace(['File:', '.svg', '.png', '.jpg'], '', $title);
        $displayTitle = str_replace('_', ' ', $displayTitle);

        $results[] = [
            'id' => 0, // No TMDB ID for Wikimedia
            'title' => $displayTitle . ' (Wikimedia)',
            'year' => '',
            'type' => 'wikimedia',
            'poster' => $imageUrl,
            'poster_w300' => $imageUrl, // Wikimedia serves original size, browser will scale
            'overview' => 'Logo depuis Wikimedia Commons',
            'rating' => 0,
        ];

        if (count($results) >= 3) break; // Max 3 Wikimedia results
    }

    return $results;
}

echo json_encode(['error' => 'unknown tmdb action']);
exit;
