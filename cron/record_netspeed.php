<?php
/**
 * Cron 1 min — Enregistre le débit réseau dans net_speed (SQLite).
 * Crontab : * * * * * www-data php /srv/share/cron/record_netspeed.php
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
    $db->exec("DELETE FROM net_speed WHERE ts < strftime('%s', 'now', '-7 days')");
} catch (PDOException $e) {
    app_log('NETSPEED DB error | ' . $e->getMessage());
}
