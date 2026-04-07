<?php
require_once __DIR__ . "/auth_check.php";
/**
 * API active_torrents — Liste des torrents actifs via qBittorrent API
 */
require_once __DIR__ . '/dashboard_helpers.php';
require_once __DIR__ . '/../functions.php';

if (!defined('QBITTORRENT_URL')) {
    define('QBITTORRENT_URL', 'http://localhost:8181');
}

/**
 * Retourne la liste des torrents actifs depuis qBittorrent.
 * @return array<string, mixed>
 */
function get_torrents_from_qbittorrent(string $baseUrl = QBITTORRENT_URL): array
{
    if ($baseUrl === '') {
        return ['downloads' => [], 'uploads' => []];
    }

    // Login + fetch via curl (file_get_contents a un bug IPv6 timeout de 3s)
    $sidFile = sys_get_temp_dir() . '/sb_qb_sid.txt';
    $sid = '';
    if (file_exists($sidFile) && (time() - filemtime($sidFile)) < 1800) {
        $sid = trim(file_get_contents($sidFile));
    }

    // Essayer avec le SID caché, re-login si échec
    $json = false;
    if ($sid !== '') {
        $ch = curl_init($baseUrl . '/api/v2/torrents/info');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
            CURLOPT_COOKIE => "SID=$sid"]);
        $json = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 403) { $json = false; $sid = ''; }
    }

    // Re-login si nécessaire
    if ($json === false || $sid === '') {
        $ch = curl_init($baseUrl . '/api/v2/auth/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
            CURLOPT_POST => true, CURLOPT_HEADER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => defined('QBITTORRENT_USER') ? QBITTORRENT_USER : 'admin',
                'password' => defined('QBITTORRENT_PASS') ? QBITTORRENT_PASS : 'adminadmin',
            ])]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['downloads' => [], 'uploads' => [], 'error' => 'qBittorrent unavailable'];
        }
        if (preg_match('/SID=([^;\s]+)/', $resp, $m)) {
            $sid = $m[1];
            @file_put_contents($sidFile, $sid);
        } else {
            return ['downloads' => [], 'uploads' => [], 'error' => 'auth failed'];
        }

        $ch = curl_init($baseUrl . '/api/v2/torrents/info');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
            CURLOPT_COOKIE => "SID=$sid"]);
        $json = curl_exec($ch);
        curl_close($ch);
    }
    if ($json === false) {
        return ['downloads' => [], 'uploads' => [], 'error' => 'API error'];
    }

    $torrents = json_decode($json, true);
    if (!is_array($torrents)) {
        return ['downloads' => [], 'uploads' => [], 'error' => 'invalid response'];
    }

    $downloads = [];
    $uploads   = [];

    foreach ($torrents as $t) {
        $name      = $t['name'] ?? '?';
        $up_rate   = (int)($t['upspeed'] ?? 0);
        $down_rate = (int)($t['dlspeed'] ?? 0);
        $progress  = round(($t['progress'] ?? 0) * 100, 1);

        // Seuil : 50 KB/s minimum pour apparaître
        if ($down_rate > 51200) {
            $downloads[] = [
                'name'     => $name,
                'down_mbs' => round($down_rate / 1048576, 2),
                'progress' => $progress,
            ];
        }
        if ($up_rate > 51200) {
            $uploads[] = [
                'name'   => $name,
                'up_mbs' => round($up_rate / 1048576, 2),
            ];
        }
    }

    usort($downloads, fn($a, $b) => $b['down_mbs'] <=> $a['down_mbs']);
    usort($uploads,   fn($a, $b) => $b['up_mbs']   <=> $a['up_mbs']);

    return ['downloads' => $downloads, 'uploads' => $uploads];
}

// Legacy alias
function get_torrents_from_rtorrent(string $sockPath = ''): array
{
    return get_torrents_from_qbittorrent();
}

// Sortie HTTP uniquement si appelé directement
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    // Cache 5s pour éviter de spammer l'API qBittorrent
    $cacheFile = sys_get_temp_dir() . '/sb_active_torrents.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 5) {
        readfile($cacheFile);
        exit;
    }
    $json = json_encode(get_torrents_from_qbittorrent());
    @file_put_contents($cacheFile, $json);
    echo $json;
}
