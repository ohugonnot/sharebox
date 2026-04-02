<?php
require_once __DIR__ . "/auth_check.php";
/**
 * API netspeed_history — Historique réseau multi-temporalité
 * ?range=24h  → net_speed brut, agrégé par 5 min (288 pts max)
 * ?range=7d   → net_speed_hourly (168 pts max)  [défaut]
 * ?range=1m   → net_speed_hourly agrégé par 6h (120 pts max)
 * ?range=1y   → net_speed_daily (365 pts max)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$range   = $_GET['range'] ?? '7d';
$allowed = ['24h', '7d', '1m', '1y'];
if (!in_array($range, $allowed, true)) {
    $range = '7d';
}

$db = get_db();

switch ($range) {
    case '24h':
        $rows = $db->query("
            SELECT (ts/300)*300 AS ts, AVG(upload) AS upload, AVG(download) AS download
            FROM net_speed
            WHERE ts > strftime('%s','now','-24 hours')
            GROUP BY (ts/300)*300
            ORDER BY ts
        ")->fetchAll();
        break;

    case '7d':
        $rows = $db->query("
            SELECT hour_ts AS ts, upload, download
            FROM net_speed_hourly
            WHERE hour_ts > strftime('%s','now','-7 days')
            ORDER BY ts
        ")->fetchAll();
        break;

    case '1m':
        $rows = $db->query("
            SELECT (hour_ts/21600)*21600 AS ts, AVG(upload) AS upload, AVG(download) AS download
            FROM net_speed_hourly
            WHERE hour_ts > strftime('%s','now','-30 days')
            GROUP BY (hour_ts/21600)*21600
            ORDER BY ts
        ")->fetchAll();
        break;

    case '1y':
        $rows = $db->query("
            SELECT day_ts AS ts, upload, download
            FROM net_speed_daily
            WHERE day_ts > strftime('%s','now','-365 days')
            ORDER BY ts
        ")->fetchAll();
        break;

    default:
        $rows = [];
}

$points = array_map(fn($r) => [
    'ts'       => (int)$r['ts'],
    'upload'   => round((float)$r['upload'], 2),
    'download' => round((float)$r['download'], 2),
], $rows);

echo json_encode(['points' => $points, 'range' => $range]);
