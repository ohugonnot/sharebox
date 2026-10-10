<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ComicFixtures.php';

/**
 * Runs the real download.php router behind php -S: ?inline=1&pages=1 and &page=N serve one
 * page of a CBZ/CBR at a time, under the same access rules as the other inline reads.
 */
class ComicServeHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $work = '';
    private static string $tmpBase = '';
    private static bool $hasRar = false;
    /** @var string[] page bytes of a.cbz in reading order (p1, p2, p10) */
    private static array $pages = [];
    private static string $jpeg = '';

    public static function setUpBeforeClass(): void
    {
        $shm = is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        self::$work = $shm . '/sb_comichttp_' . bin2hex(random_bytes(6));
        $dir = self::$work . '/share/Comics';
        mkdir($dir, 0755, true);

        self::$pages = [ComicFixtures::png(255, 0, 0), ComicFixtures::png(0, 255, 0), ComicFixtures::png(0, 0, 255)];
        // Added out of order: the natural sort must restore p1 < p2 < p10.
        ComicFixtures::zip("$dir/a.cbz", ['p10.png' => self::$pages[2], 'p1.png' => self::$pages[0], 'p2.png' => self::$pages[1], 'notes.txt' => 'x']);
        ComicFixtures::zip("$dir/c.cbr", ['1.png' => self::$pages[1], '2.png' => self::$pages[2]]);
        file_put_contents("$dir/corrupt.cbz", "PK\x03\x04" . random_bytes(400));
        ComicFixtures::zip("$dir/empty.cbz", ['readme.txt' => 'no image here']);
        file_put_contents("$dir/book.pdf", '%PDF-1.4 ' . str_repeat('x', 2000));

        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($im);
        self::$jpeg = (string)ob_get_clean();
        ComicFixtures::zip("$dir/jpg.cbz", ['01.jpg' => self::$jpeg]);
        ComicFixtures::zip("$dir/names.cbz", ['sé rie/02.png' => self::$pages[1], 'sé rie/01 & %20.png' => self::$pages[0]]);

        self::$hasRar = ComicFixtures::rarAvailable()
            && ComicFixtures::rar("$dir/b.cbr", ['1.png' => self::$pages[0], '2.png' => self::$pages[1], 'notes.txt' => 'x']);

        // A comic outside the share, reached through a symlink.
        mkdir(self::$work . '/secret');
        ComicFixtures::zip(self::$work . '/secret/secret.cbz', ['1.png' => ComicFixtures::png(1, 1, 1)]);
        mkdir(self::$work . '/share/Leak');
        symlink(self::$work . '/secret/secret.cbz', self::$work . '/share/Leak/leak.cbz');

        $db = self::$work . '/db.sqlite';
        $download = realpath(__DIR__ . '/../download.php');
        $vars = var_export(['db' => $db, 'download' => $download], true);
        file_put_contents(self::$work . '/router.php', <<<PHP
        <?php
        \$c = $vars;
        ini_set('display_errors', '0');
        define('DB_PATH', \$c['db']);
        define('XACCEL_PREFIX', '');
        define('STREAM_LOG', false);
        define('SHAREBOX_BROWSE_ENTRY', true);
        define('READER_EPUB_ENABLED', false);
        define('STREAM_REMUX_ENABLED', false);
        \$_GET['token'] ??= 'comictest';
        require \$c['download'];
        PHP);

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::cleanUp();
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // sys_temp_dir: the download counter's purge flag must not land in the live /tmp.
        // exec: without it the shell survives SIGTERM and php -S is left orphaned.
        $cmd = sprintf('exec %s -d zlib.output_compression=On -d sys_temp_dir=%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY), escapeshellarg(self::$work), $port, escapeshellarg(self::$work . '/router.php'));
        self::$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        self::$base = "http://127.0.0.1:$port/";

        $fp = false;
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($fp) { fclose($fp); break; }
            usleep(100000);
        }
        if (!$fp) {
            self::cleanUp();
            self::markTestSkipped('php -S did not start');
        }

        // First request creates the schema; the link rows go in after.
        self::request('GET', 'p=Comics/book.pdf&inline=1');
        $pdo = self::pdo();
        $ins = $pdo->prepare('INSERT INTO links (token, path, type, name, password_hash, expires_at, max_downloads) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute(['comictest', self::$work . '/share', 'folder', 'Partage', null, null, null]);
        $ins->execute(['comicpwd', self::$work . '/share', 'folder', 'Prive', password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), null, null]);
        $ins->execute(['comicold', self::$work . '/share', 'folder', 'Vieux', null, gmdate('Y-m-d H:i:s', time() - 3600), null]);
        $ins->execute(['comiclimit', self::$work . '/share/Comics/a.cbz', 'file', 'a.cbz', null, null, 1]);
        $ins->execute(['comicfree', self::$work . '/share/Comics/a.cbz', 'file', 'a.cbz', null, null, null]);
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
        if (self::$work !== '') ComicFixtures::rmrf(self::$work);
    }

    private static function pdo(): PDO
    {
        return new PDO('sqlite:' . self::$work . '/db.sqlite');
    }

    /** @return array{0:int, 1:int} [download_count, download_logs rows] */
    private static function counted(string $token): array
    {
        $pdo = self::pdo();
        $st = $pdo->prepare('SELECT id, download_count FROM links WHERE token = ?');
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $logs = $pdo->prepare('SELECT COUNT(*) FROM download_logs WHERE link_id = ?');
        $logs->execute([$row['id']]);
        return [(int)$row['download_count'], (int)$logs->fetchColumn()];
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
            CURLOPT_TIMEOUT => 15,
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

    private function needRar(): void
    {
        if (!self::$hasRar) $this->markTestSkipped('rar/unrar not available');
    }

    private function assertNotAnImage(array $r): void
    {
        $this->assertStringNotContainsString('image/', $r['headers']['content-type'] ?? '');
        $this->assertStringNotContainsString("\x89PNG", $r['body']);
    }

    // ---- page count

    public function testPageCountIsJson(): void
    {
        $r = self::request('GET', 'p=Comics/a.cbz&inline=1&pages=1');
        $this->assertSame(200, $r['code']);
        $this->assertStringStartsWith('application/json', $r['headers']['content-type'] ?? '');
        $this->assertSame(['pages' => 3], json_decode($r['body'], true));
    }

    public function testCbrNamedLikeAZipIsCounted(): void
    {
        $r = self::request('GET', 'p=Comics/c.cbr&inline=1&pages=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(['pages' => 2], json_decode($r['body'], true));
    }

    public function testRarIsCountedAndServed(): void
    {
        $this->needRar();
        $this->assertSame(['pages' => 2], json_decode(self::request('GET', 'p=Comics/b.cbr&inline=1&pages=1')['body'], true));
        foreach ([1, 2] as $n) {
            $r = self::request('GET', "p=Comics/b.cbr&inline=1&page=$n");
            $this->assertSame(200, $r['code']);
            $this->assertSame('image/png', $r['headers']['content-type'] ?? null);
            $this->assertSame(self::$pages[$n - 1], $r['body']);
        }
    }

    public function testArchiveWithoutImageHasZeroPages(): void
    {
        $r = self::request('GET', 'p=Comics/empty.cbz&inline=1&pages=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(['pages' => 0], json_decode($r['body'], true));
    }

    public function testCorruptArchiveIs422WithAJsonError(): void
    {
        $r = self::request('GET', 'p=Comics/corrupt.cbz&inline=1&pages=1');
        $this->assertSame(422, $r['code']);
        $this->assertArrayHasKey('error', json_decode($r['body'], true) ?? []);
        $this->assertSame(422, self::request('GET', 'p=Comics/corrupt.cbz&inline=1&page=1')['code']);
    }

    // ---- one page

    /** @dataProvider pageNumberProvider */
    public function testEachPageIsTheExpectedImage(int $n): void
    {
        $r = self::request('GET', "p=Comics/a.cbz&inline=1&page=$n");
        $this->assertSame(200, $r['code']);
        $this->assertSame('image/png', $r['headers']['content-type'] ?? null);
        $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null);
        $this->assertSame('private, max-age=86400', $r['headers']['cache-control'] ?? null);
        $this->assertSame(self::$pages[$n - 1], $r['body']);
    }

    public static function pageNumberProvider(): array
    {
        return ['first' => [1], 'second' => [2], 'third, p10 after p2' => [3]];
    }

    public function testPageKeepsTheEntryMimeType(): void
    {
        $r = self::request('GET', 'p=Comics/jpg.cbz&inline=1&page=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('image/jpeg', $r['headers']['content-type'] ?? null);
        $this->assertSame(self::$jpeg, $r['body']);
    }

    public function testEntryNamesWithSpacesAccentsAndPercent(): void
    {
        $this->assertSame(self::$pages[0], self::request('GET', 'p=Comics/names.cbz&inline=1&page=1')['body']);
        $this->assertSame(self::$pages[1], self::request('GET', 'p=Comics/names.cbz&inline=1&page=2')['body']);
    }

    public function testPageIsNotCompressedEvenWhenGzipIsAccepted(): void
    {
        $r = self::request('GET', 'p=Comics/a.cbz&inline=1&page=2', ['Accept-Encoding: gzip']);
        $this->assertSame(200, $r['code']);
        $this->assertArrayNotHasKey('content-encoding', $r['headers']);
        $this->assertSame(self::$pages[1], $r['body']);
    }

    /** @dataProvider badPageProvider */
    public function testInvalidPageIs404(string $query): void
    {
        $r = self::request('GET', "p=Comics/a.cbz&inline=1&$query");
        $this->assertSame(404, $r['code']);
        $this->assertNotAnImage($r);
    }

    public static function badPageProvider(): array
    {
        return [
            'zero' => ['page=0'], 'past the end' => ['page=4'], 'letters' => ['page=abc'],
            'leading zero' => ['page=01'], 'negative' => ['page=-1'], 'decimal' => ['page=1.5'],
            'empty' => ['page='], 'array' => ['page[]=1'], 'huge' => ['page=99999999999999999999'],
            'space' => ['page=%201'], 'newline' => ['page=1%0A'],
        ];
    }

    public function testInlineAloneOnAComicStaysRefused(): void
    {
        foreach (['a.cbz', 'c.cbr'] as $f) {
            $r = self::request('GET', "p=Comics/$f&inline=1");
            $this->assertSame(404, $r['code'], $f);
            $this->assertStringNotContainsString('PK', substr($r['body'], 0, 2));
        }
    }

    public function testPagesAndPageAreIgnoredOnAPdf(): void
    {
        $plain = self::request('GET', 'p=Comics/book.pdf&inline=1');
        foreach (['pages=1', 'page=1', 'page=99'] as $q) {
            $r = self::request('GET', "p=Comics/book.pdf&inline=1&$q");
            $this->assertSame($plain['code'], $r['code'], $q);
            $this->assertSame('application/pdf', $r['headers']['content-type'] ?? null, $q);
            $this->assertSame($plain['body'], $r['body'], $q);
        }
    }

    public function testHeadSendsHeadersWithoutBody(): void
    {
        $r = self::request('HEAD', 'p=Comics/a.cbz&inline=1&page=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('', $r['body']);
    }

    public function testHeadDoesNotExtractAPage(): void
    {
        $this->needRar();
        // An atime older than ctime is bumped by the next read, even under relatime.
        $file = self::$work . '/share/Comics/b.cbr';
        touch($file, time(), 946684800);
        clearstatcache();
        $head = self::request('HEAD', 'p=Comics/b.cbr&inline=1&page=1');
        clearstatcache();
        $this->assertSame(200, $head['code']);
        $this->assertSame(946684800, fileatime($file), 'HEAD must not read the archive');

        self::request('GET', 'p=Comics/b.cbr&inline=1&page=1');
        clearstatcache();
        if (fileatime($file) === 946684800) {
            $this->markTestSkipped('atime is not updated on this filesystem');
        }
    }

    // ---- access rules

    public function testReadingPagesIsNotCounted(): void
    {
        foreach (['pages=1', 'page=1', 'page=2', 'page=9'] as $q) self::request('GET', "p=Comics/a.cbz&inline=1&$q");
        self::request('HEAD', 'p=Comics/a.cbz&inline=1&page=1');
        $this->assertSame([0, 0], self::counted('comictest'));
    }

    public function testPasswordLinkWithoutSessionServesNoPage(): void
    {
        foreach (['pages=1', 'page=1'] as $q) {
            $r = self::request('GET', "token=comicpwd&p=Comics/a.cbz&inline=1&$q");
            $this->assertNotSame(['pages' => 3], json_decode($r['body'], true), $q);
            $this->assertNotAnImage($r);
        }
    }

    public function testExpiredLinkIs410(): void
    {
        foreach (['pages=1', 'page=1'] as $q) {
            $r = self::request('GET', "token=comicold&p=Comics/a.cbz&inline=1&$q");
            // Expired links are purged when the DB opens, so this is usually a 404 "not found".
            $this->assertContains($r['code'], [404, 410], $q);
            $this->assertNotAnImage($r);
        }
    }

    /** @dataProvider traversalProvider */
    public function testPathEscapeIsRefused(string $p): void
    {
        $r = self::request('GET', 'p=' . rawurlencode($p) . '&inline=1&page=1');
        $this->assertContains($r['code'], [403, 404]);
        $this->assertNotAnImage($r);
        $this->assertSame($r['code'], self::request('GET', 'p=' . rawurlencode($p) . '&inline=1&pages=1')['code']);
    }

    public static function traversalProvider(): array
    {
        return [
            'dotdot' => ['../..'],
            'dotdot to a real comic' => ['Comics/../../secret/secret.cbz'],
            'symlink out of the share' => ['Leak/leak.cbz'],
            'missing file' => ['Comics/nope.cbz'],
        ];
    }

    public function testLimitedFileLinkStaysACountedDownload(): void
    {
        // Expected: the reader is off for a limited file link, so page=1 is just the usual
        // counted download of the whole file (attachment), never an image of one page.
        $r = self::request('GET', 'token=comiclimit&inline=1&page=1');
        $this->assertNotAnImage($r);
        $this->assertNotSame('application/json', explode(';', $r['headers']['content-type'] ?? '')[0]);
        $r2 = self::request('GET', 'token=comiclimit&inline=1&pages=1');
        $this->assertNotSame(['pages' => 3], json_decode($r2['body'], true));
    }

    public function testUnlimitedFileLinkServesPages(): void
    {
        $r = self::request('GET', 'token=comicfree&inline=1&page=2');
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::$pages[1], $r['body']);
        $this->assertSame(['pages' => 3], json_decode(self::request('GET', 'token=comicfree&inline=1&pages=1')['body'], true));
        $this->assertSame([0, 0], self::counted('comicfree'));
    }

    // ---- reader page

    /** @return array<string,mixed> */
    private static function readerData(string $body): array
    {
        if (!preg_match('#<script type="application/json" id="sb-reader-data">(.*?)</script>#s', $body, $m)) return [];
        return json_decode($m[1], true) ?? [];
    }

    public function testFolderListsComicsInTheLibraryAndPlayOpensThem(): void
    {
        $lib = self::request('GET', 'p=Comics&read=1');
        $this->assertSame(200, $lib['code']);
        $data = self::readerData($lib['body']);
        $books = array_column($data['books'] ?? [], null, 'name');
        $this->assertArrayHasKey('a.cbz', $books);
        $this->assertSame('comic', $books['a.cbz']['kind']);
        $this->assertStringContainsString('p=Comics%2Fa.cbz', $books['a.cbz']['url']);
        $this->assertStringContainsString('inline=1', $books['a.cbz']['url']);

        $play = self::request('GET', 'p=Comics/a.cbz&play=1');
        $this->assertSame(200, $play['code']);
        $this->assertSame('a.cbz', self::readerData($play['body'])['open'] ?? null);
        $this->assertNotEmpty($play['headers']['content-security-policy'] ?? '');
    }

    public function testHeadWithABadPageIs404WithoutOpeningTheArchive(): void
    {
        $file = self::$work . '/share/Comics/a.cbz';
        touch($file, time(), 946684800);
        clearstatcache();
        foreach (['page=abc', 'page=0', 'page=01', 'page=1%0A'] as $q) {
            $r = self::request('HEAD', "p=Comics/a.cbz&inline=1&$q");
            $this->assertSame(404, $r['code'], $q);
            $this->assertSame('', $r['body']);
        }
        // Well-formed but past the end: HEAD cannot know the count without opening the archive.
        $this->assertSame(200, self::request('HEAD', 'p=Comics/a.cbz&inline=1&page=99')['code']);
        clearstatcache();
        $this->assertSame(946684800, fileatime($file), 'HEAD must not read the archive');
    }
}
