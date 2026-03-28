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

    $result = [];
    $items = scandir($resolvedPath);
    $folders = [];
    foreach ($items as $item) {
        if ($item[0] === '.' || !is_dir($resolvedPath . '/' . $item)) continue;
        $folders[] = $item;
    }

    if (empty($folders)) { echo json_encode(['posters' => [], 'remaining' => 0]); exit; }

    // Check cache first
    $cached = [];
    $uncached = [];
    foreach ($folders as $f) {
        // Skip les noms d'organisation (Season, OVA, Bonus, etc.)
        if (preg_match($skipPattern, $f)) continue;
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
            }
        } else {
            $uncached[] = $f;
        }
    }

    $result = $cached;

    // Fetch from TMDB for uncached folders (max 10 per request to avoid timeout)
    // Stratégie de recherche : essayer plusieurs variantes du titre pour maximiser les chances
    $toFetch = array_slice($uncached, 0, 10);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

    foreach ($toFetch as $folderName) {
        $meta = extract_title_year($folderName);
        $title = $meta['title'];

        // Construire les variantes de recherche (du plus précis au plus large)
        $queries = [$title];
        // Variante sans les mots parasites (HD, Remastered, Complete, Integrale...)
        $shorter = preg_replace('/\b(hd|remasted|remastered|complete|integrale|intégrale|collection|pack|coffret)\b.*/i', '', $title);
        $shorter = trim($shorter);
        if ($shorter !== '' && $shorter !== $title) $queries[] = $shorter;
        // Première moitié du titre (si titre long)
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
            // Essayer d'abord en multi, puis en TV seul (meilleur pour les séries/anime)
            $urls = [
                "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
                "https://api.themoviedb.org/3/search/tv?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
            ];
            foreach ($urls as $searchUrl) {
                $resp = @file_get_contents($searchUrl, false, $ctx);
                $data = $resp ? json_decode($resp, true) : null;
                if ($data && !empty($data['results'])) {
                    // Prendre le premier résultat avec un poster
                    foreach ($data['results'] as $r) {
                        if (!empty($r['poster_path'])) {
                            $posterUrl = 'https://image.tmdb.org/t/p/w300' . $r['poster_path'];
                            $tmdbId = $r['id'] ?? null;
                            $tmdbTitle = $r['title'] ?? $r['name'] ?? null;
                            $tmdbOverview = $r['overview'] ?? null;
                            break 3; // Trouvé → sortir des 3 boucles
                        }
                    }
                }
            }
        }

        // Cache result (even null = "no poster found")
        $fullPath = $resolvedPath . '/' . $folderName;
        try {
            $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
               ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
        } catch (PDOException $e) { /* ignore lock */ }

        if ($posterUrl) {
            $result[$folderName] = ['poster' => $posterUrl];
            if ($tmdbOverview) $result[$folderName]['overview'] = $tmdbOverview;
        }
    }

    // Signal if there are more folders to fetch
    $remaining = count($uncached) - count($toFetch);

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
        foreach ($videoFiles as $vf) {
            $fullPath = $resolvedPath . '/' . $vf;
            $stmt = $db->prepare("SELECT poster_url, overview FROM folder_posters WHERE path = :p");
            $stmt->execute([':p' => $fullPath]);
            $row = $stmt->fetch();
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
                $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
                   ->execute([':p' => $fullPath, ':u' => $posterUrl, ':i' => $tmdbId, ':t' => $tmdbTitle, ':o' => $tmdbOverview]);
            } catch (PDOException $e) { /* ignore lock */ }

            if ($posterUrl) {
                $result[$fileName] = ['poster' => $posterUrl];
                if ($tmdbOverview) $result[$fileName]['overview'] = $tmdbOverview;
            }
        }

        $videoRemaining = count($videoUncached) - count($videoToFetch);
    }

    echo json_encode(['posters' => $result, 'remaining' => $remaining + $videoRemaining]);
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

    $fullPath = $resolvedPath . '/' . $folder;
    if (!is_dir($fullPath) && !is_file($fullPath)) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    try {
        if (!$posterUrl) {
            // Supprimer l'entrée → le prochain fetch TMDB la recréera
            $db->prepare("DELETE FROM folder_posters WHERE path = :p")->execute([':p' => $fullPath]);
        } else {
            $db->prepare("INSERT OR REPLACE INTO folder_posters (path, poster_url, tmdb_id, title, overview) VALUES (:p, :u, :i, :t, :o)")
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

    $fullPath = $resolvedPath . '/' . $folder;
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

echo json_encode(['error' => 'unknown tmdb action']);
exit;
