<?php

use PHPUnit\Framework\TestCase;

class ChangePasswordTest extends TestCase
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
        self::$db->query("DELETE FROM users WHERE username = 'testpwd'");
        self::$db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)")
            ->execute(['testpwd', password_hash('oldpass', PASSWORD_BCRYPT), 'user']);
    }

    public function testSuccessfulChange(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'oldpass', 'newpass1', 'newpass1');
        $this->assertArrayHasKey('ok', $result);
        $row = self::$db->query("SELECT password_hash FROM users WHERE username = 'testpwd'")->fetch();
        $this->assertTrue(password_verify('newpass1', $row['password_hash']));
    }

    public function testWrongCurrentPassword(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'wrongpass', 'newpass1', 'newpass1');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsStringIgnoringCase('actuel', $result['error']);
    }

    public function testConfirmationMismatch(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'oldpass', 'newpass1', 'different');
        $this->assertArrayHasKey('error', $result);
    }

    public function testTooShortNewPassword(): void
    {
        $result = change_password_for_user(self::$db, 'testpwd', 'oldpass', 'ab', 'ab');
        $this->assertArrayHasKey('error', $result);
    }

    public function testUnknownUserReturnsError(): void
    {
        $result = change_password_for_user(self::$db, 'nobody', 'pass', 'newpass1', 'newpass1');
        $this->assertArrayHasKey('error', $result);
    }
}
