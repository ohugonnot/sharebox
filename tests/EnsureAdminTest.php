<?php

use PHPUnit\Framework\TestCase;

class EnsureAdminTest extends TestCase
{
    private static \PDO $db;
    private array $savedUsers = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../db.php';
        require_once __DIR__ . '/../functions.php';
        require_once __DIR__ . '/../auth.php';
        self::$db = get_db();
    }

    protected function setUp(): void
    {
        $this->savedUsers = self::$db->query('SELECT * FROM users')->fetchAll();
        self::$db->exec('DELETE FROM users');
        putenv('SHAREBOX_ADMIN_PASS');
        putenv('SHAREBOX_ADMIN_USER');
    }

    protected function tearDown(): void
    {
        putenv('SHAREBOX_ADMIN_PASS');
        putenv('SHAREBOX_ADMIN_USER');
        self::$db->exec('DELETE FROM users');
        $ins = self::$db->prepare('INSERT INTO users (id, username, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($this->savedUsers as $u) {
            $ins->execute([$u['id'], $u['username'], $u['password_hash'], $u['role'], $u['created_at']]);
        }
    }

    public function testEmptyDbWithoutPasswordGeneratesOne(): void
    {
        $pass = @ensure_admin_exists();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $pass);
        $rows = self::$db->query('SELECT username, password_hash, role FROM users')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('admin', $rows[0]['username']);
        $this->assertSame('admin', $rows[0]['role']);
        $this->assertTrue(password_verify($pass, $rows[0]['password_hash']));
    }

    public function testEmptyDbWithProvidedPasswordReturnsNull(): void
    {
        putenv('SHAREBOX_ADMIN_USER=boss');
        putenv('SHAREBOX_ADMIN_PASS=given-pass-1');

        $this->assertNull(ensure_admin_exists());
        $rows = self::$db->query('SELECT username, password_hash FROM users')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('boss', $rows[0]['username']);
        $this->assertTrue(password_verify('given-pass-1', $rows[0]['password_hash']));
    }

    public function testNonEmptyDbIsLeftUntouched(): void
    {
        self::$db->prepare("INSERT INTO users (username, password_hash, role) VALUES ('existing', 'hash', 'user')")->execute();
        putenv('SHAREBOX_ADMIN_PASS=ignored-pass-1');

        $this->assertNull(ensure_admin_exists());
        $rows = self::$db->query('SELECT username, password_hash, role FROM users')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(['username' => 'existing', 'password_hash' => 'hash', 'role' => 'user'], $rows[0]);
    }
}
