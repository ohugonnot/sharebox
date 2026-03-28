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

    // Noms de dossiers qui ne sont PAS des titres de média → pas de fetch TMDB
    $skipPattern = '/^(season\s*\d|saison\s*\d|s\d+e?\d*|ova|oav|bonus|extras?|special|specials|featurettes?|behind.the.scenes|deleted.scenes|interviews?|trailers?|nc|op|ed|ost|soundtrack|subs?|subtitles?|vostfr|vf|multi|disc\s*\d|cd\s*\d|dvd|blu-?ray|\d{1,2}$)/i';
    // Sous-ensemble du skipPattern : dossiers de saison (on peut leur trouver un poster TMDB via le parent)
    $seasonPattern = '/(?:^|\b)(?:season|saison|s)[\s._-]*(\d+)/i';

    $result = [];
    $items = scandir($resolvedPath);
    $folders = [];
    foreach ($items as $item) {
        if ($item[0] === '.' || !is_dir($resolvedPath . '/' . $item)) continue;
        $folders[] = $item;
    }

    if (empty($folders)) { echo json_encode(['posters' => [], 'remaining' => 0]); exit; }

    // ── Season subfolders : poster via parent tmdb_id + /tv/{id}/season/{n} ──
    // Le dossier parent (= $resolvedPath) doit avoir un tmdb_id en cache
    $parentTmdbId = null;
    $stmtParent = $db->prepare("SELECT tmdb_id FROM folder_posters WHERE path = :p AND tmdb_id IS NOT NULL");
    $stmtParent->execute([':p' => $resolvedPath]);
    $parentRow = $stmtParent->fetch();
    if ($parentRow) $parentTmdbId = (int)$parentRow['tmdb_id'];

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
                    $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
                       ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $parentTmdbId, ':t' => $seasonTitle, ':o' => $seasonOverview]);
                } catch (PDOException $e) { /* ignore */ }
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
    foreach ($folders as $f) {
        if (in_array($f, $seasonFolders, true) || preg_match($skipPattern, $f)) continue;
        $fullPath = $resolvedPath . '/' . $f;
        $stmt = $db->prepare("SELECT poster_url, overview FROM folder_posters WHERE path = :p");
        $stmt->execute([':p' => $fullPath]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['poster_url'] === '__none__') {
                $cached[$f] = ['hidden' => true];
            } elseif ($row['poster_url']) {
                $cached[$f] = ['poster' => $row['poster_url']];
                if ($row['overview']) $cached[$f]['overview'] = $row['overview'];
                $posterCount[$row['poster_url']] = ($posterCount[$row['poster_url']] ?? 0) + 1;
            }
        } else {
            $uncached[] = $f;
        }
    }

    // Filter out duplicate posters (> 3 cards with same image = probably episodes)
    foreach ($cached as $f => $info) {
        if (isset($info['poster']) && ($posterCount[$info['poster']] ?? 0) > 3) {
            unset($cached[$f]);
        }
    }

    $result = array_merge($result, $cached);

    // ── Phase 2 : extraire titres uniques depuis TOUS les uncached ──
    $titleToFolders = []; // "Black Clover" => ["E001...", "E002...", ...]
    foreach ($uncached as $f) {
        $meta = extract_title_year($f);
        $title = $meta['title'];
        $titleToFolders[$title][] = $f;
    }

    // Pre-seed from cached siblings: if a title is already resolved in DB, reuse it
    $titleResults = []; // title => {posterUrl, tmdbId, tmdbTitle, tmdbOverview}
    foreach ($cached as $name => $info) {
        if (!isset($info['poster'])) continue;
        $sibTitle = extract_title_year($name)['title'];
        if (isset($titleToFolders[$sibTitle]) && !isset($titleResults[$sibTitle])) {
            $sibPath = $resolvedPath . '/' . $name;
            $stmtSib = $db->prepare("SELECT poster_url, tmdb_id, title, overview FROM folder_posters WHERE path = :p");
            $stmtSib->execute([':p' => $sibPath]);
            $sibRow = $stmtSib->fetch();
            if ($sibRow && $sibRow['poster_url']) {
                $titleResults[$sibTitle] = ['posterUrl' => $sibRow['poster_url'], 'tmdbId' => $sibRow['tmdb_id'], 'tmdbTitle' => $sibRow['title'], 'tmdbOverview' => $sibRow['overview']];
            }
        }
    }

    // Titles that still need a TMDB search
    $titlesToSearch = array_diff_key($titleToFolders, $titleResults);

    // ── Phase 3 : chercher TMDB pour max 10 titres uniques ──
    $toSearch = array_slice(array_keys($titlesToSearch), 0, 10);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    foreach ($toSearch as $title) {
        $queries = [$title];
        $shorter = preg_replace('/\b(hd|remasted|remastered|complete|integrale|intégrale|collection|pack|coffret)\b.*/i', '', $title);
        $shorter = trim($shorter);
        if ($shorter !== '' && $shorter !== $title) $queries[] = $shorter;
        $words = explode(' ', $title);
        if (count($words) > 3) {
            $half = implode(' ', array_slice($words, 0, (int)ceil(count($words) / 2)));
            if ($half !== $title && $half !== $shorter) $queries[] = $half;
        }

        $posterUrl = null;
        $tmdbId = null;
        $tmdbTitle = null;
        $tmdbOverview = null;

        foreach ($queries as $q) {
            $encoded = urlencode($q);
            $urls = [
                "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
                "https://api.themoviedb.org/3/search/tv?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
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

        $titleResults[$title] = ['posterUrl' => $posterUrl, 'tmdbId' => $tmdbId, 'tmdbTitle' => $tmdbTitle, 'tmdbOverview' => $tmdbOverview];
    }

    // ── Phase 4 : dispatcher les résultats sur tous les dossiers ──
    // Si un même titre a > 3 dossiers, c'est probablement des épisodes :
    // on stocke en DB (cache, pas de re-fetch) mais on n'affiche pas le poster
    // (le cron IA tranchera plus tard)
    foreach ($titleResults as $title => $tr) {
        if (!isset($titleToFolders[$title])) continue;
        $folderList = $titleToFolders[$title];
        $isDuplicate = count($folderList) > 3;
        foreach ($folderList as $folderName) {
            $fullPath = $resolvedPath . '/' . $folderName;
            try {
                $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)
                              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, updated_at = datetime('now')")
                   ->execute([':p' => $fullPath, ':u' => $tr['posterUrl'], ':i' => $tr['tmdbId'], ':t' => $tr['tmdbTitle'], ':o' => $tr['tmdbOverview']]);
            } catch (PDOException $e) { /* ignore */ }
            // N'afficher le poster que si c'est pas un doublon massif
            if ($tr['posterUrl'] && !$isDuplicate) {
                $result[$folderName] = ['poster' => $tr['posterUrl']];
                if ($tr['tmdbOverview']) $result[$folderName]['overview'] = $tr['tmdbOverview'];
            }
        }
    }

    // remaining = titres uniques pas encore cherchés
    $remaining = count($titlesToSearch) - count($toSearch);

    // ── Video file posters (movies-type folders) ──
    $stmtType = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
    $stmtType->execute([':p' => $resolvedPath]);
    $typeRow = $stmtType->fetch();
    $isMovies = ($typeRow && ($typeRow['folder_type'] ?? 'series') === 'movies');

    $videoRemaining = 0;
    if ($isMovies) {
        $videoExts = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];
        $videoFiles = [];
        foreach ($items as $item) {
            if ($item[0] === '.' || is_dir($resolvedPath . '/' . $item)) continue;
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $videoExts, true)) {
                $videoFiles[] = $item;
            }
        }

        $videoCached = [];
        $videoUncached = [];
        // Batch query all video file paths at once (avoid N+1)
        $videoPaths = array_map(fn($vf) => $resolvedPath . '/' . $vf, $videoFiles);
        $videoDbRows = [];
        if (!empty($videoPaths)) {
            $ph = implode(',', array_fill(0, count($videoPaths), '?'));
            $stmt = $db->prepare("SELECT path, poster_url, overview FROM folder_posters WHERE path IN ($ph)");
            $stmt->execute($videoPaths);
            foreach ($stmt->fetchAll() as $row) {
                $videoDbRows[$row['path']] = $row;
            }
        }
        foreach ($videoFiles as $vf) {
            $fullPath = $resolvedPath . '/' . $vf;
            $row = $videoDbRows[$fullPath] ?? null;
            if ($row) {
                if ($row['poster_url'] === '__none__') {
                    $videoCached[$vf] = ['hidden' => true];
                } elseif ($row['poster_url']) {
                    $videoCached[$vf] = ['poster' => $row['poster_url']];
                    if ($row['overview']) $videoCached[$vf]['overview'] = $row['overview'];
                }
            } else {
                $videoUncached[] = $vf;
            }
        }

        $result = array_merge($result, $videoCached);

        $videoToFetch = array_slice($videoUncached, 0, 10);
        foreach ($videoToFetch as $fileName) {
            $meta = extract_title_year($fileName);
            $title = $meta['title'];

            $queries = [$title];
            $shorter = preg_replace('/\b(hd|remasted|remastered|complete|integrale|intégrale|collection|pack|coffret)\b.*/i', '', $title);
            $shorter = trim($shorter);
            if ($shorter !== '' && $shorter !== $title) $queries[] = $shorter;
            $words = explode(' ', $title);
            if (count($words) > 3) {
                $half = implode(' ', array_slice($words, 0, (int)ceil(count($words) / 2)));
                if ($half !== $title && $half !== $shorter) $queries[] = $half;
            }

            $posterUrl = null;
            $tmdbId = null;
            $tmdbTitle = null;
            $tmdbOverview = null;

            foreach ($queries as $q) {
                $encoded = urlencode($q);
                // For movies, search multi then movie (instead of TV for series)
                $urls = [
                    "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
                    "https://api.themoviedb.org/3/search/movie?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
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

            $fullPath = $resolvedPath . '/' . $fileName;
            try {
                $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)
              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, updated_at = datetime('now')")
                   ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
            } catch (PDOException $e) { /* ignore lock */ }

            if ($posterUrl) {
                $result[$fileName] = ['poster' => $posterUrl];
                if ($tmdbOverview) $result[$fileName]['overview'] = $tmdbOverview;
            }
        }

        $videoRemaining = count($videoUncached) - count($videoToFetch);
    }

    $totalRemaining = $remaining + $videoRemaining;

    // When all regex+TMDB batches are done, trigger AI fallback in background
    if ($totalRemaining === 0) {
        $stmtNull = $db->prepare("SELECT COUNT(*) FROM folder_posters WHERE path LIKE :prefix AND poster_url IS NULL AND (ai_attempts IS NULL OR ai_attempts < 3)");
        $stmtNull->execute([':prefix' => $resolvedPath . '/%']);
        $nullCount = (int)$stmtNull->fetchColumn();
        if ($nullCount > 0 && is_executable('/usr/local/bin/claude')) {
            $scriptPath = realpath(__DIR__ . '/../tools/ai-titles.php');
            $cmd = '/usr/bin/php ' . escapeshellarg($scriptPath) . ' --pending-path ' . escapeshellarg($resolvedPath) . ' > /dev/null 2>&1 &';
            @pclose(@popen($cmd, 'r'));
        }
    }

    echo json_encode(['posters' => $result, 'remaining' => $totalRemaining]);
    exit;
}

