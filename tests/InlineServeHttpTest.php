<?php

use PHPUnit\Framework\TestCase;

/**
 * Runs the real download.php router behind php -S: ?inline=1 serves PDFs and covers to the
 * reader (Range, no compression), and nothing outside a short list of types.
 */
class InlineServeHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $work = '';
    private static string $content = '';

    public static function setUpBeforeClass(): void
    {
        // /dev/shm keeps atime (the HEAD check needs it); / is mounted noatime here.
        self::$work = (is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir()) . '/sb_inline_' . bin2hex(random_bytes(6));
        mkdir(self::$work . '/share/Livres', 0755, true);
        self::$content = '%PDF-1.4 ';
        for ($i = 0; strlen(self::$content) < 10000; $i++) {
            self::$content .= chr($i % 251);
        }
        $dir = self::$work . '/share/Livres';
        file_put_contents("$dir/book.pdf", self::$content);
        file_put_contents("$dir/cover.jpg", "\xFF\xD8\xFFfake jpeg");
        file_put_contents("$dir/page.html", '<script>alert(1)</script>');
        file_put_contents("$dir/song.mp3", 'ID3 fake');
        file_put_contents("$dir/probe.pdf", self::$content);

        $db = self::$work . '/db.sqlite';
        $download = realpath(__DIR__ . '/../download.php');
        $vars = var_export(['db' => $db, 'download' => $download], true);
        // display_errors off: a live config.php redefining constants must not leak into the body.
        file_put_contents(self::$work . '/router.php', <<<PHP
        <?php
        \$c = $vars;
        ini_set('display_errors', '0');
        define('DB_PATH', \$c['db']);
        define('XACCEL_PREFIX', '');
        define('STREAM_LOG', false);
        define('SHAREBOX_BROWSE_ENTRY', true);
        \$_GET['token'] = 'inlinetest';
        require \$c['download'];
        PHP);

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::cleanUp();
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // zlib.output_compression on: PHP itself must not gzip what the reader fetches by Range.
        $cmd = sprintf('exec %s -d zlib.output_compression=On -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY), $port, escapeshellarg(self::$work . '/router.php'));
        self::$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        self::$base = "http://127.0.0.1:$port/";

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($fp) { fclose($fp); break; }
            usleep(100000);
        }
        if (!$fp) {
            self::cleanUp();
            self::markTestSkipped('php -S did not start');
        }

        // First request creates the schema; the link row goes in after.
        self::request('GET', 'p=Livres/book.pdf&inline=1');
        $pdo = new PDO('sqlite:' . $db);
        $pdo->prepare("INSERT INTO links (token, path, type, name) VALUES ('inlinetest', ?, 'folder', 'Partage')")
            ->execute([self::$work . '/share']);
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanUp();
    }

    private static function cleanUp(): void
    {
        if (is_resource(self::$proc)) {
            $s = proc_get_status(self::$proc);
            if ($s['running']) {
                @posix_kill($s['pid'], SIGTERM);
                for ($i = 0; $i < 20 && proc_get_status(self::$proc)['running']; $i++) usleep(50000);
                if (proc_get_status(self::$proc)['running']) @posix_kill($s['pid'], SIGKILL);
            }
            proc_close(self::$proc);
        }
        if (!preg_match('#^(/dev/shm|' . preg_quote(sys_get_temp_dir(), '#') . ')/sb_inline_[0-9a-f]{12}$#', self::$work)) return;
        shell_exec('rm -rf -- ' . escapeshellarg(self::$work));
    }

    /**
     * @param string[] $headers
     * @return array{code:int, headers:array<string,string>, body:string}
     */
    private static function request(string $method, string $query, array $headers = []): array
    {
        $ch = curl_init(self::$base . '?' . $query);
        $raw = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$raw) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $raw[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $body = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => $raw, 'body' => $body];
    }

    public function testPdfIsServedInline(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&inline=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('application/pdf', $r['headers']['content-type'] ?? null);
        $this->assertSame('inline; filename="book.pdf"', $r['headers']['content-disposition'] ?? null);
        $this->assertSame('bytes', $r['headers']['accept-ranges'] ?? null);
        $this->assertStringContainsString('no-transform', $r['headers']['cache-control'] ?? '');
        $this->assertSame((string)strlen(self::$content), $r['headers']['content-length'] ?? null);
        $this->assertSame(self::$content, $r['body']);
    }

    public function testRangeReturnsPartialContent(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&inline=1', ['Range: bytes=100-199']);
        $this->assertSame(206, $r['code']);
        $this->assertSame('bytes 100-199/' . strlen(self::$content), $r['headers']['content-range'] ?? null);
        $this->assertSame('100', $r['headers']['content-length'] ?? null);
        $this->assertSame(substr(self::$content, 100, 100), $r['body']);
    }

    public function testCoverIsServedAsImage(): void
    {
        $r = self::request('GET', 'p=Livres/cover.jpg&inline=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('image/jpeg', $r['headers']['content-type'] ?? null);
    }

    /** @dataProvider refusedProvider */
    public function testTypesOutsideTheListAreRefused(string $file): void
    {
        $r = self::request('GET', 'p=Livres/' . $file . '&inline=1');
        $this->assertSame(404, $r['code']);
        $this->assertStringNotContainsString('alert(1)', $r['body']);
        $this->assertStringNotContainsString('ID3', $r['body']);
    }

    public static function refusedProvider(): array
    {
        return ['html' => ['page.html'], 'audio' => ['song.mp3']];
    }

    public function testHeadSendsHeadersWithoutBody(): void
    {
        $r = self::request('HEAD', 'p=Livres/book.pdf&inline=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('application/pdf', $r['headers']['content-type'] ?? null);
        $this->assertSame((string)strlen(self::$content), $r['headers']['content-length'] ?? null);
        $this->assertSame('', $r['body']);
    }

    public function testHeadDoesNotReadTheFile(): void
    {
        // An atime older than ctime is bumped by the next read, even under relatime.
        $file = self::$work . '/share/Livres/probe.pdf';
        touch($file, time(), 946684800);
        clearstatcache();
        $head = self::request('HEAD', 'p=Livres/probe.pdf&inline=1', ['Range: bytes=0-99']);
        clearstatcache();
        $this->assertSame(206, $head['code']);
        $this->assertSame('bytes 0-99/' . strlen(self::$content), $head['headers']['content-range'] ?? null);
        $this->assertSame(946684800, fileatime($file), 'HEAD must not read the file');

        // The GET twin must move atime, otherwise this filesystem proves nothing.
        self::request('GET', 'p=Livres/probe.pdf&inline=1', ['Range: bytes=0-99']);
        clearstatcache();
        if (fileatime($file) === 946684800) {
            $this->markTestSkipped('atime is not updated on this filesystem');
        }
    }

    public function testNoCompressionEvenWhenGzipIsAccepted(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&inline=1', ['Accept-Encoding: gzip']);
        $this->assertSame(200, $r['code']);
        $this->assertArrayNotHasKey('content-encoding', $r['headers']);
        $this->assertSame((string)strlen(self::$content), $r['headers']['content-length'] ?? null);
        $this->assertSame(self::$content, $r['body']);
    }

    public function testPlainDownloadStaysAnAttachment(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf');
        $this->assertSame(200, $r['code']);
        $this->assertSame('application/octet-stream', $r['headers']['content-type'] ?? null);
        $this->assertStringStartsWith('attachment', $r['headers']['content-disposition'] ?? '');
    }
}
