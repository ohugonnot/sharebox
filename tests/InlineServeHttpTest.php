<?php

use PHPUnit\Framework\TestCase;

/**
 * Runs the real download.php router behind php -S: ?inline=1 serves PDFs and covers to the
 * reader (Range, no compression), and nothing outside a short list of types; the reader and
 * audiobook pages carry their own CSP.
 */
class InlineServeHttpTest extends TestCase
{
    // A name cannot hold '/', but the sub-path can: it carries '</script>' into the page data.
    private const HOSTILE_DIR = 'H"><img src=x onerror=alert(9)><' . '/script><script>alert(2)';
    private const HOSTILE_BOOKS = ['"><script>alert(1).pdf', "l'été 100%.pdf", "\xff\xfe.pdf", 'a b é.pdf'];
    private const HOSTILE_TRACKS = ['"><svg onload=alert(3)>.mp3', '<script>2.mp3'];

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
        mkdir(self::$work . '/share/Audio');
        foreach (['02.mp3', '01.mp3', 'folder.jpg', 'hidden.mp3'] as $f) file_put_contents(self::$work . "/share/Audio/$f", 'x');
        // Folders with media names, real or symlinked, and a HIDDEN_DIRS name: never tracks or books.
        mkdir(self::$work . '/share/Audio/x.mp3');
        symlink(self::$work . '/share/Audio/x.mp3', self::$work . '/share/Audio/z.mp3');
        mkdir("$dir/y.pdf");
        // A book symlinked from another folder of the share.
        mkdir(self::$work . '/share/Liens');
        file_put_contents(self::$work . '/share/Liens/own.pdf', '%PDF-1.4');
        symlink("$dir/book.pdf", self::$work . '/share/Liens/link.pdf');
        mkdir(self::$work . '/share/Films');
        foreach (['clip.mp4', 'manuel.pdf', 'notice.pdf'] as $f) file_put_contents(self::$work . "/share/Films/$f", 'x');
        file_put_contents("$dir/probe.pdf", self::$content);
        file_put_contents("$dir/x.epub", 'PK fake epub');
        file_put_contents("$dir/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');

        // Hostile names: HTML/JSON breakouts, quotes, %, accents, invalid UTF-8.
        $hostile = self::$work . '/share/' . self::HOSTILE_DIR;
        mkdir($hostile, 0755, true);
        foreach (self::HOSTILE_BOOKS as $f) file_put_contents("$hostile/$f", '%PDF-1.4');
        foreach (self::HOSTILE_TRACKS as $f) file_put_contents("$hostile/$f", 'x');

        // Outside the share, reached by a symlinked folder and a symlinked book.
        mkdir(self::$work . '/secret');
        file_put_contents(self::$work . '/secret/secret.pdf', '%PDF-1.4 secret');
        symlink(self::$work . '/secret', self::$work . '/share/out');
        mkdir(self::$work . '/share/Leak');
        symlink(self::$work . '/secret/secret.pdf', self::$work . '/share/Leak/leak.pdf');
        // Reached through the symlinked folder, but points back inside the share.
        symlink("$dir/book.pdf", self::$work . '/secret/back.pdf');

        // A file link: its siblings, and a symlink out of the tree, are not shared.
        mkdir(self::$work . '/d');
        file_put_contents(self::$work . '/d/mine.pdf', self::$content);
        file_put_contents(self::$work . '/d/secret-contract.pdf', '%PDF-1.4 secret');
        symlink('/etc/passwd', self::$work . '/d/pw.pdf');

        $db = self::$work . '/db.sqlite';
        $download = realpath(__DIR__ . '/../download.php');
        $vars = var_export(['db' => $db, 'download' => $download, 'nvme' => self::$work . '/d'], true);
        // display_errors off: a live config.php redefining constants must not leak into the body.
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
        define('HIDDEN_DIRS', ['hidden.mp3']);
        \$_GET['token'] ??= 'inlinetest';
        // is_path_within() accepts anything under NVME_PATH: here the folder of a shared file.
        if (\$_GET['token'] === 'nvmelink') define('NVME_PATH', \$c['nvme']);
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
        // sys_temp_dir: the download counter's purge flag must not land in the live /tmp.
        $cmd = sprintf('exec %s -d zlib.output_compression=On -d sys_temp_dir=%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY), escapeshellarg(self::$work), $port, escapeshellarg(self::$work . '/router.php'));
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
        $pdo->prepare("INSERT INTO links (token, path, type, name) VALUES ('filelink', ?, 'file', 'mine.pdf')")
            ->execute([self::$work . '/d/mine.pdf']);
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
        $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null);
        $this->assertSame((string)strlen(self::$content), $r['headers']['content-length'] ?? null);
        $this->assertSame(self::$content, $r['body']);
    }

