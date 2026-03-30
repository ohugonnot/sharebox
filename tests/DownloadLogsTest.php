<?php

use PHPUnit\Framework\TestCase;

class DownloadLogsTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM download_logs");
        self::$db->query("DELETE FROM links");
    }

    public function testTableExists(): void
    {
        $tables = self::$db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='download_logs'"
        )->fetchAll();
        $this->assertCount(1, $tables);
    }

    public function testInsertLog(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-log1', '/tmp/a', 'file', 'a.mkv']);
        $linkId = (int)self::$db->lastInsertId();

        self::$db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
            ->execute([$linkId, '1.2.3.4']);

        $count = (int)self::$db->query("SELECT COUNT(*) FROM download_logs")->fetchColumn();
        $this->assertSame(1, $count);

        $log = self::$db->query("SELECT * FROM download_logs")->fetch();
        $this->assertSame($linkId, (int)$log['link_id']);
        $this->assertSame('1.2.3.4', $log['ip']);
        $this->assertNotEmpty($log['downloaded_at']);
    }

    public function testCleanupRemovesOldLogs(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-log2', '/tmp/b', 'file', 'b.mkv']);
        $linkId = (int)self::$db->lastInsertId();

        // Log vieux de 31 jours
        $old = date('Y-m-d H:i:s', time() - 31 * 86400);
        self::$db->prepare("INSERT INTO download_logs (link_id, ip, downloaded_at) VALUES (?, ?, ?)")
            ->execute([$linkId, '1.2.3.4', $old]);
        // Log récent
        self::$db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
            ->execute([$linkId, '5.6.7.8']);

        self::$db->exec("DELETE FROM download_logs WHERE downloaded_at < datetime('now', '-30 days')");

        $count = (int)self::$db->query("SELECT COUNT(*) FROM download_logs")->fetchColumn();
        $this->assertSame(1, $count);

        $remaining = self::$db->query("SELECT ip FROM download_logs")->fetch();
        $this->assertSame('5.6.7.8', $remaining['ip']);
    }

    public function testRecentActivityQuery(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name, created_by) VALUES (?,?,?,?,?)")
            ->execute(['tok-log3', '/tmp/c', 'file', 'c.mkv', 'alice']);
        $linkId = (int)self::$db->lastInsertId();

        self::$db->prepare("INSERT INTO download_logs (link_id, ip) VALUES (?, ?)")
            ->execute([$linkId, '9.9.9.9']);

        $rows = self::$db->query("
            SELECT dl.ip, l.name, l.token, l.created_by
            FROM download_logs dl
            JOIN links l ON dl.link_id = l.id
            ORDER BY dl.downloaded_at DESC
            LIMIT 50
        ")->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertSame('9.9.9.9', $rows[0]['ip']);
        $this->assertSame('alice', $rows[0]['created_by']);
    }
}
