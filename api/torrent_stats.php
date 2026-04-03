<?php
/**
 * api/torrent_stats.php — Statistiques long-terme d'upload par torrent
 *
 * Actions :
 *   ?action=list&range=7d|30d|90d   → top uploaders sur la période
 *   ?action=chart&hash=XXX&range=7d|30d|90d → série temporelle d'un torrent
 *   ?action=lifecycle                → scatter âge vs vitesse (tous les torrents)
 *
 * DB : data/torrent-stats.db (SQLite, read-only depuis PHP)
 */

require_once __DIR__ . '/../auth.php';
require_auth();

header('Content-Type: application/json');

$STATS_DB = __DIR__ . '/../data/torrent-stats.db';

if (!file_exists($STATS_DB)) {
    echo json_encode(['error' => 'stats_db_not_found', 'data' => []]);
    exit;
}

try {
    $db = new PDO('sqlite:' . $STATS_DB, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA query_only = ON');
} catch (Exception $e) {
    echo json_encode(['error' => 'db_open_failed']);
    exit;
}

$action = $_GET['action'] ?? 'list';
$range  = $_GET['range']  ?? '7d';
$hash   = $_GET['hash']   ?? '';

$now = time();
$cutoff = match($range) {
    '30d'  => $now - 30 * 86400,
    '90d'  => $now - 90 * 86400,
    default => $now - 7 * 86400,   // 7d
};

// ── list : top uploaders ──────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->prepare("
        SELECT
            m.hash,
            m.name,
            m.size_bytes,
            m.location,
            m.finished_ts,
            COALESCE(SUM(h.up_bytes), 0)                                   AS total_up,
            COALESCE(AVG(h.rate_bps), 0)                                   AS avg_rate,
            COALESCE(AVG(h.peers_avg), 0)                                  AS avg_peers,
            COUNT(h.hour_ts)                                               AS hours_seen
        FROM torrent_meta m
        LEFT JOIN torrent_hourly h
               ON h.hash = m.hash AND h.hour_ts >= :cutoff
        GROUP BY m.hash
        ORDER BY total_up DESC
    ");
    $rows->execute([':cutoff' => $cutoff]);
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'hash'        => $r['hash'],
            'name'        => $r['name'],
            'size_bytes'  => (int)$r['size_bytes'],
            'location'    => $r['location'],
            'finished_ts' => (int)$r['finished_ts'],
            'total_up'    => (int)$r['total_up'],
            'avg_rate_kbps' => round($r['avg_rate'] / 1024, 1),
            'avg_peers'   => round($r['avg_peers'], 1),
            'hours_seen'  => (int)$r['hours_seen'],
        ];
    }
    echo json_encode(['range' => $range, 'data' => $data]);
    exit;
}

// ── chart : série temporelle d'un torrent ────────────────────────────────────
if ($action === 'chart') {
    if (!preg_match('/^[0-9a-fA-F]{40}$/', $hash)) {
        echo json_encode(['error' => 'invalid_hash']);
        exit;
    }

    // Bucket selon la plage : 7d → hourly (3600), 30d → 6h (21600), 90d → daily (86400)
    $bucket = match($range) {
        '30d'  => 21600,
        '90d'  => 86400,
        default => 3600,
    };

    $stmt = $db->prepare("
        SELECT
            (hour_ts / :bucket) * :bucket AS ts,
            SUM(up_bytes)                 AS up_bytes,
            AVG(rate_bps)                 AS rate_bps,
            AVG(peers_avg)                AS peers_avg,
            MAX(location)                 AS location
        FROM torrent_hourly
        WHERE hash = :hash AND hour_ts >= :cutoff
        GROUP BY (hour_ts / :bucket)
        ORDER BY ts ASC
    ");
    $stmt->execute([':hash' => $hash, ':cutoff' => $cutoff, ':bucket' => $bucket]);

    $meta = $db->prepare('SELECT name, size_bytes, location, finished_ts FROM torrent_meta WHERE hash = ?');
    $meta->execute([$hash]);
    $m = $meta->fetch();

    $points = [];
    foreach ($stmt as $r) {
        $points[] = [
            'ts'       => (int)$r['ts'],
            'up_bytes' => (int)$r['up_bytes'],
            'rate_kbps' => round($r['rate_bps'] / 1024, 1),
            'peers'    => round($r['peers_avg'], 1),
            'location' => $r['location'],
        ];
    }
    echo json_encode([
        'hash'   => $hash,
        'range'  => $range,
        'bucket' => $bucket,
        'meta'   => $m ?: new stdClass(),
        'points' => $points,
    ]);
    exit;
}

// ── lifecycle : scatter âge vs vitesse (tous les torrents) ───────────────────
if ($action === 'lifecycle') {
    $stmt = $db->query("
        SELECT
            m.hash,
            m.name,
            m.size_bytes,
            m.location,
            m.finished_ts,
            COALESCE(SUM(h.up_bytes), 0)  AS total_up_all,
            COALESCE(AVG(h.rate_bps), 0)  AS avg_rate_all,
            -- vitesse sur les 7 derniers jours
            COALESCE(SUM(CASE WHEN h.hour_ts >= {$now} - 604800 THEN h.up_bytes ELSE 0 END), 0) AS up_7d,
            COALESCE(AVG(CASE WHEN h.hour_ts >= {$now} - 604800 THEN h.rate_bps ELSE NULL END), 0) AS rate_7d,
            -- vitesse entre 7j et 30j (pour tendance)
            COALESCE(AVG(CASE WHEN h.hour_ts < {$now} - 604800 AND h.hour_ts >= {$now} - 2592000 THEN h.rate_bps ELSE NULL END), 0) AS rate_7d_30d
        FROM torrent_meta m
        LEFT JOIN torrent_hourly h ON h.hash = m.hash
        WHERE m.finished_ts > 0
        GROUP BY m.hash
        HAVING total_up_all > 0 OR m.finished_ts > 0
        ORDER BY avg_rate_all DESC
    ");

    $data = [];
    foreach ($stmt as $r) {
        $age_days   = $r['finished_ts'] > 0 ? round(($now - $r['finished_ts']) / 86400, 1) : null;
        $rate_7d    = round($r['rate_7d'] / 1024, 1);
        $rate_7d30d = round($r['rate_7d_30d'] / 1024, 1);
        // tendance : comparaison 7d récents vs 7-30j
        $trend = 'stable';
        if ($rate_7d30d > 0) {
            $ratio = $rate_7d / $rate_7d30d;
            if ($ratio >= 1.2)      $trend = 'up';
            elseif ($ratio <= 0.8)  $trend = 'down';
        } elseif ($rate_7d > 0) {
            $trend = 'new';
        }
        $data[] = [
            'hash'          => $r['hash'],
            'name'          => $r['name'],
            'size_bytes'    => (int)$r['size_bytes'],
            'location'      => $r['location'],
            'age_days'      => $age_days,
            'total_up'      => (int)$r['total_up_all'],
            'avg_rate_kbps' => round($r['avg_rate_all'] / 1024, 1),
            'rate_7d_kbps'  => $rate_7d,
            'trend'         => $trend,
        ];
    }
    echo json_encode(['data' => $data]);
    exit;
}

echo json_encode(['error' => 'unknown_action']);