    // Regression: the session's no-cache headers made the browser download the whole book on each open.
    public function testInlineBookIsCacheable(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&inline=1', ['Range: bytes=0-99']);
        $this->assertSame(206, $r['code']);
        $this->assertStringContainsString('private', $r['headers']['cache-control'] ?? '');
        $this->assertStringContainsString('max-age=86400', $r['headers']['cache-control'] ?? '');
        $this->assertStringNotContainsString('no-cache', $r['headers']['cache-control'] ?? '');
        $this->assertArrayNotHasKey('pragma', $r['headers']);
        $this->assertNotEmpty($r['headers']['last-modified'] ?? '');
    }

    public function testRangeReturnsPartialContent(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&inline=1', ['Range: bytes=100-199']);
        $this->assertSame(206, $r['code']);
        $this->assertSame('bytes 100-199/' . strlen(self::$content), $r['headers']['content-range'] ?? null);
        $this->assertSame('100', $r['headers']['content-length'] ?? null);
        $this->assertSame(substr(self::$content, 100, 100), $r['body']);
    }

    public function testRangePastTheEndIsRefused(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&inline=1', ['Range: bytes=99999-']);
        $this->assertSame(416, $r['code']);
        $this->assertSame('bytes */' . strlen(self::$content), $r['headers']['content-range'] ?? null);
    }

