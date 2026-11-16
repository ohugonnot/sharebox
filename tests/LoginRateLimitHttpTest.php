<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ComicFixtures.php';

/** Real login.php behind php -S with several workers: attempts are counted atomically per client IP. */
class LoginRateLimitHttpTest extends TestCase
{
    private const WRONG = 'Identifiants incorrects.';
    private const LOCKED = 'Trop de tentatives.';

    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $work = '';

    public static function setUpBeforeClass(): void
    {
        $shm = is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        self::$work = $shm . '/sb_loginrl_' . bin2hex(random_bytes(6));
        mkdir(self::$work, 0755, true);

        $vars = var_export(['db' => self::$work . '/db.sqlite', 'login' => realpath(__DIR__ . '/../login.php')], true);
        file_put_contents(self::$work . '/router.php', <<<PHP
        <?php
        \$c = $vars;
        ini_set('display_errors', '0');
        define('DB_PATH', \$c['db']);
        define('STREAM_LOG', false);
        define('SHAREBOX_BROWSE_ENTRY', true);
        if (isset(\$_GET['_ip'])) \$_SERVER['REMOTE_ADDR'] = \$_GET['_ip'];
        require \$c['login'];
        PHP);

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::cleanUp();
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // exec: without it the shell survives SIGTERM and php -S is left orphaned.
        // Workers: a single-process php -S hides the check-then-increment race.
        $cmd = sprintf('exec env PHP_CLI_SERVER_WORKERS=8 SHAREBOX_ADMIN_PASS=unused-admin-pass %s -d sys_temp_dir=%s -d session.save_path=%s -S 127.0.0.1:%d %s',
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

        self::post('nobody', 'x', '203.0.113.1'); // creates the schema
        self::db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'user')")
            ->execute(['alice', password_hash('right-pass', PASSWORD_BCRYPT, ['cost' => 4])]);
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

    /** @return array{code:int, body:string} */
    private static function post(string $user, string $password, string $ip): array
    {
        $ch = curl_init(self::$base . '?_ip=' . rawurlencode($ip));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['username' => $user, 'password' => $password],
        ]);
        $body = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }

    private function wrongTimes(string $ip, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $r = self::post('alice', 'bad', $ip);
            $this->assertStringContainsString(self::WRONG, $r['body'], 'attempt ' . ($i + 1));
        }
    }

    public function testSixthAttemptIsLockedWithoutCookie(): void
    {
        $this->wrongTimes('198.51.100.10', 5);
        $r = self::post('alice', 'bad', '198.51.100.10');
        $this->assertSame(429, $r['code']);
        $this->assertStringContainsString(self::LOCKED, $r['body']);
    }

    public function testRightPasswordIsRefusedWhileLocked(): void
    {
        $this->wrongTimes('198.51.100.11', 5);
        $r = self::post('alice', 'right-pass', '198.51.100.11');
        $this->assertSame(429, $r['code']);
        $this->assertStringContainsString(self::LOCKED, $r['body']);
    }

    public function testSuccessClearsTheCounter(): void
    {
        $this->wrongTimes('198.51.100.12', 4);
        $ok = self::post('alice', 'right-pass', '198.51.100.12');
        $this->assertSame(302, $ok['code']);
        $this->wrongTimes('198.51.100.12', 5);
        $this->assertSame(429, self::post('alice', 'bad', '198.51.100.12')['code']);
    }

    public function testOtherIpIsNotLocked(): void
    {
        $this->wrongTimes('198.51.100.13', 5);
        $this->assertSame(429, self::post('alice', 'bad', '198.51.100.13')['code']);
        $this->assertStringContainsString(self::WRONG, self::post('alice', 'bad', '198.51.100.14')['body']);
    }

    /** Regression: check and increment used to be two separate steps, so parallel requests all passed the check. */
    public function testParallelWrongPasswordsAreCappedAtFive(): void
    {
        for ($run = 0; $run < 3; $run++) {
            self::db()->exec('DELETE FROM share_attempts');
            $this->runParallelWave('198.51.100.' . (20 + $run));
        }
    }

    private function runParallelWave(string $ip): void
    {
        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 12; $i++) {
            $ch = curl_init(self::$base . '?_ip=' . rawurlencode($ip));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['username' => 'alice', 'password' => 'bad'],
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
            $this->assertLessThan(500, $code);
            if ($code === 429) $locked++;
            elseif (str_contains($body, self::WRONG)) $wrong++;
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);

        $this->assertSame(5, $wrong, "$ip: wrong-password responses");
        $this->assertSame(7, $locked, "$ip: locked responses");
        $stmt = self::db()->prepare('SELECT count FROM share_attempts WHERE token = ?');
        $stmt->execute([':login']);
        $this->assertSame(12, (int)$stmt->fetchColumn());
    }
}
