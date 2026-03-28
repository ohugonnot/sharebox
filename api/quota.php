<?php
/**
 * API quota — Bandwidth usage from vnstat (monthly)
 * Returns JSON with current month rx/tx in bytes, quota info.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Quota mensuel en bytes (100 TB)
$quota_bytes = defined('BANDWIDTH_QUOTA_TB') ? BANDWIDTH_QUOTA_TB * (1024 ** 4) : 100 * (1024 ** 4);

$output = [];
$ret = 0;
exec('vnstat -m --json 2>/dev/null', $output, $ret);
$json = implode('', $output);

if ($ret !== 0 || $json === '') {
    app_log('QUOTA error | vnstat unavailable ret=' . $ret);
    echo json_encode(['error' => 'vnstat unavailable']);
    exit;
}

$data = json_decode($json, true);
if (!$data || empty($data['interfaces'])) {
    app_log('QUOTA error | vnstat returned no interfaces');
    echo json_encode(['error' => 'no vnstat data']);
    exit;
}

$iface = $data['interfaces'][0];
$months = $iface['traffic']['month'] ?? [];

// Current month
$now_year  = (int)date('Y');
$now_month = (int)date('n');

$rx = 0;
$tx = 0;
$found = false;

foreach ($months as $m) {
    if ($m['date']['year'] === $now_year && $m['date']['month'] === $now_month) {
        $rx = $m['rx'];
        $tx = $m['tx'];
        $found = true;
        break;
    }
}

$total = $rx + $tx;
$pct   = $quota_bytes > 0 ? round($total / $quota_bytes * 100, 2) : 0;

// Days remaining in month
$days_in_month = (int)date('t');
$day_now       = (int)date('j');
$days_left     = $days_in_month - $day_now;

// Daily average and projection
$daily_avg  = $total / $day_now;
$projection = $daily_avg * $days_in_month;

echo json_encode([
    'rx_bytes'       => $rx,
    'tx_bytes'       => $tx,
    'total_bytes'    => $total,
    'quota_bytes'    => $quota_bytes,
    'pct'            => $pct,
    'days_left'      => $days_left,
    'days_in_month'  => $days_in_month,
    'day_now'        => $day_now,
    'daily_avg'      => round($daily_avg),
    'projection'     => round($projection),
]);
