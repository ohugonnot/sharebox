<?php

use PHPUnit\Framework\TestCase;

class DirSizeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sharebox_dirsize_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
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

    public function testEmptyDirectory(): void
    {
        $this->assertSame(0, dir_size($this->tmpDir));
    }

    public function testSingleFile(): void
    {
        file_put_contents($this->tmpDir . '/file.txt', str_repeat('A', 100));
        $this->assertSame(100, dir_size($this->tmpDir));
    }

    public function testMultipleFiles(): void
    {
        file_put_contents($this->tmpDir . '/a.txt', str_repeat('X', 50));
        file_put_contents($this->tmpDir . '/b.txt', str_repeat('Y', 75));
        $this->assertSame(125, dir_size($this->tmpDir));
    }

    public function testNestedDirectories(): void
    {
        mkdir($this->tmpDir . '/sub1/sub2', 0755, true);
        file_put_contents($this->tmpDir . '/root.txt', str_repeat('A', 10));
        file_put_contents($this->tmpDir . '/sub1/mid.txt', str_repeat('B', 20));
        file_put_contents($this->tmpDir . '/sub1/sub2/deep.txt', str_repeat('C', 30));
        $this->assertSame(60, dir_size($this->tmpDir));
    }

    public function testEmptySubDirectories(): void
    {
        mkdir($this->tmpDir . '/empty1', 0755);
        mkdir($this->tmpDir . '/empty2', 0755);
        file_put_contents($this->tmpDir . '/file.bin', str_repeat('Z', 200));
        $this->assertSame(200, dir_size($this->tmpDir));
    }
}
