<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires des fonctions parse/calc du dashboard sysinfo.
 * Les fichiers /proc/* sont mockés dans /tmp pour isoler les calculs.
 */
class DashboardSysinfoTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../api/dashboard_helpers.php';
        $this->tmpDir = sys_get_temp_dir() . '/sb_sysinfo_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------ CPU --

    public function testParseCpuStatExtractsValues(): void
    {
        // Format /proc/stat : cpu user nice system idle iowait irq softirq steal
        $statFile = $this->tmpDir . '/stat';
        file_put_contents($statFile, "cpu  1000 200 300 5000 100 0 0 0\ncpu0 500 100 150 2500 50 0 0 0\n");

        $result = parse_cpu_stat($statFile);

        $this->assertSame([1000, 200, 300, 5000, 100, 0, 0, 0], $result);
    }

    public function testParseCpuStatReturnsEmptyOnMissingFile(): void
    {
        $result = parse_cpu_stat('/tmp/does_not_exist_cpu_stat_' . uniqid());
        $this->assertSame([], $result);
    }

    public function testCalcCpuPctWithKnownValues(): void
    {
        // Première lecture
        $a = [1000, 0, 500, 8000, 100, 0, 0, 0]; // total=9600, idle=8000, iowait=100
        // Deuxième lecture : +200 active, +100 iowait, +200 idle sur 500 ticks total
        $b = [1200, 0, 500, 8200, 200, 0, 0, 0]; // total=10100

        $result = calc_cpu_pct($a, $b);

        // diff total = 500, idle = 200, iowait = 100, active = 500-200-100 = 200
        $this->assertSame(40.0, $result['active_pct']);  // 200/500*100
        $this->assertSame(20.0, $result['iowait_pct']);  // 100/500*100
        $this->assertSame(40.0, $result['idle_pct']);    // 200/500*100
    }

    public function testCalcCpuPctWithEmptyInputReturnsDefaults(): void
    {
        $result = calc_cpu_pct([], []);
        $this->assertSame(0.0,   $result['active_pct']);
        $this->assertSame(0.0,   $result['iowait_pct']);
        $this->assertSame(100.0, $result['idle_pct']);
    }

    public function testCalcCpuPctClampsNegativeToZero(): void
    {
        // Si les compteurs régressent (wrapping), on ne retourne pas de négatif
        $a = [5000, 0, 0, 5000, 0, 0, 0, 0];
        $b = [5000, 0, 0, 5000, 0, 0, 0, 0]; // aucun changement -> total diff = 0
        $result = calc_cpu_pct($a, $b);
        $this->assertSame(0.0,   $result['active_pct']);
        $this->assertSame(100.0, $result['idle_pct']);
    }

    // ------------------------------------------------------------------ RAM --

    public function testParseMeminfoExtractsValues(): void
    {
        $meminfoFile = $this->tmpDir . '/meminfo';
        file_put_contents($meminfoFile, implode("\n", [
            'MemTotal:       16384000 kB',
            'MemFree:         2048000 kB',
            'MemAvailable:    8192000 kB',
            'Buffers:          512000 kB',
            'Cached:          4096000 kB',
            'SReclaimable:     256000 kB',
        ]));

        $result = parse_meminfo($meminfoFile);

        $this->assertSame(16384000, $result['MemTotal']);
        $this->assertSame(2048000,  $result['MemFree']);
        $this->assertSame(8192000,  $result['MemAvailable']);
        $this->assertSame(512000,   $result['Buffers']);
        $this->assertSame(4096000,  $result['Cached']);
        $this->assertSame(256000,   $result['SReclaimable']);
    }

    public function testParseMeminfoReturnsEmptyOnMissingFile(): void
    {
        $result = parse_meminfo('/tmp/does_not_exist_meminfo_' . uniqid());
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------- Diskstats --

    public function testParseDiskstatsForDeviceFindsExactMatch(): void
    {
        $diskFile = $this->tmpDir . '/diskstats';
        // Format: major minor name rd_ios rd_merge rd_sectors rd_ticks wr_ios wr_merge wr_sectors wr_ticks ios_in_prog tot_ticks rq_ticks
        file_put_contents($diskFile, implode("\n", [
            '   8   0 sda 100 0 2000 500 50 0 1000 200 0 300 700',
            '   9   5 md5 500 0 8000 2000 200 0 4000 800 1 1200 3000',
            '   9   0 md0 10  0 200  50  5  0 100  20  0 30  70',
        ]));

        $result = parse_diskstats_for_device('md5', $diskFile);

        $this->assertNotNull($result);
        $this->assertSame('md5', $result[2]);
        $this->assertSame('8000', $result[5]);  // rd_sectors
        $this->assertSame('4000', $result[9]);  // wr_sectors
        $this->assertSame('1200', $result[12]); // tot_ticks

        // Exact match: 'md' should NOT match 'md5'
        $this->assertNull(parse_diskstats_for_device('md', $diskFile));
    }

    public function testParseDiskstatsForDeviceReturnsNullWhenNotFound(): void
    {
        $diskFile = $this->tmpDir . '/diskstats';
        file_put_contents($diskFile, '   8   0 sda 100 0 2000 500 50 0 1000 200 0 300 700');

        $result = parse_diskstats_for_device('md', $diskFile);
        $this->assertNull($result);
    }
}
