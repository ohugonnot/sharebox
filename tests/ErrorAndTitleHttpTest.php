<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ComicFixtures.php';

/** Real download.php behind php -S: error pages offer a way back, listings show the current folder. */
class ErrorAndTitleHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $work = '';
    private const XSS_DIR = 'x<img src=x onerror=alert(1)>&"y';

    public static function setUpBeforeClass(): void
    {
        $shm = is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        self::$work = $shm . '/sb_errtitle_' . bin2hex(random_bytes(6));
        mkdir(self::$work . '/share/Livres/Tome 1', 0755, true);
        mkdir(self::$work . '/share/' . self::XSS_DIR, 0755, true);
        file_put_contents(self::$work . '/share/Livres/note.txt', 'x');
        file_put_contents(self::$work . '/share/Livres/film.mkv.meta', '');
        file_put_contents(self::$work . '/share/Livres/roman.pdf', '%PDF-1.4');
        file_put_contents(self::$work . '/share/Livres/Tome 1/unknown.zzz', 'x');

        $vars = var_export(['db' => self::$work . '/db.sqlite', 'download' => realpath(__DIR__ . '/../download.php')], true);
        file_put_contents(self::$work . '/router.php', <<<PHP
        <?php
        \$c = $vars;
        ini_set('display_errors', '0');
        define('DB_PATH', \$c['db']);
        define('XACCEL_PREFIX', '');
        define('STREAM_LOG', false);
        define('SHAREBOX_BROWSE_ENTRY', true);
        require \$c['download'];
        PHP);

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::cleanUp();
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // exec: without it the shell survives SIGTERM and php -S is left orphaned.
        $cmd = sprintf('exec %s -d sys_temp_dir=%s -S 127.0.0.1:%d %s',
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

        self::request('token=nope'); // creates the schema
        $ins = (new PDO('sqlite:' . self::$work . '/db.sqlite'))
            ->prepare('INSERT INTO links (token, path, type, name, password_hash, expires_at, max_downloads) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ins->execute(['listtest', self::$work . '/share', 'folder', 'Partage', null, null, null]);
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

    /** @return array{code:int, body:string} */
    private static function request(string $query): array
    {
        $ch = curl_init(self::$base . '?' . $query);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }

    private static function backHref(string $body): ?string
    {
        return preg_match('/<a class="btn-back" href="([^"]*)"/', $body, $m) ? html_entity_decode($m[1]) : null;
    }

    public function testUnknownTokenFallsBackToSiteRootWithHistoryHandler(): void
    {
        $r = self::request('token=nope');
        $this->assertSame(404, $r['code']);
        $this->assertSame('/', self::backHref($r['body']));
        $this->assertStringContainsString('history.back()', $r['body']);
        $this->assertStringContainsString('Retour', $r['body']);
        $this->assertStringNotContainsString('⚠', $r['body']);
        $this->assertStringContainsString('<svg', $r['body']);
    }

    public function testForbiddenSubPathGoesBackToLinkRoot(): void
    {
        $r = self::request('token=listtest&p=' . rawurlencode('Livres/../../etc'));
        $this->assertSame(403, $r['code']);
        $this->assertSame('/dl/listtest', self::backHref($r['body']));
        $this->assertStringNotContainsString('history.back()', $r['body']);
        $this->assertStringNotContainsString('⚠', $r['body']);
    }

    public function testMissingSubPathGoesBackToLinkRoot(): void
    {
        $r = self::request('token=listtest&p=' . rawurlencode('Livres/absent'));
        $this->assertContains($r['code'], [403, 404]);
        $this->assertSame('/dl/listtest', self::backHref($r['body']));
    }

    public function testUnreadableInlineFileGoesBackToParentFolder(): void
    {
        $r = self::request('token=listtest&inline=1&p=' . rawurlencode('Livres/Tome 1/unknown.zzz'));
        $this->assertSame(404, $r['code']);
        $this->assertSame('/dl/listtest?p=' . rawurlencode('Livres/Tome 1'), self::backHref($r['body']));
    }

    public function testListingRootKeepsLinkName(): void
    {
        $r = self::request('token=listtest');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('<title>Partage</title>', $r['body']);
        $this->assertStringContainsString('<div class="header-title">Partage</div>', $r['body']);
    }

    public function testListingSubfolderShowsFolderNameInTitleAndHeader(): void
    {
        $r = self::request('token=listtest&p=' . rawurlencode('Livres/Tome 1'));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('<title>Tome 1 — Partage</title>', $r['body']);
        $this->assertStringContainsString('<div class="header-title">Tome 1</div>', $r['body']);
        $this->assertStringContainsString('>Partage</a>', $r['body'], 'link name stays in the breadcrumb');
    }

    public function testFolderNameWithHtmlIsEscaped(): void
    {
        $r = self::request('token=listtest&p=' . rawurlencode(self::XSS_DIR));
        $this->assertSame(200, $r['code']);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $r['body']);
        $esc = htmlspecialchars(self::XSS_DIR);
        $this->assertStringContainsString("<title>$esc — Partage</title>", $r['body']);
        $this->assertStringContainsString("<div class=\"header-title\">$esc</div>", $r['body']);
    }

    public function testListingHidesRtorrentMetaFiles(): void
    {
        $r = self::request('token=listtest&p=Livres');
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('data-name="note.txt"', $r['body']);
        $this->assertStringNotContainsString('film.mkv.meta', $r['body']);
    }

    public function testSearchAndZipSkipMetaFiles(): void
    {
        $r = self::request('token=listtest&q=film');
        $this->assertSame(200, $r['code']);
        $this->assertStringNotContainsString('film.mkv.meta', $r['body']);

        $z = self::request('token=listtest&zip=1&p=Livres');
        $this->assertStringContainsString('note.txt', $z['body']);
        $this->assertStringNotContainsString('film.mkv.meta', $z['body']);
    }

    public function testBooksGetBookIconAndFilesWithoutPlayKeepAnEmptySlot(): void
    {
        $body = self::request('token=listtest&p=Livres')['body'];
        $this->assertMatchesRegularExpression('/<div class="row-icon book">.*?data-name="roman\.pdf"|data-name="roman\.pdf"[^>]*>\s*<div class="row-icon book">/s', $body);
        $this->assertSame(1, preg_match_all('/<span class="btn-play"[^>]*title="Lire"/', $body));
        $this->assertSame(1, substr_count($body, '<span class="btn-play-slot"></span>'));
    }
}
