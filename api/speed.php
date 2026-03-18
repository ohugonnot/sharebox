<?php
/**
 * API speed — Débit réseau instantané
 * Cache 8s dans /tmp/sb_net_speed.json (diff des compteurs /proc/net/dev).
 */
require_once __DIR__ . '/dashboard_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$MAX_MBS    = defined('NET_MAX_MBS') ? NET_MAX_MBS : 125; // 1 Gbit/s
$CACHE_FILE = '/tmp/sb_net_speed.json';
$CACHE_TTL  = 8; // secondes

$iface = detect_primary_iface();
if (!$iface) {
    echo json_encode(['upload' => 0, 'download' => 0, 'max_mbs' => $MAX_MBS]);
    exit;
}

$now   = microtime(true);
$cache = null;

if (is_readable($CACHE_FILE)) {
    $raw = @json_decode((string)file_get_contents($CACHE_FILE), true);
    if (is_array($raw) && isset($raw['ts'])) {
        $cache = $raw;
    }
}

// Cache frais : retourner la vitesse déjà calculée
if ($cache !== null && ($now - (float)$cache['ts']) < $CACHE_TTL) {
    echo json_encode([
        'upload'   => round((float)($cache['upload']   ?? 0), 2),
        'download' => round((float)($cache['download'] ?? 0), 2),
        'max_mbs'  => $MAX_MBS,
    ]);
    exit;
}

// Lire les compteurs actuels
$counters = parse_net_dev($iface);
if (!$counters) {
    echo json_encode(['upload' => 0, 'download' => 0, 'max_mbs' => $MAX_MBS]);
    exit;
}

// Calculer le débit si on a une lecture précédente
if ($cache !== null && isset($cache['rx_bytes'], $cache['tx_bytes'])) {
    $elapsed  = max(0.001, $now - (float)$cache['ts']);
    $upload   = max(0.0, ($counters['tx_bytes'] - (int)$cache['tx_bytes']) / $elapsed / 1048576);
    $download = max(0.0, ($counters['rx_bytes'] - (int)$cache['rx_bytes']) / $elapsed / 1048576);
    $upload   = round($upload, 2);
    $download = round($download, 2);
} else {
    // Première requête : pas de référence, on retourne 0 et on sauvegarde
    $upload = $download = 0.0;
}

// Mettre à jour le cache avec les nouveaux compteurs + débit calculé
@file_put_contents($CACHE_FILE, json_encode([
    'ts'       => $now,
    'rx_bytes' => $counters['rx_bytes'],
    'tx_bytes' => $counters['tx_bytes'],
    'upload'   => $upload,
    'download' => $download,
]), LOCK_EX);

echo json_encode(['upload' => $upload, 'download' => $download, 'max_mbs' => $MAX_MBS]);
