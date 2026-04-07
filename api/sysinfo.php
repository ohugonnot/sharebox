<?php
require_once __DIR__ . "/auth_check.php";
/**
 * API sysinfo — CPU, RAM, Disk, Disk I/O
 * Retourne JSON, mesure en temps réel sur une fenêtre de 500ms.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/dashboard_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// --- Cache 3s pour éviter de bloquer un worker PHP 500ms par requête ---
$cacheFile = sys_get_temp_dir() . '/sb_sysinfo.json';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3) {
    readfile($cacheFile);
    exit;
}

// --- Détection des volumes + device primaire (avant la fenêtre de mesure) ---
$volumes    = detect_storage_volumes();
$blockInfo  = detect_block_device(BASE_PATH);
$diskPrefix = $blockInfo['prefix'];

// --- Première lecture (avant fenêtre 500 ms) ---
$cpu_a    = parse_cpu_stat();
$ds_a     = parse_diskstats_for_device($diskPrefix);
$vol_ds_a = [];
foreach ($volumes as $i => $vol) {
    $vol_ds_a[$i] = parse_diskstats_for_device($vol['_block_prefix']);
}

// --- Fenêtre de mesure 500 ms ---
usleep(500000);

// --- Deuxième lecture ---
$cpu_b    = parse_cpu_stat();
$ds_b     = parse_diskstats_for_device($diskPrefix);
$vol_ds_b = [];
foreach ($volumes as $i => $vol) {
    $vol_ds_b[$i] = parse_diskstats_for_device($vol['_block_prefix']);
}

// CPU
$cpu   = calc_cpu_pct($cpu_a, $cpu_b);
$load  = sys_getloadavg() ?: [0.0, 0.0, 0.0];
$cores = (int)(file_exists('/sys/devices/system/cpu/online')
    ? (int)preg_replace('/0-(\d+)/', '$1', trim((string)file_get_contents('/sys/devices/system/cpu/online'))) + 1
    : substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor'));

// RAM
$mem      = parse_meminfo();
$total_kb = $mem['MemTotal']     ?? 0;
$avail_kb = $mem['MemAvailable'] ?? 0;
$free_kb  = $mem['MemFree']      ?? 0;
$buf_kb   = $mem['Buffers']      ?? 0;
$cache_kb = ($mem['Cached'] ?? 0) + ($mem['SReclaimable'] ?? 0);
$prog_kb  = max(0, $total_kb - $avail_kb - $buf_kb - $cache_kb);

// Disk space primaire (pour les champs disk_* de compatibilité)
$disk_total = (float)(disk_total_space(BASE_PATH) ?: 0);
$disk_free  = (float)(disk_free_space(BASE_PATH) ?: 0);
$disk_used  = $disk_total - $disk_free;

// Disk I/O primaire (BASE_PATH) — fenêtre 500 ms
$disk_busy_pct  = 0.0;
$disk_io_pct    = 0.0;
$disk_read_mbs  = 0.0;
$disk_write_mbs = 0.0;

if ($ds_a !== null && $ds_b !== null) {
    $busy_ms_diff = (int)$ds_b[12] - (int)$ds_a[12];
    $rq_ms_diff   = (int)$ds_b[13] - (int)$ds_a[13];
    $rd_sec_diff  = (int)$ds_b[5]  - (int)$ds_a[5];
    $wr_sec_diff  = (int)$ds_b[9]  - (int)$ds_a[9];

    $raid_disks     = $blockInfo['raid_disks'];
    // Sur RAID0, md0 %util est trompeur (100% ≠ saturé). On normalise par le nombre de disques
    // ET on cap à busy_ms car rq_ms peut dépasser quand les requêtes se queue.
    // busy_ms = temps réel pendant lequel au moins 1 disque était actif → meilleur indicateur.
    $disk_busy_pct  = round(min(100.0, $busy_ms_diff / 500.0 / max(1, $raid_disks) * 100.0), 1);
    $disk_io_pct    = $disk_busy_pct; // Utiliser busy normalisé — plus stable et réaliste que rq_ms
    $disk_read_mbs  = round(max(0.0, $rd_sec_diff * 512 / 1048576 / 0.5), 2);
    $disk_write_mbs = round(max(0.0, $wr_sec_diff * 512 / 1048576 / 0.5), 2);
}

// IO par volume — même fenêtre de mesure
foreach ($volumes as $i => &$vol) {
    $a = $vol_ds_a[$i] ?? null;
    $b = $vol_ds_b[$i] ?? null;
    if ($a !== null && $b !== null) {
        $rq_ms  = (int)$b[13] - (int)$a[13];
        $rd_sec = (int)$b[5]  - (int)$a[5];
        $wr_sec = (int)$b[9]  - (int)$a[9];
        $vol['io_pct']    = round(min(100.0, $rq_ms / (500.0 * max(1, $vol['_raid_disks'])) * 100.0), 1);
        $vol['read_mbs']  = round(max(0.0, $rd_sec * 512 / 1048576 / 0.5), 2);
        $vol['write_mbs'] = round(max(0.0, $wr_sec * 512 / 1048576 / 0.5), 2);
    }
    // Supprimer les champs internes avant la sérialisation JSON
    unset($vol['_block_prefix'], $vol['_raid_disks']);
}
unset($vol);

// Températures
$cpu_temp  = read_cpu_package_temp();
$hdd_temps = read_hdd_temps();

$json = json_encode([
    'cpu_active_pct' => $cpu['active_pct'],
    'cpu_iowait_pct' => $cpu['iowait_pct'],
    'cpu_idle_pct'   => $cpu['idle_pct'],
    'cpu_load'       => $load,
    'cpu_cores'      => $cores,
    'ram_total_mb'   => (int)round($total_kb / 1024),
    'ram_used_mb'    => (int)round(($total_kb - $avail_kb) / 1024),
    'ram_prog_mb'    => (int)round($prog_kb / 1024),
    'ram_cache_mb'   => (int)round(($buf_kb + $cache_kb) / 1024),
    'ram_free_mb'    => (int)round($free_kb / 1024),
    'disk_total_gb'  => round($disk_total / 1073741824, 1),
    'disk_used_gb'   => round($disk_used  / 1073741824, 1),
    'disk_free_gb'   => round($disk_free  / 1073741824, 1),
    'volumes'        => $volumes,
    'disk_busy_pct'  => $disk_busy_pct,
    'disk_io_pct'    => $disk_io_pct,
    'disk_read_mbs'  => $disk_read_mbs,
    'disk_write_mbs' => $disk_write_mbs,
    'cpu_temp_c'     => $cpu_temp,
    'hdd_temps'      => $hdd_temps ?: null,
]);
@file_put_contents($cacheFile, $json);
echo $json;
