<?php
/**
 * Dashboard helper functions — pure, testable, no side effects
 * No HTTP output, no require_once dependencies.
 */

/**
 * Parse /proc/stat cpu line into an array of tick values.
 * Returns [user, nice, system, idle, iowait, irq, softirq, steal, ...]
 * @return array<int, int>
 */
function parse_cpu_stat(string $statPath = '/proc/stat'): array
{
    $content = @file_get_contents($statPath);
    if ($content === false) return [];
    foreach (explode("\n", $content) as $line) {
        if (strncmp($line, 'cpu ', 4) === 0) {
            $parts = preg_split('/\s+/', trim($line));
            array_shift($parts); // remove 'cpu' label
            return array_map('intval', $parts);
        }
    }
    return [];
}

/**
 * Calculate CPU percentages from two /proc/stat readings.
 * Returns ['active_pct', 'iowait_pct', 'idle_pct'] as floats.
 * @param array<int, int> $a
 * @param array<int, int> $b
 * @return array<string, float>
 */
function calc_cpu_pct(array $a, array $b): array
{
    $empty = ['active_pct' => 0.0, 'iowait_pct' => 0.0, 'idle_pct' => 100.0];
    if (empty($a) || empty($b)) return $empty;

    $totalA = array_sum($a);
    $totalB = array_sum($b);
    $total  = $totalB - $totalA;
    if ($total <= 0) return $empty;

    $idle   = ($b[3] ?? 0) - ($a[3] ?? 0); // idle column
    $iowait = ($b[4] ?? 0) - ($a[4] ?? 0); // iowait column
    $active = $total - $idle - $iowait;

    return [
        'active_pct' => round(max(0.0, $active / $total * 100), 1),
        'iowait_pct' => round(max(0.0, $iowait / $total * 100), 1),
        'idle_pct'   => round(max(0.0, $idle / $total * 100), 1),
    ];
}

/**
 * Parse /proc/meminfo into an associative array (values in kB).
 * @return array<string, int>
 */
function parse_meminfo(string $meminfoPath = '/proc/meminfo'): array
{
    $result  = [];
    $content = @file_get_contents($meminfoPath);
    if ($content === false) return $result;
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $result[$m[1]] = (int)$m[2];
        }
    }
    return $result;
}

/**
 * Detect primary network interface via /proc/net/route.
 * Returns interface name (e.g. 'eth0') or empty string if not found.
 */
function detect_primary_iface(string $routePath = '/proc/net/route'): string
{
    $content = @file_get_contents($routePath);
    if ($content === false) return '';
    foreach (explode("\n", $content) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 4) continue;
        // Destination == 00000000 (default route) AND RTF_GATEWAY flag set (0x0002)
        if ($parts[1] === '00000000' && (hexdec($parts[3]) & 0x0002)) {
            return $parts[0];
        }
    }
    return '';
}

/**
 * Parse /proc/net/dev for a specific interface.
 * Returns ['rx_bytes' => int, 'tx_bytes' => int] or null if not found.
 * @return array<string, int>|null
 */
function parse_net_dev(string $iface, string $netDevPath = '/proc/net/dev'): ?array
{
    $content = @file_get_contents($netDevPath);
    if ($content === false) return null;
    $prefix = $iface . ':';
    foreach (explode("\n", $content) as $line) {
        $line = ltrim($line);
        if (strncmp($line, $prefix, strlen($prefix)) !== 0) continue;
        $fields = preg_split('/\s+/', trim(substr($line, strlen($prefix))));
        return [
            'rx_bytes' => (int)($fields[0] ?? 0),
            'tx_bytes' => (int)($fields[8] ?? 0),
        ];
    }
    return null;
}

/**
 * Lit la température CPU (package) depuis hwmon.
 * Cherche le premier hwmon dont name == 'coretemp' (Intel) ou 'k10temp' (AMD).
 * Retourne null si non disponible.
 */
function read_cpu_package_temp(string $hwmonBase = '/sys/class/hwmon'): ?float
{
    $dirs = @glob($hwmonBase . '/hwmon*/name') ?: [];
    foreach ($dirs as $nameFile) {
        $name = trim((string)@file_get_contents($nameFile));
        if ($name !== 'coretemp' && $name !== 'k10temp') continue;
        $hwmonDir = dirname($nameFile);
        // temp1 = Package id 0 (température globale du CPU)
        $raw = @file_get_contents($hwmonDir . '/temp1_input');
        if ($raw !== false) {
            return round((int)trim($raw) / 1000.0, 1);
        }
    }
    return null;
}

