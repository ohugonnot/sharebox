<?php

use PHPUnit\Framework\TestCase;

/** Server-side attempt counter for share-link passwords (auth.php). */
class ShareAttemptTest extends TestCase
{
    private string $token;
    /** @var array<string, mixed> */
    private array $serverBackup;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../auth.php';
    }

    protected function setUp(): void
    {
        $this->token = 'tok' . bin2hex(random_bytes(6));
        $this->serverBackup = $_SERVER;
        get_db()->exec('DELETE FROM share_attempts');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        get_db()->exec('DELETE FROM share_attempts');
    }

    private function row(string $ip): array|false
    {
        $s = get_db()->prepare('SELECT count, first_ts FROM share_attempts WHERE token = ? AND ip = ?');
        $s->execute([$this->token, $ip]);
        return $s->fetch();
    }

    public function testFirstAttemptReturnsOne(): void
    {
        $this->assertSame(1, share_attempt($this->token, '198.51.100.1'));
    }

    public function testCountsUp(): void
    {
        $got = [];
        for ($i = 0; $i < 11; $i++) $got[] = share_attempt($this->token, '198.51.100.1');
        $this->assertSame(range(1, 11), $got);
    }

    public function testOtherIpIsIndependent(): void
    {
        share_attempt($this->token, '198.51.100.1');
        share_attempt($this->token, '198.51.100.1');
        $this->assertSame(1, share_attempt($this->token, '198.51.100.2'));
    }

    public function testOtherTokenIsIndependent(): void
    {
        share_attempt($this->token, '198.51.100.1');
        share_attempt($this->token, '198.51.100.1');
        $this->assertSame(1, share_attempt($this->token . 'b', '198.51.100.1'));
        get_db()->prepare('DELETE FROM share_attempts WHERE token = ?')->execute([$this->token . 'b']);
    }

    public function testClearResets(): void
    {
        share_attempt($this->token, '198.51.100.1');
        share_attempt($this->token, '198.51.100.1');
        clear_share_attempts($this->token, '198.51.100.1');
        $this->assertSame(1, share_attempt($this->token, '198.51.100.1'));
    }

    public function testClearLeavesOtherKeysAlone(): void
    {
        share_attempt($this->token, '198.51.100.1');
        share_attempt($this->token, '198.51.100.2');
        share_attempt($this->token, '198.51.100.2');
        clear_share_attempts($this->token, '198.51.100.1');
        $this->assertSame(3, share_attempt($this->token, '198.51.100.2'));
    }

    public function testClearUnknownKeyIsNoop(): void
    {
        clear_share_attempts($this->token, '198.51.100.9');
        $this->assertFalse($this->row('198.51.100.9'));
    }

    public function testExpiredWindowRestartsAtOne(): void
    {
        share_attempt($this->token, '198.51.100.1');
        get_db()->prepare('UPDATE share_attempts SET count = 50, first_ts = ? WHERE token = ?')
            ->execute([time() - AUTH_LOCKOUT_WINDOW - 1, $this->token]);
        $this->assertSame(1, share_attempt($this->token, '198.51.100.1'));
        $this->assertGreaterThanOrEqual(time() - 2, (int)$this->row('198.51.100.1')['first_ts']);
    }

    public function testWindowIsFixedNotSliding(): void
    {
        share_attempt($this->token, '198.51.100.1');
        $start = time() - 800;
        get_db()->prepare('UPDATE share_attempts SET first_ts = ? WHERE token = ?')->execute([$start, $this->token]);
        share_attempt($this->token, '198.51.100.1');
        share_attempt($this->token, '198.51.100.1');
        $this->assertSame($start, (int)$this->row('198.51.100.1')['first_ts']);
    }

    public function testConcurrentAttemptsAllCounted(): void
    {
        // Own database: DatabaseTest unlinks DB_PATH under the parent's open handle.
        $work = sys_get_temp_dir() . '/sb_attempt_' . bin2hex(random_bytes(6));
        mkdir($work, 0700);
        $dbFile = $work . '/db.sqlite';
        $auth = realpath(__DIR__ . '/../auth.php');
        shell_exec(sprintf('%s -d sys_temp_dir=%s -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($work),
            escapeshellarg('define("DB_PATH", ' . var_export($dbFile, true) . '); require ' . var_export($auth, true) . '; get_db();')));
        $code = 'define("DB_PATH", $argv[1]); require $argv[2]; echo share_attempt($argv[3], "198.51.100.1");';
        $procs = [];
        for ($i = 0; $i < 20; $i++) {
            $cmd = sprintf('exec %s -d sys_temp_dir=%s -r %s %s %s %s',
                escapeshellarg(PHP_BINARY), escapeshellarg($work), escapeshellarg($code),
                escapeshellarg($dbFile), escapeshellarg($auth), escapeshellarg($this->token));
            $procs[] = [proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes), $pipes];
        }
        $out = [];
        foreach ($procs as [$p, $pipes]) {
            $out[] = (int)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($p);
        }
        foreach (glob($work . '/*') ?: [] as $f) @unlink($f);
        @rmdir($work);
        sort($out);
        $this->assertSame(range(1, 20), $out);
    }

    public function testIpv4Unchanged(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->assertSame('203.0.113.7', rate_limit_ip());
    }

    public function testIpv6CutTo64(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:3:4:5:6';
        $a = rate_limit_ip();
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:ffff::1';
        $this->assertSame('2001:db8:1:2::', $a);
        $this->assertSame($a, rate_limit_ip());
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
        $this->assertNotSame($a, rate_limit_ip());
    }

    public function testMappedIpv4NotCollapsed(): void
    {
        $_SERVER['REMOTE_ADDR'] = '::ffff:1.2.3.4';
        $a = rate_limit_ip();
        $_SERVER['REMOTE_ADDR'] = '::ffff:5.6.7.8';
        $this->assertNotSame($a, rate_limit_ip());
    }

    public function testIgnoresForwardedHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.9.9';
        $this->assertSame('203.0.113.7', rate_limit_ip());
    }

    public function testMissingRemoteAddr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('0.0.0.0', rate_limit_ip());
    }
}
