<?php
/**
 * API netspeed_history — Historique réseau 7 jours (agrégé par heure)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$db = get_db();

$rows = $db->query("
    SELECT (ts / 3600) * 3600 AS hour,
           AVG(upload)        AS upload,
           AVG(download)      AS download
    FROM net_speed
    WHERE ts > strftime('%s', 'now', '-7 days')
    GROUP BY hour
    ORDER BY hour
")->fetchAll();

$points = array_map(fn($r) => [
    'ts'       => (int)$r['hour'],
    'upload'   => round((float)$r['upload'], 2),
    'download' => round((float)$r['download'], 2),
], $rows);

echo json_encode(['points' => $points]);