/**
 * Lit les températures disques (HDD SATA + NVMe) via hwmon.
 * Retourne un tableau ['sda' => 38.0, 'nvme0n1' => 42.0, ...] ou [] si non disponible.
 * @return array<string, float>
 */
function read_hdd_temps(string $sysBlock = '/sys/block'): array
{
    $temps = [];

    // SATA/SAS drives (via drivetemp kernel module)
    $disks = @glob($sysBlock . '/sd*/device/hwmon/hwmon*/temp1_input') ?: [];
    foreach ($disks as $tempFile) {
        if (preg_match('|/sys/block/(sd[a-z]+)/|', $tempFile, $m)) {
            $raw = @file_get_contents($tempFile);
            if ($raw !== false) {
                $temps[$m[1]] = round((int)trim($raw) / 1000.0, 1);
            }
        }
    }

    // NVMe drives — check both common sysfs layouts
    $nvmePaths = array_merge(
        @glob($sysBlock . '/nvme*/device/hwmon/hwmon*/temp1_input') ?: [],
        @glob($sysBlock . '/nvme*/hwmon*/temp1_input') ?: []
    );
    foreach ($nvmePaths as $tempFile) {
        if (preg_match('|/sys/block/(nvme[^/]+)/|', $tempFile, $m)) {
            if (!isset($temps[$m[1]])) { // avoid duplicates from double glob
                $raw = @file_get_contents($tempFile);
                if ($raw !== false) {
                    $temps[$m[1]] = round((int)trim($raw) / 1000.0, 1);
                }
            }
        }
    }

    return $temps;
}

/**
 * Detect the block device backing a filesystem path.
 * Parses /proc/mounts, resolves symlinks, strips partition suffixes.
 * Returns ['prefix' => 'md5', 'raid_disks' => 4] for use with parse_diskstats_for_device().
 * @return array{prefix: string, raid_disks: int}
 */
function detect_block_device(string $path, string $mountsPath = '/proc/mounts'): array
{
    $fallback = ['prefix' => 'sd', 'raid_disks' => 1];

    $content = @file_get_contents($mountsPath);
    if ($content === false) return $fallback;

    // Find the longest mount point that is a prefix of $path
    $bestMount = '';
    $bestDev   = '';
    foreach (explode("\n", $content) as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 2) continue;
        $mountPoint = $parts[1];
        $mLen = strlen($mountPoint);
        if (strncmp($path, $mountPoint, $mLen) === 0
            && ($mLen === 1 || strlen($path) === $mLen || $path[$mLen] === '/')
            && $mLen > strlen($bestMount)) {
            $bestMount = $mountPoint;
            $bestDev   = $parts[0];
        }
    }

    if ($bestDev === '') return $fallback;

    // Resolve symlinks (/dev/mapper/..., /dev/disk/by-uuid/...)
    $resolved = @realpath($bestDev) ?: $bestDev;
    $devName  = basename($resolved);

    // Strip partition suffixes: nvme0n1p1→nvme0n1, md5p1→md5, sda1→sda
    if (preg_match('/^(nvme\d+n\d+)/', $devName, $m)) {
        $baseDev = $m[1];
    } elseif (preg_match('/^(md\d+)/', $devName, $m)) {
        $baseDev = $m[1];
    } elseif (preg_match('/^(sd[a-z]+)/', $devName, $m)) {
        $baseDev = $m[1];
    } elseif (preg_match('/^(dm-\d+)/', $devName, $m)) {
        $baseDev = $m[1];
    } else {
        $baseDev = $devName;
    }

    // Read raid_disks if this is an md RAID device
    $raidDisks = 1;
    if (preg_match('/^md\d+$/', $baseDev)) {
        $rd = @file_get_contents("/sys/block/{$baseDev}/md/raid_disks");
        if ($rd !== false) {
            $raidDisks = max(1, (int)trim($rd));
        }
    }

    return ['prefix' => $baseDev, 'raid_disks' => $raidDisks];
}

