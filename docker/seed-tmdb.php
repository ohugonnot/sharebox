<?php
/**
 * Pre-seed TMDB data for demo content.
 * Called by entrypoint.sh after demo-data.sh.
 * Fetches real posters, overviews, and ratings from TMDB API.
 */

require '/app/db.php';

$mediaDir = rtrim($argv[1] ?? '/media', '/');
$apiKey   = $argv[2] ?? '';

if (!$apiKey) {
    echo "seed-tmdb: no API key, skipping.\n";
    exit(0);
}

$db = get_db();

// Check if already seeded (any entry with a real poster)
$existing = $db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '' AND poster_url != '__none__'")->fetchColumn();
if ($existing > 5) {
    echo "seed-tmdb: already seeded ($existing entries), skipping.\n";
    exit(0);
}

// ── Mapping: folder path => TMDB search query + type ──
$entries = [
    // Series
    ['path' => "$mediaDir/Series/Breaking Bad",      'query' => 'Breaking Bad',      'type' => 'tv'],
    ['path' => "$mediaDir/Series/Game of Thrones",    'query' => 'Game of Thrones',    'type' => 'tv'],
    ['path' => "$mediaDir/Series/Stranger Things",    'query' => 'Stranger Things',    'type' => 'tv'],
    ['path' => "$mediaDir/Series/The Mandalorian",    'query' => 'The Mandalorian',    'type' => 'tv'],
    // Films
    ['path' => "$mediaDir/Films/Inception.2010.1080p.BluRay.x264.mkv",            'query' => 'Inception',              'type' => 'movie', 'year' => 2010],
    ['path' => "$mediaDir/Films/The.Dark.Knight.2008.MULTI.1080p.mkv",             'query' => 'The Dark Knight',        'type' => 'movie', 'year' => 2008],
    ['path' => "$mediaDir/Films/Interstellar.2014.FRENCH.BDRip.mkv",               'query' => 'Interstellar',           'type' => 'movie', 'year' => 2014],
    ['path' => "$mediaDir/Films/Pulp.Fiction.1994.REMASTERED.720p.mkv",            'query' => 'Pulp Fiction',           'type' => 'movie', 'year' => 1994],
    ['path' => "$mediaDir/Films/The.Matrix.1999.UHD.2160p.x265.mkv",              'query' => 'The Matrix',             'type' => 'movie', 'year' => 1999],
    ['path' => "$mediaDir/Films/Parasite.2019.KOREAN.VOSTFR.BluRay.mkv",          'query' => 'Parasite',               'type' => 'movie', 'year' => 2019],
    ['path' => "$mediaDir/Films/Fight.Club.1999.MULTI.1080p.DTS.mkv",             'query' => 'Fight Club',             'type' => 'movie', 'year' => 1999],
    ['path' => "$mediaDir/Films/Gladiator.2000.FRENCH.720p.x264.mkv",             'query' => 'Gladiator',              'type' => 'movie', 'year' => 2000],
    ['path' => "$mediaDir/Films/The.Shawshank.Redemption.1994.1080p.mkv",         'query' => 'The Shawshank Redemption','type' => 'movie', 'year' => 1994],
    ['path' => "$mediaDir/Films/Spirited.Away.2001.JAPANESE.BluRay.mkv",           'query' => 'Spirited Away',          'type' => 'movie', 'year' => 2001],
    ['path' => "$mediaDir/Films/Blade.Runner.2049.2017.MULTI.2160p.HDR.mkv",      'query' => 'Blade Runner 2049',      'type' => 'movie', 'year' => 2017],
    ['path' => "$mediaDir/Films/Dune.2021.IMAX.1080p.WEB-DL.mkv",                 'query' => 'Dune',                   'type' => 'movie', 'year' => 2021],
    // Anime
    ['path' => "$mediaDir/Anime/Attack on Titan",     'query' => 'Attack on Titan',    'type' => 'tv'],
    ['path' => "$mediaDir/Anime/Death Note",           'query' => 'Death Note',         'type' => 'tv'],
    ['path' => "$mediaDir/Anime/One Piece",            'query' => 'One Piece',          'type' => 'tv'],
    // Category folders (no poster, just folder_type)
    ['path' => "$mediaDir/Anime",   'folder_type' => 'series', 'skip_tmdb' => true],
    ['path' => "$mediaDir/Series",  'folder_type' => 'series', 'skip_tmdb' => true],
    ['path' => "$mediaDir/Films",   'folder_type' => 'movies', 'skip_tmdb' => true],
];

