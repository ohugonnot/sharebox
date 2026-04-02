<?php
/**
 * Cron 1 min — Enregistre le débit réseau dans net_speed (SQLite).
 * Crontab : * * * * * www-data php /var/www/sharebox/cron/record_netspeed.php
 *
 * Calcul : diff des compteurs /proc/net/dev sur 1 seconde -> MB/s.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../api/dashboard_helpers.php';

$iface = detect_primary_iface();
if (!$iface) {
    app_log('NETSPEED error | no primary interface detected');
    exit(0);
}

$a = parse_net_dev($iface);
sleep(1);
$b = parse_net_dev($iface);

if (!$a || !$b) {
    app_log('NETSPEED error | parse_net_dev failed iface=' . $iface);
    exit(0);
}

$upload   = max(0.0, ($b['tx_bytes'] - $a['tx_bytes']) / 1048576);
$download = max(0.0, ($b['rx_bytes'] - $a['rx_bytes']) / 1048576);

try {
    $db = get_db();
    $db->prepare("INSERT INTO net_speed (ts, upload, download) VALUES (?, ?, ?)")
       ->execute([time(), $upload, $download]);

    // Agréger les heures complètes pas encore dans net_speed_hourly
    // (SQLite n'a pas 'start of hour' — arithmétique entière)
    $db->exec("
        INSERT OR IGNORE INTO net_speed_hourly (hour_ts, upload, download)
        SELECT (ts/3600)*3600, AVG(upload), AVG(download)
        FROM net_speed
        WHERE ts < (strftime('%s','now') / 3600) * 3600
          AND (ts/3600)*3600 NOT IN (SELECT hour_ts FROM net_speed_hourly)
        GROUP BY (ts/3600)*3600
    ");

    // Agréger les jours complets pas encore dans net_speed_daily
    $db->exec("
        INSERT OR IGNORE INTO net_speed_daily (day_ts, upload, download)
        SELECT (hour_ts/86400)*86400, AVG(upload), AVG(download)
        FROM net_speed_hourly
        WHERE hour_ts < strftime('%s','now','start of day')
          AND (hour_ts/86400)*86400 NOT IN (SELECT day_ts FROM net_speed_daily)
        GROUP BY (hour_ts/86400)*86400
    ");

    // Cleanup : raw 48h, hourly 90 jours, daily 2 ans
    $db->exec("DELETE FROM net_speed WHERE ts < strftime('%s','now','-2 days')");
    $db->exec("DELETE FROM net_speed_hourly WHERE hour_ts < strftime('%s','now','-90 days')");
    $db->exec("DELETE FROM net_speed_daily WHERE day_ts < strftime('%s','now','-730 days')");
} catch (PDOException $e) {
    app_log('NETSPEED DB error | ' . $e->getMessage());
}