    public function testHostileNameIsQuotedInContentDisposition(): void
    {
        $r = self::request('GET', 'p=' . rawurlencode(self::HOSTILE_DIR . '/"><script>alert(1).pdf') . '&inline=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('inline; filename="\\"><script>alert(1).pdf"', $r['headers']['content-disposition'] ?? null);
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
        return ['html' => ['page.html'], 'audio' => ['song.mp3'], 'svg' => ['logo.svg']];
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

    /** @dataProvider rangeProvider */
    public function testHeadOnADownloadSendsTheSameHeadersAsGet(array $range): void
    {
        $get = self::request('GET', 'p=Livres/book.pdf', $range);
        $head = self::request('HEAD', 'p=Livres/book.pdf', $range);
        $this->assertSame($get['code'], $head['code']);
        foreach (['content-type', 'content-disposition', 'content-length', 'accept-ranges', 'content-range'] as $h) {
            $this->assertSame($get['headers'][$h] ?? null, $head['headers'][$h] ?? null, $h);
        }
        $this->assertSame('', $head['body']);
    }

    public static function rangeProvider(): array
    {
        return ['whole file' => [[]], 'range' => [['Range: bytes=100-199']]];
    }

    public function testHeadOnADownloadDoesNotReadTheFile(): void
    {
        $file = self::$work . '/share/Livres/probe.pdf';
        touch($file, time(), 946684800);
        clearstatcache();
        $head = self::request('HEAD', 'p=Livres/probe.pdf');
        clearstatcache();
        $this->assertSame(200, $head['code']);
        $this->assertSame((string)strlen(self::$content), $head['headers']['content-length'] ?? null);
        $this->assertSame(946684800, fileatime($file), 'HEAD must not read the file');
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

    private const CSP = "default-src 'self'; script-src 'self'; worker-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; media-src 'self'; connect-src 'self'; font-src 'self'; "
        . "frame-src 'none'; object-src 'none'; base-uri 'none'";

    public function testReaderPageHasCspAndBooks(): void
    {
        $r = self::request('GET', 'p=Livres&read=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::CSP, $r['headers']['content-security-policy'] ?? null);
        $this->assertStringContainsString('id="sb-reader-data"', $r['body']);
        $this->assertStringContainsString('Livres%2Fbook.pdf\u0026inline=1', $r['body']);
        $this->assertStringContainsString('"open":null', $r['body']);
        $this->assertStringNotContainsString('fonts.googleapis', $r['body']);
        $this->assertSame('no-store', $r['headers']['cache-control'] ?? null);
        $this->assertStringContainsString('<img class="mp-cover" src="/dl/inlinetest?p=Livres%2Fcover.jpg&amp;inline=1"', $r['body']);
        $books = array_column(self::pageJson($r['body'], 'sb-reader-data'), null, 'name');
        $this->assertSame(strlen(self::$content), $books['book.pdf']['size'] ?? null);
    }

    public function testPlayOnABookOpensTheReaderOnIt(): void
    {
        $r = self::request('GET', 'p=Livres/book.pdf&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::CSP, $r['headers']['content-security-policy'] ?? null);
        $this->assertStringContainsString('"open":"book.pdf"', $r['body']);
        $this->assertStringContainsString('"storageToken":"sb:inlinetest:Livres"', $r['body']);
    }

    public function testAudiobookPageHasCspAndTracks(): void
    {
        $r = self::request('GET', 'p=Audio&listen=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::CSP, $r['headers']['content-security-policy'] ?? null);
        $this->assertMatchesRegularExpression('#01\.mp3.*02\.mp3#s', $r['body']);
        $this->assertStringContainsString('id="asc-audiobook-data"', $r['body']);
        $this->assertStringContainsString('class="ab-cover" src="/dl/inlinetest?p=Audio%2Ffolder.jpg&amp;inline=1"', $r['body']);
        $this->assertSame('no-store', $r['headers']['cache-control'] ?? null);
    }

    public function testFoldersAndHiddenNamesAreNeverTracks(): void
    {
        $r = self::request('GET', 'p=Audio&listen=1');
        $this->assertSame(['01.mp3', '02.mp3'], array_column(self::pageJson($r['body'], 'asc-audiobook-data'), 'name'));
    }

    public function testFoldersAreNeverBooks(): void
    {
        $r = self::request('GET', 'p=Livres&read=1');
        $names = array_column(self::pageJson($r['body'], 'sb-reader-data'), 'name');
        $this->assertSame(['book.pdf', 'probe.pdf'], $names);
    }

    public function testPlayOnASymlinkedBookListsTheFolderOfTheLink(): void
    {
        $r = self::request('GET', 'p=Liens/link.pdf&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('"open":"link.pdf"', $r['body']);
        $books = self::pageJson($r['body'], 'sb-reader-data');
        $this->assertSame(['link.pdf', 'own.pdf'], array_column($books, 'name'));
        $this->assertSame('/dl/inlinetest?p=Liens%2Flink.pdf&inline=1', $books[0]['url']);
    }

    public function testPlayThroughAnEscapingFolderListsNothingOutside(): void
    {
        $r = self::request('GET', 'p=out/back.pdf&play=1');
        $this->assertStringNotContainsString('secret', $r['body']);
    }

    /** @dataProvider fileLinkPageProvider */
    public function testAFileLinkNeverShowsItsFolder(string $query): void
    {
        $r = self::request('GET', 'token=filelink&' . $query);
        foreach (['secret-contract', 'pw.pdf', (string)filesize('/etc/passwd')] as $leak) {
            $this->assertStringNotContainsString($leak, $r['body']);
        }
    }

    public static function fileLinkPageProvider(): array
    {
        return ['play' => ['play=1'], 'read' => ['read=1'], 'listen' => ['listen=1']];
    }

    public function testAFileLinkUnderNvmePathNeverShowsItsFolder(): void
    {
        self::addLink('nvmelink', self::$work . '/d/mine.pdf', 'file', null);
        $r = self::request('GET', 'token=nvmelink&play=1');
        $this->assertSame(200, $r['code']);
        foreach (['secret-contract', 'pw.pdf', (string)filesize('/etc/passwd')] as $leak) {
            $this->assertStringNotContainsString($leak, $r['body']);
        }
        $this->assertSame(['mine.pdf'], array_column(self::pageJson($r['body'], 'sb-reader-data'), 'name'));
    }

    public function testPlayOnAFileLinkOpensTheSharedBook(): void
    {
        $r = self::request('GET', 'token=filelink&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('"open":"mine.pdf"', $r['body']);
        $books = self::pageJson($r['body'], 'sb-reader-data');
        $this->assertSame(['mine.pdf'], array_column($books, 'name'));
        $this->assertSame('/dl/filelink?inline=1', $books[0]['url']);
        $this->assertSame(strlen(self::$content), $books[0]['size']);
        $this->assertSame(206, self::request('GET', 'token=filelink&inline=1', ['Range: bytes=0-9'])['code']);
    }

    private static function pdo(): PDO
    {
        return new PDO('sqlite:' . self::$work . '/db.sqlite');
    }

    private static function addLink(string $token, string $path, string $type, ?int $max): void
    {
        self::pdo()->prepare("INSERT INTO links (token, path, type, name, max_downloads) VALUES (?, ?, ?, 'mine.pdf', ?)")
            ->execute([$token, $path, $type, $max]);
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

    public function testALimitedFileLinkStaysAPlainCountedDownload(): void
    {
        // The reader would spend the only download on its first GET, then get 410 on its Range requests.
        self::addLink('lim-inline', self::$work . '/d/mine.pdf', 'file', 1);
        $r = self::request('GET', 'token=lim-inline&inline=1');
        $this->assertSame(200, $r['code']);
        $this->assertStringStartsWith('attachment', $r['headers']['content-disposition'] ?? '');
        $this->assertSame([1, 1], self::counted('lim-inline'));
        $this->assertSame(410, self::request('GET', 'token=lim-inline&inline=1')['code']);

        self::addLink('lim-play', self::$work . '/d/mine.pdf', 'file', 5);
        $r = self::request('GET', 'token=lim-play&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertStringStartsWith('attachment', $r['headers']['content-disposition'] ?? '');
        $this->assertStringNotContainsString('sb-reader-data', $r['body']);
    }

    public function testReadingAnUnlimitedFileLinkIsNotCounted(): void
    {
        self::addLink('free-file', self::$work . '/d/mine.pdf', 'file', null);
        $r = self::request('GET', 'token=free-file&inline=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame('inline; filename="mine.pdf"', $r['headers']['content-disposition'] ?? null);
        $this->assertStringContainsString('"open":"mine.pdf"', self::request('GET', 'token=free-file&play=1')['body']);
        $this->assertSame([0, 0], self::counted('free-file'));
    }

    public function testALimitedFolderLinkKeepsTheReader(): void
    {
        self::addLink('lim-dir', self::$work . '/share', 'folder', 1);
        $this->assertSame(self::CSP, self::request('GET', 'token=lim-dir&p=Livres&read=1')['headers']['content-security-policy'] ?? null);
        $r = self::request('GET', 'token=lim-dir&p=Livres/book.pdf&inline=1');
        $this->assertSame('inline; filename="book.pdf"', $r['headers']['content-disposition'] ?? null);
        $this->assertStringContainsString('sb-reader-data', self::request('GET', 'token=lim-dir&p=Livres/book.pdf&play=1')['body']);
        $this->assertSame([0, 0], self::counted('lim-dir'));
    }

    public function testInlineInAFolderLinkIsNotCounted(): void
    {
        $before = self::counted('inlinetest');
        $this->assertSame(200, self::request('GET', 'p=Livres/book.pdf&inline=1')['code']);
        $this->assertSame($before, self::counted('inlinetest'));
    }

    /** @dataProvider dotDotProvider */
    public function testTitleAndResumeKeyUseTheCanonicalPath(string $query, string $title, string $key): void
    {
        $r = self::request('GET', $query);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('<h1 class="mp-title">' . $title . '</h1>', $r['body']);
        $this->assertMatchesRegularExpression('#"storageToken":"' . preg_quote($key, '#') . '"|data-token="' . preg_quote($key, '#') . '"#', $r['body']);
    }

    public static function dotDotProvider(): array
    {
        return [
            'read' => ['p=Audio/../Livres&read=1', 'Livres', 'sb:inlinetest:Livres'],
            'read root' => ['p=Audio/..&read=1', 'Partage', 'sb:inlinetest:'],
            'play' => ['p=Audio/../Livres/book.pdf&play=1', 'Livres', 'sb:inlinetest:Livres'],
            'listen' => ['p=Livres/../Audio&listen=1', 'Audio', 'sb:inlinetest:Audio'],
        ];
    }

    public function testListingKeepsNoCsp(): void
    {
        $r = self::request('GET', 'p=Livres');
        $this->assertSame(200, $r['code']);
        $this->assertArrayNotHasKey('content-security-policy', $r['headers']);
    }

    public function testLibraryListingOffersReaderButtons(): void
    {
        $r = self::request('GET', 'p=Livres');
        $this->assertStringContainsString('href="/dl/inlinetest?p=Livres&amp;read=1"', $r['body']);
        $this->assertStringContainsString('Bibliothèque · 2 livres', $r['body']);
        $this->assertStringContainsString('data-play="/dl/inlinetest?p=Livres%2Fbook.pdf&amp;play=1"', $r['body']);
        $this->assertStringNotContainsString('data-play="/dl/inlinetest?p=Livres%2Fpage.html', $r['body']);
    }

    public function testAudiobookListingOffersListen(): void
    {
        $r = self::request('GET', 'p=Audio');
        $this->assertStringContainsString('href="/dl/inlinetest?p=Audio&amp;listen=1"', $r['body']);
        $this->assertStringContainsString('Écouter · 2 pistes', $r['body']);
    }

    // A book in a video folder gets its own read arrow, but the folder gets no library button.
    public function testVideoFolderGetsNoFolderButtonButBooksKeepTheirArrow(): void
    {
        $r = self::request('GET', 'p=Films');
        $this->assertStringNotContainsString('read=1', $r['body']);
        $this->assertStringNotContainsString('listen=1', $r['body']);
        $this->assertStringContainsString('manuel.pdf&amp;play=1', $r['body']);
        $this->assertStringContainsString('clip.mp4&amp;play=1', $r['body']);
    }

    /**
     * @return array{name:string, url:string}[]
     */
    private static function pageJson(string $body, string $id): array
    {
        if (!preg_match('#<script type="application/json" id="' . $id . '">(.*?)</script>#s', $body, $m)) return [];
        $data = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        return $data['books'] ?? $data;
    }

    public function testHostileNamesStayInertOnTheReaderPage(): void
    {
        $r = self::request('GET', 'p=' . rawurlencode(self::HOSTILE_DIR) . '&read=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::CSP, $r['headers']['content-security-policy'] ?? null);
        foreach (['<script>alert', '<img src=x', '<svg onload', '</script><script', "l'été"] as $raw) {
            $this->assertStringNotContainsString($raw, $r['body']);
        }
        // Only our own external scripts: one per tag, no inline code.
        $this->assertSame(2, preg_match_all('#<script src="/share/[a-z-]+\.js\?v=\d+"></script>#', $r['body']));
        $this->assertSame(3, substr_count($r['body'], '<script'));

        $books = self::pageJson($r['body'], 'sb-reader-data');
        $names = array_column($books, 'name');
        sort($names);
        $expected = array_map(fn($n) => json_decode(json_encode($n, JSON_INVALID_UTF8_SUBSTITUTE)), self::HOSTILE_BOOKS);
        sort($expected);
        $this->assertSame($expected, $names);
        foreach ($books as $b) {
            $this->assertMatchesRegularExpression('#^/dl/inlinetest\?p=[A-Za-z0-9%._~-]+&inline=1$#', $b['url']);
        }
        // The raw bytes reach the file, even when the shown name had to be repaired.
        $raw = self::request('GET', 'p=' . rawurlencode(self::HOSTILE_DIR . "/\xff\xfe.pdf") . '&inline=1');
        $this->assertSame(200, $raw['code']);
    }

    public function testHostileNamesStayInertOnTheAudiobookPage(): void
    {
        $r = self::request('GET', 'p=' . rawurlencode(self::HOSTILE_DIR) . '&listen=1');
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::CSP, $r['headers']['content-security-policy'] ?? null);
        foreach (['<script>alert', '<img src=x', '<svg onload', '</script><script'] as $raw) {
            $this->assertStringNotContainsString($raw, $r['body']);
        }
        $this->assertSame(self::HOSTILE_TRACKS, array_column(self::pageJson($r['body'], 'asc-audiobook-data'), 'name'));
    }

    public function testPlayOnAVideoStillOpensTheVideoPlayer(): void
    {
        $r = self::request('GET', 'p=Films/clip.mp4&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertArrayNotHasKey('content-security-policy', $r['headers']);
        $this->assertStringContainsString('<video id="player"', $r['body']);
        $this->assertStringNotContainsString('sb-reader-data', $r['body']);
    }

    public function testPlayOnAudioStillOpensThePlayer(): void
    {
        $r = self::request('GET', 'p=Livres/song.mp3&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertArrayNotHasKey('content-security-policy', $r['headers']);
        $this->assertStringContainsString('<audio id="player"', $r['body']);
    }

    /** @dataProvider notABookProvider */
    public function testPlayOnOtherFilesIsAPlainDownload(string $file): void
    {
        $r = self::request('GET', 'p=Livres/' . $file . '&play=1');
        $this->assertSame(200, $r['code']);
        $this->assertArrayNotHasKey('content-security-policy', $r['headers']);
        $this->assertStringStartsWith('attachment', $r['headers']['content-disposition'] ?? '');
    }

    public static function notABookProvider(): array
    {
        return ['html' => ['page.html'], 'epub when disabled' => ['x.epub']];
    }

    /** @dataProvider escapeProvider */
    public function testNothingOutsideTheShareIsReachable(string $query): void
    {
        $r = self::request('GET', $query);
        $this->assertSame(403, $r['code']);
        $this->assertStringNotContainsString('secret', $r['body']);
    }

    public static function escapeProvider(): array
    {
        return [
            'dot-dot reader' => ['p=Livres/../../secret&read=1'],
            'dot-dot play' => ['p=../secret/secret.pdf&play=1'],
            'symlinked folder' => ['p=out&read=1'],
            'symlinked folder audio' => ['p=out&listen=1'],
            'symlinked book inline' => ['p=Leak/leak.pdf&inline=1'],
            'symlinked book play' => ['p=Leak/leak.pdf&play=1'],
        ];
    }
}
