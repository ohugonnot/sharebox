<?php

use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sharebox_priv_');
        if (!defined('DB_PATH'))               define('DB_PATH', $tmp);
        if (!defined('BASE_PATH'))             define('BASE_PATH', '/tmp/privtest/');
        if (!defined('XACCEL_PREFIX'))         define('XACCEL_PREFIX', '/internal');
        if (!defined('DL_BASE_URL'))           define('DL_BASE_URL', '/dl/');
        if (!defined('STREAM_MAX_CONCURRENT')) define('STREAM_MAX_CONCURRENT', 4);
        if (!defined('STREAM_REMUX_ENABLED'))  define('STREAM_REMUX_ENABLED', false);
        if (!defined('STREAM_LOG'))            define('STREAM_LOG', false);
        if (!defined('BANDWIDTH_QUOTA_TB'))    define('BANDWIDTH_QUOTA_TB', 100);
        require_once __DIR__ . '/../db.php';
        self::$db = get_db();
    }

    public static function tearDownAfterClass(): void
    {
        $path = DB_PATH;
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
            if (file_exists($f)) @unlink($f);
        }
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM links");
        self::$db->query("DELETE FROM users WHERE username IN ('alice','bob','carol')");

        // alice: non-private, bob: private, carol: admin
        self::$db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?,?,?,?)")
            ->execute(['alice', 'x', 'user', 0]);
        self::$db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?,?,?,?)")
            ->execute(['bob', 'x', 'user', 1]);
        self::$db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?,?,?,?)")
            ->execute(['carol', 'x', 'admin', 0]);

        // alice creates link A, bob creates link B, legacy link (no owner)
        self::$db->prepare("INSERT INTO links (token, path, type, name, created_by) VALUES (?,?,?,?,?)")
            ->execute(['tok-alice', '/tmp/a', 'file', 'a.mkv', 'alice']);
        self::$db->prepare("INSERT INTO links (token, path, type, name, created_by) VALUES (?,?,?,?,?)")
            ->execute(['tok-bob', '/tmp/b', 'file', 'b.mkv', 'bob']);
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-legacy', '/tmp/c', 'file', 'c.mkv']);
    }

    /** Admin sees all 3 links */
    public function testAdminSeesAllLinks(): void
    {
        $links = self::$db->query("SELECT token FROM links ORDER BY token")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(3, $links);
    }

    /** Non-private user sees public links (alice's + legacy, NOT bob's) */
    public function testNonPrivateUserSeesPublicLinks(): void
    {
        $stmt = self::$db->prepare("
            SELECT l.token FROM links l
            LEFT JOIN users u ON l.created_by = u.username
            WHERE l.created_by IS NULL OR u.private = 0
            ORDER BY l.token
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('tok-alice', $tokens);
        $this->assertContains('tok-legacy', $tokens);
        $this->assertNotContains('tok-bob', $tokens);
    }

    /** Private user sees only their own links */
    public function testPrivateUserSeesOnlyOwnLinks(): void
    {
        $stmt = self::$db->prepare("SELECT token FROM links WHERE created_by = ? ORDER BY token");
        $stmt->execute(['bob']);
        $tokens = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['tok-bob'], $tokens);
    }
}
