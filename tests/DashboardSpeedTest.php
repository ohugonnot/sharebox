<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la détection d'interface et du parsing /proc/net/dev.
 */
class DashboardSpeedTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../api/dashboard_helpers.php';
        $this->tmpDir = sys_get_temp_dir() . '/sb_speed_test_' . uniqid();
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

    // ------------------------------------------------ detect_primary_iface --

    public function testDetectPrimaryIfaceFindsDefaultRoute(): void
    {
        $routeFile = $this->tmpDir . '/route';
        // Format : Iface Dest Gateway Flags RefCnt Use Metric Mask MTU Window IRTT
        // Flags 0003 = RTF_UP | RTF_GATEWAY (route par défaut)
        file_put_contents($routeFile, implode("\n", [
            'Iface   Destination Gateway Flags RefCnt Use Metric Mask     MTU Window IRTT',
            'eth0    00000000    0101A8C0 0003    0      0    0   00000000  0   0      0',
            'eth0    00FEA8C0    00000000 0001    0      0    0   00FFFFFF  0   0      0',
        ]));

        $result = detect_primary_iface($routeFile);
        $this->assertSame('eth0', $result);
    }

    public function testDetectPrimaryIfaceReturnsEmptyStringWhenNoDefaultRoute(): void
    {
        $routeFile = $this->tmpDir . '/route';
        file_put_contents($routeFile, implode("\n", [
            'Iface   Destination Gateway Flags RefCnt Use Metric Mask     MTU Window IRTT',
            'eth0    00FEA8C0    00000000 0001    0      0    0   00FFFFFF  0   0      0',
        ]));

        $result = detect_primary_iface($routeFile);
        $this->assertSame('', $result);
    }

    public function testDetectPrimaryIfaceReturnsEmptyOnMissingFile(): void
    {
        $result = detect_primary_iface('/tmp/does_not_exist_route_' . uniqid());
        $this->assertSame('', $result);
    }

    // ------------------------------------------------------- parse_net_dev --

    public function testParseNetDevExtractsByteCounters(): void
    {
        $netDevFile = $this->tmpDir . '/net_dev';
        // Format /proc/net/dev — 16 champs après "iface:"
        // rx: bytes packets errs drop fifo frame compressed multicast
        // tx: bytes packets errs drop fifo colls carrier compressed
        $line = 'eth0: 1234567890 100000 0 0 0 0 0 0 987654321 80000 0 0 0 0 0 0';
        file_put_contents($netDevFile, implode("\n", [
            'Inter-|   Receive                                                |  Transmit',
            ' face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed',
            $line,
        ]));

        $result = parse_net_dev('eth0', $netDevFile);

        $this->assertNotNull($result);
        $this->assertSame(1234567890, $result['rx_bytes']);
        $this->assertSame(987654321,  $result['tx_bytes']);
    }

    public function testParseNetDevReturnsNullForUnknownInterface(): void
    {
        $netDevFile = $this->tmpDir . '/net_dev';
        file_put_contents($netDevFile, "Inter-|\n face |\nlo: 100 1 0 0 0 0 0 0 100 1 0 0 0 0 0 0\n");

        $result = parse_net_dev('eth0', $netDevFile);
        $this->assertNull($result);
    }

    public function testParseNetDevReturnsNullOnMissingFile(): void
    {
        $result = parse_net_dev('eth0', '/tmp/does_not_exist_netdev_' . uniqid());
        $this->assertNull($result);
    }

    // -------------------------------------------- Calcul débit (arithmétique) --

    public function testNetSpeedCalculationMbs(): void
    {
        // Simulation : 100 MB reçus en 10 secondes = 10 MB/s
        $rxBefore  = 1_000_000_000;
        $rxAfter   = 1_100_000_000; // +100 MB
        $elapsed   = 10.0;          // secondes

        $downloadMbs = ($rxAfter - $rxBefore) / $elapsed / 1048576;

        $this->assertEqualsWithDelta(9.537, $downloadMbs, 0.001);
    }
}
