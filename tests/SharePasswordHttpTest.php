<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ComicFixtures.php';

/** Real download.php behind php -S: share-link password attempts are limited per token and client IP. */
class SharePasswordHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $work = '';

    public static function setUpBeforeClass(): void
    {
        $shm = is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        self::$work = $shm . '/sb_sharepwd_' . bin2hex(random_bytes(6));
        mkdir(self::$work . '/share/Livres', 0755, true);
        file_put_contents(self::$work . '/share/Livres/note.txt', 'x');

        $vars = var_export(['db' => self::$work . '/db.sqlite', 'download' => realpath(__DIR__ . '/../download.php')], true);
        file_put_contents(self::$work . '/router.php', <<<PHP
        <?php
        \$c = $vars;
        ini_set('display_errors', '0');
        define('DB_PATH', \$c['db']);
        define('XACCEL_PREFIX', '');
        define('STREAM_LOG', false);
        define('AUTH_FAIL_SLEEP', 0);
        define('SHAREBOX_BROWSE_ENTRY', true);
        if (isset(\$_GET['_ip'])) \$_SERVER['REMOTE_ADDR'] = \$_GET['_ip'];
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
        // Workers: a single-process php -S hides SQLite snapshot/lock races between requests.
        $cmd = sprintf('exec env PHP_CLI_SERVER_WORKERS=8 %s -d sys_temp_dir=%s -d session.save_path=%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY), escapeshellarg(self::$work), escapeshellarg(self::$work), $port, escapeshellarg(self::$work . '/router.php'));
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

        self::post('nope', 'x', '203.0.113.1'); // creates the schema
        $ins = self::db()->prepare('INSERT INTO links (token, path, type, name, password_hash, expires_at, max_downloads) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
        foreach (['pwd1', 'pwd2', 'pwd3', 'pwd4', 'pwd5'] as $t) {
            $ins->execute([$t, self::$work . '/share', 'folder', 'Partage', $hash, null, null]);
        }
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

    private static function db(): PDO
    {
        $db = new PDO('sqlite:' . self::$work . '/db.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout=10000');
        return $db;
    }

    protected function setUp(): void
    {
        self::db()->exec('DELETE FROM share_attempts');
    }

    /**
     * @param list<string> $headers
     * @return array{code:int, body:string}
     */
    private static function post(string $token, string $password, string $ip, ?string $jar = null, array $headers = []): array
    {
        $ch = curl_init(self::$base . '?token=' . $token . '&_ip=' . rawurlencode($ip));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['password' => $password],
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($jar !== null) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        $body = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }

    /** @return array{code:int, body:string} */
    private static function get(string $token, string $ip, ?string $jar = null): array
    {
        $ch = curl_init(self::$base . '?token=' . $token . '&_ip=' . rawurlencode($ip));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        if ($jar !== null) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        $body = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }

    private function wrongTimes(string $token, string $ip, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $r = self::post($token, 'wrong', $ip);
            $this->assertStringContainsString('Mot de passe incorrect.', $r['body'], "attempt $i");
        }
    }

    public function testEleventhAttemptWithoutCookieIsLocked(): void
    {
        $this->wrongTimes('pwd1', '198.51.100.10', 10);
        $r = self::post('pwd1', 'wrong', '198.51.100.10');
        $this->assertSame(429, $r['code']);
        $this->assertStringContainsString('Trop de tentatives', $r['body']);
    }

    public function testCorrectPasswordRefusedWhileLocked(): void
    {
        $this->wrongTimes('pwd1', '198.51.100.11', 10);
        $r = self::post('pwd1', 'secret', '198.51.100.11');
        $this->assertSame(429, $r['code']);
        $this->assertStringContainsString('Trop de tentatives', $r['body']);
    }

    public function testCorrectPasswordUnlocksAndSessionSticks(): void
    {
        $jar = self::$work . '/jar_' . bin2hex(random_bytes(4));
        $r = self::post('pwd1', 'secret', '198.51.100.12', $jar);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('Livres', $r['body']);
        $this->assertStringNotContainsString('Accès protégé', $r['body']);
        $r = self::get('pwd1', '198.51.100.12', $jar);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('Livres', $r['body']);
        $this->assertStringNotContainsString('Accès protégé', $r['body']);
    }

    public function testSuccessResetsCounter(): void
    {
        $ip = '198.51.100.13';
        $this->wrongTimes('pwd1', $ip, 9);
        $this->assertStringContainsString('Livres', self::post('pwd1', 'secret', $ip)['body']);
        $this->wrongTimes('pwd1', $ip, 10);
        $this->assertSame(429, self::post('pwd1', 'wrong', $ip)['code']);
    }

    public function testOtherIpNotLocked(): void
    {
        $this->wrongTimes('pwd1', '198.51.100.14', 10);
        $this->assertSame(429, self::post('pwd1', 'wrong', '198.51.100.14')['code']);
        $r = self::post('pwd1', 'wrong', '198.51.100.15');
        $this->assertStringContainsString('Mot de passe incorrect.', $r['body']);
    }

    public function testOtherTokenNotLocked(): void
    {
        $this->wrongTimes('pwd1', '198.51.100.16', 10);
        $this->assertSame(429, self::post('pwd1', 'wrong', '198.51.100.16')['code']);
        $r = self::post('pwd2', 'wrong', '198.51.100.16');
        $this->assertStringContainsString('Mot de passe incorrect.', $r['body']);
    }

    public function testForwardedHeaderDoesNotBypass(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            self::post('pwd1', 'wrong', '198.51.100.17', null, ['X-Forwarded-For: 10.0.0.' . $i]);
        }
        $r = self::post('pwd1', 'wrong', '198.51.100.17', null, ['X-Forwarded-For: 10.0.0.99']);
        $this->assertSame(429, $r['code']);
    }

    public function testExpiredWindowUnlocks(): void
    {
        $ip = '198.51.100.18';
        $this->wrongTimes('pwd1', $ip, 10);
        $this->assertSame(429, self::post('pwd1', 'wrong', $ip)['code']);
        self::db()->prepare('UPDATE share_attempts SET first_ts = ? WHERE token = ?')
            ->execute([time() - 901, 'pwd1']);
        $r = self::post('pwd1', 'wrong', $ip);
        $this->assertStringContainsString('Mot de passe incorrect.', $r['body']);
    }

    public function testGetFormNotCounted(): void
    {
        for ($i = 0; $i < 20; $i++) self::get('pwd1', '198.51.100.19');
        $this->assertSame(0, (int)self::db()->query('SELECT COUNT(*) FROM share_attempts')->fetchColumn());
    }

    public function testLockoutIsFast(): void
    {
        $this->wrongTimes('pwd1', '198.51.100.20', 10);
        $t = microtime(true);
        self::post('pwd1', 'wrong', '198.51.100.20');
        $this->assertLessThan(1.0, microtime(true) - $t);
    }

    /** Regression: an open read cursor in download.php made concurrent share_attempt() writes die with "database is locked". */
    public function testParallelWrongPasswordsAreCountedWithoutFatal(): void
    {
        $this->runParallelWave('pwd3', '198.51.100.30');
        self::db()->exec('DELETE FROM share_attempts');
        $this->runParallelWave('pwd4', '198.51.100.31');
        self::db()->exec('DELETE FROM share_attempts');
        $this->runParallelWave('pwd5', '198.51.100.32');
    }

    private function runParallelWave(string $token, string $ip): void
    {
        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 12; $i++) {
            $ch = curl_init(self::$base . '?token=' . $token . '&_ip=' . rawurlencode($ip));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['password' => 'wrong'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.1);
        } while ($running);

        $wrong = $locked = 0;
        foreach ($handles as $ch) {
            $body = (string)curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->assertStringNotContainsString('PDOException', $body);
            $this->assertStringNotContainsString('database is locked', $body);
            $this->assertStringNotContainsString('Fatal', $body);
            if ($code === 429) $locked++;
            elseif (str_contains($body, 'Mot de passe incorrect.')) $wrong++;
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);

        $this->assertSame(10, $wrong, "$token: wrong-password responses");
        $this->assertSame(2, $locked, "$token: 429 responses");
        $stmt = self::db()->prepare('SELECT count FROM share_attempts WHERE token = ?');
        $stmt->execute([$token]);
        $this->assertSame(12, (int)$stmt->fetchColumn());
    }
}
