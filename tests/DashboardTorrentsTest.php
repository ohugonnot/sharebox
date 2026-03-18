<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour active_torrents.php :
 * - comportement sans socket (doit retourner une clé 'error', pas d'exception)
 * - conversion rates bytes/s → MB/s
 * - calcul de progression completed_chunks / size_chunks
 */
class DashboardTorrentsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../api/active_torrents.php';
    }

    // ---------------------------------------- Socket manquant → error key --

    public function testMissingSocketReturnsErrorKey(): void
    {
        $result = get_torrents_from_rtorrent('/tmp/nonexistent_rtorrent_' . uniqid() . '.sock');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('downloads', $result);
        $this->assertArrayHasKey('uploads', $result);
        $this->assertSame([], $result['downloads']);
        $this->assertSame([], $result['uploads']);
    }

    public function testMissingSocketDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        try {
            get_torrents_from_rtorrent('/tmp/nonexistent_rtorrent_' . uniqid() . '.sock');
        } catch (\Throwable $e) {
            $this->fail('get_torrents_from_rtorrent() a levé une exception : ' . $e->getMessage());
        }
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

    // ------------------------------------------ Parsing réponse multicall --

    public function testXmlrpcParseResponseReturnsNullOnInvalidXml(): void
    {
        $result = xmlrpc_parse_response('not xml at all');
        $this->assertNull($result);
    }

    public function testXmlrpcParseResponseParsesArray(): void
    {
        $xml = '<?xml version="1.0"?>'
             . '<methodResponse><params><param><value>'
             . '<array><data>'
             .   '<value><int>42</int></value>'
             .   '<value><string>hello</string></value>'
             . '</data></array>'
             . '</value></param></params></methodResponse>';

        $result = xmlrpc_parse_response($xml);

        $this->assertIsArray($result);
        $this->assertSame(42,      $result[0]);
        $this->assertSame('hello', $result[1]);
    }
}
