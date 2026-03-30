<?php

use PHPUnit\Framework\TestCase;

class PurgeExpiredTest extends TestCase
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
        self::$db->query("DELETE FROM links");
    }

    public function testPurgesExpiredOnly(): void
    {
        // Lien expiré
        self::$db->prepare("INSERT INTO links (token, path, type, name, expires_at) VALUES (?,?,?,?,?)")
            ->execute(['tok-exp', '/tmp/a', 'file', 'a.mkv', '2020-01-01 00:00:00']);
        // Lien actif (expire demain)
        self::$db->prepare("INSERT INTO links (token, path, type, name, expires_at) VALUES (?,?,?,?,?)")
            ->execute(['tok-active', '/tmp/b', 'file', 'b.mkv', date('Y-m-d H:i:s', time() + 86400)]);
        // Lien permanent (pas d'expiration)
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-perm', '/tmp/c', 'file', 'c.mkv']);

        $deleted = purge_expired_links(self::$db);

        $this->assertSame(1, $deleted);
        $remaining = (int)self::$db->query("SELECT COUNT(*) FROM links")->fetchColumn();
        $this->assertSame(2, $remaining);
        $gone = (int)self::$db->query("SELECT COUNT(*) FROM links WHERE token = 'tok-exp'")->fetchColumn();
        $this->assertSame(0, $gone);
    }

    public function testReturnsZeroWhenNothingToDelete(): void
    {
        $deleted = purge_expired_links(self::$db);
        $this->assertSame(0, $deleted);
    }

    public function testDoesNotDeletePermanentLinks(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-perm2', '/tmp/d', 'file', 'd.mkv']);
        $deleted = purge_expired_links(self::$db);
        $this->assertSame(0, $deleted);
        $count = (int)self::$db->query("SELECT COUNT(*) FROM links")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
