<?php
/**
 * Backfill tmdb_rating pour les entrées qui ont un tmdb_id mais pas de note.
 * Ne touche à rien d'autre (poster, overview, verified, etc.)
 * Usage : sudo -u www-data php tools/tmdb-backfill-rating.php
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$TMDB_API_KEY = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
if (!$TMDB_API_KEY) {
    fwrite(STDERR, "Error: TMDB_API_KEY not configured in config.php\n");
    exit(1);
}

$db  = get_db();
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);

$rows = $db->query("
    SELECT path, tmdb_id, tmdb_type
    FROM folder_posters
    WHERE tmdb_id IS NOT NULL AND tmdb_rating IS NULL
    ORDER BY path
")->fetchAll();

$total   = count($rows);
$updated = 0;
$failed  = 0;

echo "Backfill rating TMDB : {$total} entrées\n";

$stmt = $db->prepare("UPDATE folder_posters SET tmdb_rating = ? WHERE path = ?");

foreach ($rows as $i => $row) {
    $type     = $row['tmdb_type'] === 'movie' ? 'movie' : 'tv';
    $url      = "https://api.themoviedb.org/3/{$type}/{$row['tmdb_id']}?api_key={$TMDB_API_KEY}&language=fr";
    $resp     = @file_get_contents($url, false, $ctx);
    $data     = $resp ? json_decode($resp, true) : null;
    $rating   = $data ? round((float)($data['vote_average'] ?? 0), 1) : null;

    if ($rating !== null && $rating > 0) {
        $stmt->execute([$rating, $row['path']]);
        $updated++;
    } else {
        // Store 0.0 to avoid re-fetching entries with no rating on TMDB
        $stmt->execute([0.0, $row['path']]);
        $failed++;
    }

    if (($i + 1) % 50 === 0) {
        echo "  " . ($i + 1) . "/{$total} — updated={$updated} no_rating={$failed}\n";
    }

    usleep(50000); // 50ms — respecte le rate limit TMDB
}

echo "Terminé : {$updated} notes récupérées, {$failed} sans note (0.0 enregistré)\n";
