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
        . '|movies?|films?|s(?:é|e)ries?|covers?|images?|photos?|videos?|samples?|nfo'
        . '|wii|wiiu|switch|nds|3ds|cia|gba|n64|snes|nes|psp|ps[1-4]|xbox'
        . '|bdmv|clipinf|playlist|stream$|meta|backup|certificate'
        . ')/iu';
    // Sous-ensemble du skipPattern : dossiers de saison (on peut leur trouver un poster TMDB via le parent)
    $seasonPattern = '/(?:^|\b)(?:season|saison|s)[\s._-]*(\d+)/i';

    $result = [];
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
    if (empty($folders) && !$isMoviesEarly) { echo json_encode(['posters' => [], 'remaining' => 0]); exit; }

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
                        $result[$f] = ['poster' => $row['poster_url']];
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
        $folderPaths = array_map(fn($f) => $resolvedPath . '/' . $f, $eligibleFolders);
        $ph = implode(',', array_fill(0, count($folderPaths), '?'));
        $stmt = $db->prepare("SELECT path, poster_url, overview, verified FROM folder_posters WHERE path IN ($ph)");
        $stmt->execute($folderPaths);
        foreach ($stmt->fetchAll() as $row) {
            $folderDbRows[$row['path']] = $row;
        }
    }

    $hiddenCount = 0; $posterHitCount = 0; $pendingCount = 0;
    foreach ($eligibleFolders as $f) {
        $fullPath = $resolvedPath . '/' . $f;
        $row = $folderDbRows[$fullPath] ?? null;
        if ($row) {
            if ($row['poster_url'] === '__none__') {
                $cached[$f] = ['hidden' => true];
                $hiddenCount++;
            } elseif ($row['poster_url']) {
                $cached[$f] = ['poster' => $row['poster_url']];
                if ($row['overview']) $cached[$f]['overview'] = $row['overview'];
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

    // Filter out duplicate posters (> 3 cards with same image = probably episodes)
    foreach ($cached as $f => $info) {
        if (isset($info['poster']) && ($posterCount[$info['poster']] ?? 0) > 3) {
            unset($cached[$f]);
        }
    }

    $result = array_merge($result, $cached);

    // ── INSERT all uncached folders as NULL (no TMDB calls, instant) ──
    if (!empty($uncached)) {
        try { $db->beginTransaction(); } catch (PDOException $e) {}
        foreach ($uncached as $f) {
            $fullPath = $resolvedPath . '/' . $f;
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
            $stmt = $db->prepare("SELECT path, poster_url, overview, verified FROM folder_posters WHERE path IN ($ph)");
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
                    $result[$vf] = ['poster' => $row['poster_url']];
                    if ($row['overview']) $result[$vf]['overview'] = $row['overview'];
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

    // ── Trigger background worker if any NULLs need processing ──
    $stmtNull = $db->prepare("SELECT COUNT(*) FROM folder_posters WHERE path LIKE :prefix AND poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)");
    $stmtNull->execute([':prefix' => $resolvedPath . '/%']);
    $nullCount = (int)$stmtNull->fetchColumn();
    if ($nullCount > 0) {
        $lockFile = sys_get_temp_dir() . '/sharebox_ai_' . md5($resolvedPath) . '.lock';
        $lockFp = @fopen($lockFile, 'w');
        if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
            poster_log('BG trigger | ' . $nullCount . ' NULL entries → launching --pending-path ' . basename($resolvedPath));
            $scriptPath = realpath(__DIR__ . '/../tools/ai-titles.php');
            $cmd = 'sudo -u copain /usr/bin/php ' . escapeshellarg($scriptPath) . ' --pending-path ' . escapeshellarg($resolvedPath) . ' >> /srv/share/data/ai-titles.log 2>&1 &';
            @pclose(@popen($cmd, 'r'));
        }
        if ($lockFp) fclose($lockFp);
    }

    poster_log('POSTERS response | result=' . count($result) . ' pending=' . $nullCount);
    echo json_encode(['posters' => $result, 'pending' => $nullCount]);
    exit;
}

// ── Search TMDB for a folder name (manual selection) ──
if (isset($_GET['tmdb_search'])) {
    $searchName = trim($_GET['tmdb_search']);
    if (!$searchName) { echo '[]'; exit; }

    $searchType = $_GET['tmdb_type'] ?? 'multi';
    if (!in_array($searchType, ['multi', 'tv', 'movie'], true)) $searchType = 'multi';
    poster_log('SEARCH manual | query="' . $searchName . '" type=' . $searchType);
    $query = urlencode($searchName);
    $url = "https://api.themoviedb.org/3/search/{$searchType}?api_key={$apiKey}&query={$query}&language=fr&page=1";

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;

    $results = [];
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
            ];
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

    if (!$folder) { http_response_code(400); echo json_encode(['error' => 'missing folder']); exit; }

    $fullPath = realpath($resolvedPath . '/' . $folder);
    if (!$fullPath || !is_path_within($fullPath, $resolvedPath)) { http_response_code(403); echo json_encode(['error' => 'path not allowed']); exit; }
    if (!is_dir($fullPath) && !is_file($fullPath)) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    try {
        if (!$posterUrl) {
            poster_log('SET delete | ' . $folder . ' → clearing DB entry for re-fetch');
            $db->prepare("DELETE FROM folder_posters WHERE path = :p")->execute([':p' => $fullPath]);
        } else {
            poster_log('SET poster | ' . $folder . ' → ' . ($title ?: '?') . ' (id=' . $tmdbId . ') ' . ($posterUrl === '__none__' ? '__none__' : 'poster') . ' verified=1');
            $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified) VALUES (:p, :u, :i, :t, :o, 1)
              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 1, updated_at = datetime('now')")
               ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $title, ':o' => $overview]);
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

    $fullPath = realpath($resolvedPath . '/' . $folder);
    if (!$fullPath || !is_path_within($fullPath, $resolvedPath)) { http_response_code(403); echo json_encode(['error' => 'path not allowed']); exit; }
    if (!is_dir($fullPath)) { http_response_code(404); echo json_encode(['error' => 'folder not found']); exit; }

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
        // INSERT si pas d'entrée, UPDATE si existante (sauf __none__ = choix humain)
        poster_log('AI recheck | ' . $name . ' → reset to pending (verified=-1, poster=NULL, ai_attempts=0)');
        $db->prepare("INSERT INTO folder_posters (path, poster_url, verified, ai_attempts) VALUES (:p, NULL, -1, 0)
                      ON CONFLICT(path) DO UPDATE SET poster_url = CASE WHEN poster_url = '__none__' THEN '__none__' ELSE NULL END,
                      verified = CASE WHEN poster_url = '__none__' THEN verified ELSE -1 END,
                      ai_attempts = CASE WHEN poster_url = '__none__' THEN ai_attempts ELSE 0 END")
           ->execute([':p' => $fullPath]);
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
        // Supprimer toutes les entrées sauf __none__ (choix humain) pour ce dossier
        $stmt = $db->prepare("DELETE FROM folder_posters WHERE path LIKE :prefix AND poster_url != '__none__'");
        $stmt->execute([':prefix' => $resolvedPath . '/%']);
        $deleted = $stmt->rowCount();
        // Supprimer aussi l'entrée du dossier lui-même (pour re-fetch le parent)
        $stmt2 = $db->prepare("DELETE FROM folder_posters WHERE path = :p AND poster_url != '__none__'");
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

echo json_encode(['error' => 'unknown tmdb action']);
exit;