// Season folders: fetched in a second pass after series are seeded (needs tmdb_id)
$seasons = [
    ['path' => "$mediaDir/Series/Breaking Bad/Season 1",      'parent' => "$mediaDir/Series/Breaking Bad",      'season' => 1],
    ['path' => "$mediaDir/Series/Breaking Bad/Season 2",      'parent' => "$mediaDir/Series/Breaking Bad",      'season' => 2],
    ['path' => "$mediaDir/Series/Game of Thrones/Season 1",   'parent' => "$mediaDir/Series/Game of Thrones",   'season' => 1],
    ['path' => "$mediaDir/Series/Game of Thrones/Season 2",   'parent' => "$mediaDir/Series/Game of Thrones",   'season' => 2],
    ['path' => "$mediaDir/Series/Stranger Things/Season 1",   'parent' => "$mediaDir/Series/Stranger Things",   'season' => 1],
    ['path' => "$mediaDir/Series/Stranger Things/Season 2",   'parent' => "$mediaDir/Series/Stranger Things",   'season' => 2],
    ['path' => "$mediaDir/Series/The Mandalorian/Season 1",   'parent' => "$mediaDir/Series/The Mandalorian",   'season' => 1],
    ['path' => "$mediaDir/Series/The Mandalorian/Season 2",   'parent' => "$mediaDir/Series/The Mandalorian",   'season' => 2],
    ['path' => "$mediaDir/Anime/Attack on Titan/Season 1",    'parent' => "$mediaDir/Anime/Attack on Titan",    'season' => 1],
    ['path' => "$mediaDir/Anime/Attack on Titan/Season 2",    'parent' => "$mediaDir/Anime/Attack on Titan",    'season' => 2],
    ['path' => "$mediaDir/Anime/Death Note/Season 1",         'parent' => "$mediaDir/Anime/Death Note",         'season' => 1],
    ['path' => "$mediaDir/Anime/One Piece/Season 1",          'parent' => "$mediaDir/Anime/One Piece",          'season' => 1],
];

