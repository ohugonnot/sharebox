<?php

use PHPUnit\Framework\TestCase;

class MaxDownloadsTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        self::$db->query("DELETE FROM links");
    }

    public function testColumnExists(): void
    {
        $cols = array_column(self::$db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        $this->assertContains('max_downloads', $cols);
    }

    public function testDefaultIsNull(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name) VALUES (?,?,?,?)")
            ->execute(['tok-unlim', '/tmp/a', 'file', 'a.mkv']);
        $link = self::$db->query("SELECT max_downloads FROM links WHERE token = 'tok-unlim'")->fetch();
        $this->assertNull($link['max_downloads']);
    }

    public function testLinkNotYetExhausted(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name, max_downloads, download_count) VALUES (?,?,?,?,?,?)")
            ->execute(['tok-max', '/tmp/b', 'file', 'b.mkv', 3, 2]);
        $link = self::$db->query("SELECT * FROM links WHERE token = 'tok-max'")->fetch();
        $this->assertFalse((int)$link['download_count'] >= (int)$link['max_downloads']);
    }

    public function testLinkExhausted(): void
    {
        self::$db->prepare("INSERT INTO links (token, path, type, name, max_downloads, download_count) VALUES (?,?,?,?,?,?)")
            ->execute(['tok-done', '/tmp/c', 'file', 'c.mkv', 2, 2]);
        $link = self::$db->query("SELECT * FROM links WHERE token = 'tok-done'")->fetch();
        $this->assertTrue((int)$link['download_count'] >= (int)$link['max_downloads']);
    }
}