/**
 * Derive a short human label from a block device name prefix.
 * Returns 'RAID', 'NVMe', 'HDD', 'VPS', 'Docker', or 'Disque'.
 */
function label_from_device(string $devName): string
{
    if (preg_match('/^md\d/',    $devName)) return 'RAID';
    if (preg_match('/^nvme/',    $devName)) return 'NVMe';
    if (preg_match('/^sd[a-z]/', $devName)) return 'HDD';
    if (preg_match('/^vd[a-z]/', $devName)) return 'VPS';
    if ($devName === 'overlay' || $devName === 'overlay2') return 'Docker';
    if ($devName === 'tmpfs')                               return 'tmpfs';
    return 'Disque';
}

/**
 * Guess a short human label for a filesystem path based on its backing device.
 * Returns 'RAID', 'NVMe', 'HDD', 'VPS', 'Docker', or 'Disque'.
 */
function guess_volume_label(string $path, string $mountsPath = '/proc/mounts'): string
{
    return label_from_device(detect_block_device($path, $mountsPath)['prefix']);
}

/**
 * Auto-detect unique storage volumes from configured paths.
 * Deduplicates by device id (stat dev) so the same filesystem appears only once.
 *
 * Candidate paths (in order): BASE_PATH, MEDIA_ROOT, NVME_PATH, dirname(DB_PATH).
 *
 * Returns each volume with space stats plus internal fields _block_prefix/_raid_disks
 * (prefixed with _ to signal they are stripped before JSON output by the caller).
 *
 * @param string $mountsPath Injectable for tests
 * @return array<int, array{label: string, total_gb: float, used_gb: float, free_gb: float, _block_prefix: string, _raid_disks: int}>
 */
function detect_storage_volumes(string $mountsPath = '/proc/mounts'): array
{
    $candidates = [];
    $bp = defined('BASE_PATH')  ? BASE_PATH  : '';
    $mr = defined('MEDIA_ROOT') ? MEDIA_ROOT : '';
    $np = defined('NVME_PATH')  ? NVME_PATH  : '';
    $dp = defined('DB_PATH')    ? DB_PATH    : '';
    if ($bp !== '')                       $candidates[] = $bp;
    if ($mr !== '' && is_dir($mr))        $candidates[] = $mr;
    if ($np !== '' && is_dir($np))        $candidates[] = $np;
    if ($dp !== '')                       $candidates[] = dirname($dp);

    $seen    = []; // device-id → true
    $volumes = [];
    foreach ($candidates as $path) {
        $real = @realpath($path);
        if ($real === false || !is_dir($real)) continue;
        $st = @stat($real);
        if ($st === false) continue;
        $dev = $st['dev'];
        if (isset($seen[$dev])) continue;
        $seen[$dev] = true;

        $total     = (float)(@disk_total_space($real) ?: 0);
        $free      = (float)(@disk_free_space($real)  ?: 0);
        if ($total <= 0.0) continue;

        $blockInfo = detect_block_device($real, $mountsPath);

        $volumes[] = [
            'label'          => label_from_device($blockInfo['prefix']),
            'total_gb'       => round($total           / 1073741824, 1),
            'used_gb'        => round(($total - $free) / 1073741824, 1),
            'free_gb'        => round($free            / 1073741824, 1),
            '_block_prefix'  => $blockInfo['prefix'],
            '_raid_disks'    => $blockInfo['raid_disks'],
        ];
    }
    return $volumes;
}

/**
 * Parse /proc/diskstats for the first device whose name starts with $prefix.
 * Returns the split fields array or null.
 * @return array<int, string>|null
 *
 * /proc/diskstats fields (1-indexed, or 0-indexed in returned array):
 *   [0]=major [1]=minor [2]=name
 *   [3]=rd_ios [4]=rd_merge [5]=rd_sectors [6]=rd_ticks
 *   [7]=wr_ios [8]=wr_merge [9]=wr_sectors [10]=wr_ticks
 *   [11]=ios_in_prog [12]=tot_ticks(busy ms) [13]=rq_ticks
 */
function parse_diskstats_for_device(string $device, string $diskstatsPath = '/proc/diskstats'): ?array
{
    $content = @file_get_contents($diskstatsPath);
    if ($content === false) return null;
    foreach (explode("\n", $content) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 14) continue;
        if ($parts[2] === $device) {
            return $parts;
        }
    }
    return null;
}
