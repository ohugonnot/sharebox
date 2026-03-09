<?php

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    private const TOKEN_REGEX = '/^[a-z0-9][a-z0-9-]{1,50}$/';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sharebox_test_' . uniqid();
        mkdir($this->tmpDir . '/sub', 0755, true);
        file_put_contents($this->tmpDir . '/sub/file.txt', 'test');
    }

    protected function tearDown(): void
    {
        // Cleanup symlinks before rmdir
        foreach (glob($this->tmpDir . '/*') as $item) {
            if (is_link($item)) {
                unlink($item);
            }
        }
        // Recursive delete
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // --- Token regex tests ---

    /**
     * @dataProvider validTokenProvider
     */
    public function testValidTokens(string $token): void
    {
        $this->assertMatchesRegularExpression(self::TOKEN_REGEX, $token);
    }

    public static function validTokenProvider(): array
    {
        return [
            'minimal' => ['ab'],
            'slug classique' => ['batman-2005-x7k2'],
        ];
    }

    /**
     * @dataProvider invalidTokenProvider
     */
    public function testInvalidTokens(string $token): void
    {
        $this->assertDoesNotMatchRegularExpression(self::TOKEN_REGEX, $token);
    }

    public static function invalidTokenProvider(): array
    {
        return [
            'vide' => [''],
            'un seul char' => ['a'],
            'commence par tiret' => ['-dash'],
            'majuscules' => ['UPPER'],
            'espace' => ['has space'],
            'traversal' => ['a../../etc'],
            'trop long' => [str_repeat('a', 52)],
        ];
    }

    // --- Path traversal tests (via is_path_within) ---

    public function testIsPathWithinBaseSeul(): void
    {
        $this->assertTrue(is_path_within(realpath($this->tmpDir), $this->tmpDir));
    }

    public function testIsPathWithinSousFichier(): void
    {
        $resolved = realpath($this->tmpDir . '/sub/file.txt');
        $this->assertTrue(is_path_within($resolved, $this->tmpDir));
    }

    public function testIsPathWithinTraversalBloque(): void
    {
        $resolved = realpath($this->tmpDir . '/../../etc/passwd');
        $this->assertFalse(is_path_within($resolved, $this->tmpDir));
    }

    public function testIsPathWithinInexistant(): void
    {
        // realpath returns false for non-existent paths
        $this->assertFalse(is_path_within(false, $this->tmpDir));
    }

    public function testIsPathWithinSymlinkExterieur(): void
    {
        $link = $this->tmpDir . '/outside';
        symlink('/etc', $link);
        $resolved = realpath($this->tmpDir . '/outside/passwd');
        $this->assertFalse(is_path_within($resolved, $this->tmpDir));
    }

    public function testIsPathWithinTrailingSlash(): void
    {
        $this->assertTrue(is_path_within(realpath($this->tmpDir), $this->tmpDir . '/'));
    }
}