$stmt = $db->prepare("INSERT INTO folder_posters (path, poster_url, tmdb_id, title, overview, tmdb_rating, tmdb_year, tmdb_type, folder_type, verified, match_attempts)
    VALUES (:path, :poster_url, :tmdb_id, :title, :overview, :rating, :year, :tmdb_type, :folder_type, 1, 1)
    ON CONFLICT(path) DO UPDATE SET
        poster_url = COALESCE(:poster_url, folder_posters.poster_url),
        tmdb_id = COALESCE(:tmdb_id, folder_posters.tmdb_id),
        title = COALESCE(:title, folder_posters.title),
        overview = COALESCE(:overview, folder_posters.overview),
        tmdb_rating = COALESCE(:rating, folder_posters.tmdb_rating),
        tmdb_year = COALESCE(:year, folder_posters.tmdb_year),
        tmdb_type = COALESCE(:tmdb_type, folder_posters.tmdb_type),
        folder_type = COALESCE(:folder_type, folder_posters.folder_type),
        verified = 1,
        match_attempts = 1");

$seeded = 0;
$failed = 0;

foreach ($entries as $entry) {
    $path = $entry['path'];
    $folderType = $entry['folder_type'] ?? (($entry['type'] ?? '') === 'movie' ? 'movies' : 'series');

    if (!empty($entry['skip_tmdb'])) {
        $stmt->execute([
            ':path'       => $path,
            ':poster_url' => null,
            ':tmdb_id'    => null,
            ':title'      => null,
            ':overview'   => null,
            ':rating'     => null,
            ':year'       => null,
            ':tmdb_type'  => null,
            ':folder_type'=> $folderType,
        ]);
        continue;
    }

    $query = urlencode($entry['query']);
    $tmdbType = $entry['type'];
    $yearParam = isset($entry['year']) ? "&year={$entry['year']}" : '';
    $url = "https://api.themoviedb.org/3/search/$tmdbType?api_key=$apiKey&query=$query$yearParam&language=en-US&page=1";

    $json = @file_get_contents($url);
    if (!$json) {
        echo "  FAIL: {$entry['query']}\n";
        $failed++;
        continue;
    }

    $data = json_decode($json, true);
    $results = $data['results'] ?? [];
    if (empty($results)) {
        echo "  NO RESULT: {$entry['query']}\n";
        $failed++;
        continue;
    }

    $r = $results[0];
    $posterUrl  = $r['poster_path'] ? "https://image.tmdb.org/t/p/w500{$r['poster_path']}" : null;
    $title      = $r['title'] ?? $r['name'] ?? $entry['query'];
    $overview   = $r['overview'] ?? '';
    $rating     = $r['vote_average'] ?? null;
    $tmdbId     = $r['id'] ?? null;
    $releaseDate = $r['release_date'] ?? $r['first_air_date'] ?? '';
    $year       = $releaseDate ? substr($releaseDate, 0, 4) : ($entry['year'] ?? null);

    $stmt->execute([
        ':path'       => $path,
        ':poster_url' => $posterUrl,
        ':tmdb_id'    => $tmdbId,
        ':title'      => $title,
        ':overview'   => $overview,
        ':rating'     => $rating,
        ':year'       => $year,
        ':tmdb_type'  => $tmdbType,
        ':folder_type'=> $folderType,
    ]);

    echo "  OK: $title ($year) - " . ($rating ?? '?') . "/10\n";
    $seeded++;

    // Rate limit: TMDB allows ~40 req/10s
    usleep(300000);
}

// ── Second pass: fetch season posters via /tv/{id}/season/{n} ──
$seasonSeeded = 0;
foreach ($seasons as $s) {
    // Look up parent tmdb_id
    $pStmt = $db->prepare("SELECT tmdb_id FROM folder_posters WHERE path = :p AND tmdb_id IS NOT NULL");
    $pStmt->execute([':p' => $s['parent']]);
    $pRow = $pStmt->fetch();
    if (!$pRow) continue;

    $tmdbId = (int)$pRow['tmdb_id'];
    $seasonNum = $s['season'];
    $url = "https://api.themoviedb.org/3/tv/$tmdbId/season/$seasonNum?api_key=$apiKey&language=en-US";

    $json = @file_get_contents($url);
    if (!$json) { continue; }

    $data = json_decode($json, true);
    $posterPath = $data['poster_path'] ?? null;
    $posterUrl  = $posterPath ? "https://image.tmdb.org/t/p/w500$posterPath" : null;
    $overview   = $data['overview'] ?? '';
    $name       = $data['name'] ?? "Season $seasonNum";

    $stmt->execute([
        ':path'       => $s['path'],
        ':poster_url' => $posterUrl,
        ':tmdb_id'    => $tmdbId,
        ':title'      => $name,
        ':overview'   => $overview,
        ':rating'     => null,
        ':year'       => null,
        ':tmdb_type'  => 'tv',
        ':folder_type'=> 'series',
    ]);

    if ($posterUrl) {
        echo "  SEASON OK: $name\n";
        $seasonSeeded++;
    }
    usleep(300000);
}

echo "seed-tmdb: done. Seeded $seeded titles + $seasonSeeded seasons, failed $failed.\n";
