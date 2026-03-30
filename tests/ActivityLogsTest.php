<?php

use PHPUnit\Framework\TestCase;

class ActivityLogsTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        require_once __DIR__ . '/../functions.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM activity_logs");
    }

    public function testTableExists(): void
    {
        $tables = self::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='activity_logs'"
        )->fetchAll();
        $this->assertCount(1, $tables);
    }

    public function testLogActivityInsertsRow(): void
    {
        log_activity('login_ok', 'alice', '1.2.3.4', null);

        $row = self::$db->query("SELECT * FROM activity_logs")->fetch();
        $this->assertSame('login_ok', $row['event_type']);
        $this->assertSame('alice', $row['username']);
        $this->assertSame('1.2.3.4', $row['ip']);
        $this->assertNull($row['details']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testLogActivityWithDetails(): void
    {
        log_activity('link_create', 'bob', '5.6.7.8', 'film.mkv [abc123]');

        $row = self::$db->query("SELECT * FROM activity_logs")->fetch();
        $this->assertSame('link_create', $row['event_type']);
        $this->assertSame('film.mkv [abc123]', $row['details']);
    }

    public function testLogActivitySilentOnError(): void
    {
        // Doit ne pas lever d'exception même avec des valeurs nulles
        log_activity('login_fail', null, null, null);
        $count = (int)self::$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCleanupRemovesOldEntries(): void
    {
        $old = date('Y-m-d H:i:s', time() - 91 * 86400);
        self::$db->prepare("INSERT INTO activity_logs (event_type, username, ip, created_at) VALUES (?,?,?,?)")
            ->execute(['login_ok', 'alice', '1.2.3.4', $old]);
        self::$db->prepare("INSERT INTO activity_logs (event_type, username, ip) VALUES (?,?,?)")
            ->execute(['login_ok', 'bob', '9.9.9.9']);

        // Simuler le nettoyage
        self::$db->exec("DELETE FROM activity_logs WHERE created_at < datetime('now', '-90 days')");

        $count = (int)self::$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = self::$db->query("SELECT username FROM activity_logs")->fetch();
        $this->assertSame('bob', $remaining['username']);
    }
}