// ── Search TMDB for a folder name (manual selection) ──
if (isset($_GET['tmdb_search'])) {
    $searchName = trim($_GET['tmdb_search']);
    if (!$searchName) { echo '[]'; exit; }

    $query = urlencode($searchName);
    $url = "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$query}&language=fr&page=1";

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
                'type' => $r['media_type'] ?? '?',
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
            // Supprimer l'entrée → le prochain fetch TMDB la recréera
            $db->prepare("DELETE FROM folder_posters WHERE path = :p")->execute([':p' => $fullPath]);
        } else {
            $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, verified) VALUES (:p, :u, :i, :t, :o, 1)
              ON CONFLICT(path) DO UPDATE SET poster_url = :u, tmdb_id = :i, title = :t, overview = :o, verified = 1, updated_at = datetime('now')")
               ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $title, ':o' => $overview]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
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
        $db->prepare("INSERT INTO folder_posters (path, folder_type) VALUES (:p, :t)
                      ON CONFLICT(path) DO UPDATE SET folder_type = :t, updated_at = datetime('now')")
           ->execute([':p' => $fullPath, ':t' => $type]);
        echo json_encode(['success' => true, 'folder_type' => $type]);
    } catch (PDOException $e) {
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
        // Reset verified + clear poster so AI cron re-processes it
        $db->prepare("UPDATE folder_posters SET verified = 0, poster_url = NULL, ai_attempts = 0 WHERE path = :p AND poster_url != '__none__'")
           ->execute([':p' => $fullPath]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db error']);
    }
    exit;
}

echo json_encode(['error' => 'unknown tmdb action']);
exit;
