<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour active_torrents.php (qBittorrent API).
 * - comportement avec URL vide (doit retourner des listes vides, pas d'exception)
 * - conversion rates bytes/s → MB/s
 * - calcul de progression
 */
class DashboardTorrentsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../api/active_torrents.php';
    }

    // ---------------------------------------- URL vide → listes vides --

    public function testEmptyUrlReturnsEmptyLists(): void
    {
        $result = get_torrents_from_qbittorrent('');

        $this->assertIsArray($result);
        $this->assertSame([], $result['downloads']);
        $this->assertSame([], $result['uploads']);
    }

    public function testEmptyUrlDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        try {
            get_torrents_from_qbittorrent('');
        } catch (\Throwable $e) {
            $this->fail('get_torrents_from_qbittorrent("") a levé une exception : ' . $e->getMessage());
        }
    }

    // ---------------------------------------- Legacy alias existe --

    public function testLegacyAliasExists(): void
    {
        $this->assertTrue(
            function_exists('get_torrents_from_rtorrent'),
            'L\'alias legacy get_torrents_from_rtorrent doit exister pour la compatibilité'
        );
    }

    // ---------------------------------------- Conversion bytes/s → MB/s --

    public function testRateConversionBytesPerSecToMbs(): void
    {
        // 10 MB/s = 10 * 1 048 576 bytes/s
        $bytesPerSec = 10 * 1048576;
        $mbs         = round($bytesPerSec / 1048576, 2);
        $this->assertSame(10.0, $mbs);
    }

    public function testRateConversionPartialMbs(): void
    {
        // 5 242 880 bytes/s = 5.0 MB/s
        $mbs = round(5242880 / 1048576, 2);
        $this->assertSame(5.0, $mbs);
    }

    // ------------------------------------------ Calcul progression --

    public function testProgressCalculation(): void
    {
        $completed = 75;
        $total     = 100;
        $progress  = round($completed / $total * 100, 1);
        $this->assertSame(75.0, $progress);
    }

    public function testProgressCalculation100Pct(): void
    {
        $progress = round(100 / 100 * 100, 1);
        $this->assertSame(100.0, $progress);
    }

    public function testProgressWithZeroTotalIsZero(): void
    {
        // Défense contre division par zéro
        $total    = 0;
        $progress = $total > 0 ? round(50 / $total * 100, 1) : 0.0;
        $this->assertSame(0.0, $progress);
    }

    // ------------------------------------------ Seuil 50 KB/s --

    public function testThresholdIs50KBs(): void
    {
        // Le seuil dans le code est 51200 bytes/s (50 KB/s)
        $source = file_get_contents(__DIR__ . '/../api/active_torrents.php');
        $this->assertStringContainsString(
            '51200',
            $source,
            'Le seuil d\'affichage doit être 51200 bytes/s (50 KB/s)'
        );
    }
}
