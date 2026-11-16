<?php

use PHPUnit\Framework\TestCase;

/** Login attempt limiting: the ':login' key of share_attempts, counted per client IP. */
class RateLimitTest extends TestCase
{
    private const KEY = ':login';

    /** @var array<string, mixed> */
    private array $serverBackup;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../auth.php';
    }

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        get_db()->exec('DELETE FROM share_attempts');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        get_db()->exec('DELETE FROM share_attempts');
    }

    private function locked(string $ip): bool
    {
        return share_attempt(self::KEY, $ip) > AUTH_LOGIN_MAX_ATTEMPTS;
    }

    public function testFirstFiveAttemptsPassSixthIsLocked(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($this->locked('198.51.100.1'), "attempt $i");
        }
        $this->assertTrue($this->locked('198.51.100.1'), 'attempt 6');
        $this->assertTrue($this->locked('198.51.100.1'), 'attempt 7');
    }

    public function testSuccessClearsTheCounter(): void
    {
        for ($i = 0; $i < 5; $i++) share_attempt(self::KEY, '198.51.100.1');
        clear_share_attempts(self::KEY, '198.51.100.1');
        $this->assertSame(1, share_attempt(self::KEY, '198.51.100.1'));
    }

    public function testIpsAreIndependent(): void
    {
        for ($i = 0; $i < 6; $i++) share_attempt(self::KEY, '198.51.100.1');
        $this->assertFalse($this->locked('198.51.100.2'));
    }

    public function testLoginKeyDoesNotTouchShareTokens(): void
    {
        for ($i = 0; $i < 6; $i++) share_attempt(self::KEY, '198.51.100.1');
        $this->assertSame(1, share_attempt('login', '198.51.100.1'));
        clear_share_attempts(self::KEY, '198.51.100.1');
        $this->assertSame(2, share_attempt('login', '198.51.100.1'));
    }

    public function testExpiredWindowUnlocks(): void
    {
        for ($i = 0; $i < 6; $i++) share_attempt(self::KEY, '198.51.100.1');
        get_db()->prepare('UPDATE share_attempts SET first_ts = ? WHERE token = ?')
            ->execute([time() - AUTH_LOCKOUT_WINDOW - 1, self::KEY]);
        $this->assertFalse($this->locked('198.51.100.1'));
    }

    public function testClearOnUnknownIpIsIdempotent(): void
    {
        clear_share_attempts(self::KEY, '198.51.100.99');
        $this->assertSame(0, (int)get_db()->query('SELECT COUNT(*) FROM share_attempts')->fetchColumn());
    }

    public function testForwardedHeadersDoNotChangeTheKey(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
        $this->assertSame('198.51.100.7', rate_limit_ip());
    }

    public function testIpv6SharesTheSlash64Bucket(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1';
        $a = rate_limit_ip();
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:bbbb::2';
        $this->assertSame($a, rate_limit_ip());
    }

    /** Fail closed: a broken database raises, it never reports "not locked". */
    public function testDatabaseFailureRaises(): void
    {
        $db = get_db();
        $db->exec('ALTER TABLE share_attempts RENAME TO share_attempts_off');
        try {
            $this->expectException(PDOException::class);
            share_attempt(self::KEY, '198.51.100.1');
        } finally {
            $db->exec('ALTER TABLE share_attempts_off RENAME TO share_attempts');
        }
    }
}
