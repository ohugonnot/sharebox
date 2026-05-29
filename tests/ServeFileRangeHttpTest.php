<?php

use PHPUnit\Framework\TestCase;

/**
 * Test d'INTÉGRATION réel de serve_file() (functions.php) en mode direct
 * (sans X-Accel / nginx) : on lance un php -S éphémère qui appelle serve_file()
 * et on vérifie le comportement Range HTTP de bout en bout (206 / Content-Range /
 * 416 / suffixe / corps réellement servi).
 *
 * Contrairement à ServeFileRangeTest (qui réimplémente l'arithmétique), ici on
 * EXÉCUTE le code de production : c'est le chemin qui sert des octets de fichiers
 * arbitraires, jamais couvert auparavant.
 */
class ServeFileRangeHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $fixture = '';
    private static string $router = '';
    private static string $content = '';
    private static int $size = 0;

    public static function setUpBeforeClass(): void
    {
        // Fixture déterministe (10000 octets, motif connu pour vérifier les octets servis)
        self::$content = '';
        for ($i = 0; $i < 10000; $i++) {
            self::$content .= chr($i % 251); // 251 premier → pas d'alignement trompeur sur 256
        }
        self::$size = strlen(self::$content);
        self::$fixture = tempnam(sys_get_temp_dir(), 'sb_fixture_');
        file_put_contents(self::$fixture, self::$content);

        // Routeur de test : appelle serve_file() SANS définir XACCEL_PREFIX → service direct
        self::$router = tempnam(sys_get_temp_dir(), 'sb_router_') . '.php';
        $functions = realpath(__DIR__ . '/../functions.php');
        file_put_contents(self::$router, <<<PHP
        <?php
        require '$functions';
        serve_file(\$_GET['f'] ?? '', 'application/octet-stream', 'inline; filename="x.bin"');
        PHP);

        // Port libre éphémère
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(self::$router)
        );
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
        self::$proc = proc_open($cmd, $descriptors, $pipes);
        self::$base = "http://127.0.0.1:$port";

        // Attendre que le serveur réponde
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($fp) { fclose($fp); $ready = true; break; }
            usleep(100000);
        }
        if (!$ready) {
            self::markTestSkipped('php -S did not start');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            $status = proc_get_status(self::$proc);
            if ($status['running']) {
                // tuer le process (et son groupe le cas échéant)
                @proc_terminate(self::$proc, SIGTERM);
            }
            proc_close(self::$proc);
        }
        foreach ([self::$fixture, self::$router] as $f) {
            if ($f && file_exists($f)) @unlink($f);
        }
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function httpGet(string $rangeHeader = ''): array
    {
        $http = ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 5];
        if ($rangeHeader !== '') {
            $http['header'] = 'Range: ' . $rangeHeader;
        }
        $ctx = stream_context_create(['http' => $http]);
        $url = self::$base . '/?f=' . rawurlencode(self::$fixture);
        $body = @file_get_contents($url, false, $ctx);
        $raw = $http_response_header ?? [];

        $status = 0;
        $headers = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body];
    }

    public function testFullRequestReturns200WithFullBodyAndAcceptRanges(): void
    {
        $r = $this->httpGet();
        $this->assertSame(200, $r['status']);
        $this->assertSame('bytes', $r['headers']['accept-ranges'] ?? null);
        $this->assertSame(self::$size, strlen($r['body']));
        $this->assertSame(self::$content, $r['body']);
    }

    public function testRangeReturns206WithCorrectContentRangeAndBytes(): void
    {
        $r = $this->httpGet('bytes=0-99');
        $this->assertSame(206, $r['status']);
        $this->assertSame('bytes 0-99/' . self::$size, $r['headers']['content-range'] ?? null);
        $this->assertSame(100, strlen($r['body']));
        $this->assertSame(substr(self::$content, 0, 100), $r['body']);
    }

    public function testMidRangeReturnsExactSlice(): void
    {
        $r = $this->httpGet('bytes=500-1499');
        $this->assertSame(206, $r['status']);
        $this->assertSame('bytes 500-1499/' . self::$size, $r['headers']['content-range'] ?? null);
        $this->assertSame(1000, strlen($r['body']));
        $this->assertSame(substr(self::$content, 500, 1000), $r['body']);
    }

    public function testSuffixRangeReturnsLastBytes(): void
    {
        $r = $this->httpGet('bytes=-100');
        $this->assertSame(206, $r['status']);
        $this->assertSame('bytes ' . (self::$size - 100) . '-' . (self::$size - 1) . '/' . self::$size, $r['headers']['content-range'] ?? null);
        $this->assertSame(substr(self::$content, -100), $r['body']);
    }

    public function testOpenEndedRangeServesToEnd(): void
    {
        $r = $this->httpGet('bytes=9900-');
        $this->assertSame(206, $r['status']);
        $this->assertSame(100, strlen($r['body']));
        $this->assertSame(substr(self::$content, 9900), $r['body']);
    }

    public function testUnsatisfiableRangeReturns416(): void
    {
        $r = $this->httpGet('bytes=999999-1000000');
        $this->assertSame(416, $r['status']);
        $this->assertSame('bytes */' . self::$size, $r['headers']['content-range'] ?? null);
    }
}
