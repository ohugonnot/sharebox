<?php

use PHPUnit\Framework\TestCase;

class DownloadTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sharebox_dl_test_' . uniqid();
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

    // ── validateQuality ──────────────────────────────────────────────────────

    /**
     * @dataProvider validQualityProvider
     */
    public function testValidateQualityAccepted(int $input, int $expected): void
    {
        $this->assertSame($expected, validateQuality($input));
    }

    public static function validQualityProvider(): array
    {
        return [
            '480p'  => [480, 480],
            '576p'  => [576, 576],
            '720p'  => [720, 720],
            '1080p' => [1080, 1080],
        ];
    }

    /**
     * @dataProvider invalidQualityProvider
     */
    public function testValidateQualityFallback(int $input): void
    {
        $this->assertSame(720, validateQuality($input));
    }

    public static function invalidQualityProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-1],
            '360p'     => [360],
            '4K'       => [2160],
            'random'   => [999],
        ];
    }

    // ── buildFilterGraph ─────────────────────────────────────────────────────

    public function testBuildFilterGraphTranscodeOnly(): void
    {
        $result = buildFilterGraph(720, 0);
        $this->assertStringContainsString('scale=-2', $result);
        $this->assertStringContainsString('720', $result);
        $this->assertStringContainsString('[0:a:0]', $result);
        $this->assertStringNotContainsString('overlay', $result);
    }

    public function testBuildFilterGraphWithBurnSub(): void
    {
        $result = buildFilterGraph(1080, 1, 2);
        $this->assertStringContainsString('scale2ref', $result);
        $this->assertStringContainsString('overlay', $result);
        $this->assertStringContainsString('[0:s:2]', $result);
        $this->assertStringContainsString('[0:a:1]', $result);
        $this->assertStringContainsString('1080', $result);
    }

    // ── buildFfmpegInputArgs ─────────────────────────────────────────────────

    public function testBuildFfmpegInputArgsNoSeek(): void
    {
        $result = buildFfmpegInputArgs('/path/to/file.mkv');
        $this->assertStringContainsString('ffmpeg', $result);
        $this->assertStringContainsString('-thread_queue_size 512', $result);
        $this->assertStringContainsString("'/path/to/file.mkv'", $result);
    }

    public function testBuildFfmpegInputArgsWithSeek(): void
    {
        $result = buildFfmpegInputArgs('/path/to/file.mkv', ' -ss 120');
        $this->assertStringContainsString('-ss 120', $result);
        // -ss must appear before -i (coarse seek)
        $ssPos = strpos($result, '-ss 120');
        $iPos  = strpos($result, '-i ');
        $this->assertLessThan($iPos, $ssPos, '-ss must appear before -i for coarse seek');
    }

    // ── buildFmp4MuxerArgs ───────────────────────────────────────────────────

    public function testBuildFmp4MuxerArgs(): void
    {
        $result = buildFmp4MuxerArgs();
        $this->assertStringContainsString('-movflags frag_keyframe+empty_moov+default_base_moof', $result);
        $this->assertStringContainsString('-min_frag_duration 2000000', $result);
        $this->assertStringContainsString('-avoid_negative_ts make_zero', $result);
    }

    // ── stream_log rotation ──────────────────────────────────────────────────

    /**
     * Use a fixed path for STREAM_LOG since it can only be defined once per process.
     */
    private static string $logFile = '/tmp/sharebox_test_stream.log';

    private function ensureStreamLog(): string
    {
        if (!defined('STREAM_LOG')) {
            define('STREAM_LOG', self::$logFile);
        }
        $path = STREAM_LOG;
        // Skip if STREAM_LOG points to prod (defined by config.php loaded by another test)
        if ($path !== self::$logFile) {
            $this->markTestSkipped('STREAM_LOG already defined as ' . $path . ' (loaded by another test)');
        }
        return $path;
    }

    public function testStreamLogRotatesAt5MB(): void
    {
        $logFile = $this->ensureStreamLog();

        // Back up original log if it exists
        $hadOriginal = file_exists($logFile);
        $originalContent = $hadOriginal ? file_get_contents($logFile) : null;

        // Clean up from previous runs
        foreach (['.1', '.2', '.3', ''] as $suffix) {
            @unlink($logFile . $suffix);
        }

        try {
            // Create a log file just over 5 MB
            file_put_contents($logFile, str_repeat('x', 5 * 1024 * 1024 + 1));
            $this->assertGreaterThan(5 * 1024 * 1024, filesize($logFile));

            // Call stream_log — should trigger rotation
            stream_log('test rotation');

            // Old file should now be .1
            $this->assertFileExists($logFile . '.1', 'Original log should be rotated to .1');
            // New log should exist with the test message
            $this->assertFileExists($logFile, 'A new log file should be created after rotation');
            $content = file_get_contents($logFile);
            $this->assertStringContainsString('test rotation', $content);
        } finally {
            // Clean up test artifacts
            foreach (['.1', '.2', '.3', ''] as $suffix) {
                @unlink($logFile . $suffix);
            }
            // Restore original if it existed
            if ($hadOriginal && $originalContent !== null) {
                file_put_contents($logFile, $originalContent);
            }
        }
    }

    public function testStreamLogNoRotateUnder5MB(): void
    {
        $logFile = $this->ensureStreamLog();

        // Back up original log if it exists
        $hadOriginal = file_exists($logFile);
        $originalContent = $hadOriginal ? file_get_contents($logFile) : null;

        // Clean up
        foreach (['.1', '.2', '.3', ''] as $suffix) {
            @unlink($logFile . $suffix);
        }

        try {
            file_put_contents($logFile, str_repeat('x', 1024));
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            stream_log('small file test');

            $this->assertFileDoesNotExist($logFile . '.1', 'Should not rotate a small log file');
            clearstatcache(true, $logFile);
            $content = file_get_contents($logFile);
            $this->assertStringContainsString('small file test', $content);
        } finally {
            foreach (['.1', '.2', '.3', ''] as $suffix) {
                @unlink($logFile . $suffix);
            }
            if ($hadOriginal && $originalContent !== null) {
                file_put_contents($logFile, $originalContent);
            }
        }
    }

    // ── computeEpisodeNav ────────────────────────────────────────────────────

    public function testEpisodeNavMiddleEpisode(): void
    {
        // Create 3 video files in tmpDir
        touch($this->tmpDir . '/episode01.mkv');
        touch($this->tmpDir . '/episode02.mkv');
        touch($this->tmpDir . '/episode03.mkv');

        $nav = computeEpisodeNav('episode02.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNotNull($nav['prev'], 'Middle episode should have a prev');
        $this->assertNotNull($nav['next'], 'Middle episode should have a next');
        $this->assertSame('episode01.mkv', $nav['prev']['name']);
        $this->assertSame('episode03.mkv', $nav['next']['name']);
    }

    public function testEpisodeNavFirstEpisode(): void
    {
        touch($this->tmpDir . '/episode01.mkv');
        touch($this->tmpDir . '/episode02.mkv');
        touch($this->tmpDir . '/episode03.mkv');

        $nav = computeEpisodeNav('episode01.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNull($nav['prev'], 'First episode should have no prev');
        $this->assertNotNull($nav['next']);
        $this->assertSame('episode02.mkv', $nav['next']['name']);
    }

    public function testEpisodeNavLastEpisode(): void
    {
        touch($this->tmpDir . '/episode01.mkv');
        touch($this->tmpDir . '/episode02.mkv');
        touch($this->tmpDir . '/episode03.mkv');

        $nav = computeEpisodeNav('episode03.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNotNull($nav['prev']);
        $this->assertSame('episode02.mkv', $nav['prev']['name']);
        $this->assertNull($nav['next'], 'Last episode should have no next');
    }

    public function testEpisodeNavSingleFile(): void
    {
        touch($this->tmpDir . '/movie.mkv');

        $nav = computeEpisodeNav('movie.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNull($nav['prev'], 'Single file should have no prev');
        $this->assertNull($nav['next'], 'Single file should have no next');
    }

    public function testEpisodeNavIgnoresNonVideoFiles(): void
    {
        touch($this->tmpDir . '/episode01.mkv');
        touch($this->tmpDir . '/episode02.mkv');
        touch($this->tmpDir . '/notes.txt');
        touch($this->tmpDir . '/cover.jpg');
        touch($this->tmpDir . '/subs.srt');

        $nav = computeEpisodeNav('episode01.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNull($nav['prev'], 'First of 2 videos should have no prev');
        $this->assertNotNull($nav['next']);
        $this->assertSame('episode02.mkv', $nav['next']['name']);
    }

    public function testEpisodeNavNaturalSort(): void
    {
        // Natural sort: ep2 before ep10
        touch($this->tmpDir . '/ep2.mkv');
        touch($this->tmpDir . '/ep10.mkv');
        touch($this->tmpDir . '/ep1.mkv');

        $nav = computeEpisodeNav('ep2.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNotNull($nav['prev']);
        $this->assertSame('ep1.mkv', $nav['prev']['name']);
        $this->assertNotNull($nav['next']);
        $this->assertSame('ep10.mkv', $nav['next']['name']);
    }

    public function testEpisodeNavSubdirectory(): void
    {
        // Episodes inside a subdirectory
        mkdir($this->tmpDir . '/Season1', 0755);
        touch($this->tmpDir . '/Season1/s01e01.mkv');
        touch($this->tmpDir . '/Season1/s01e02.mkv');
        touch($this->tmpDir . '/Season1/s01e03.mkv');

        $nav = computeEpisodeNav('Season1/s01e02.mkv', $this->tmpDir, '/dl/test-token');

        $this->assertNotNull($nav['prev']);
        $this->assertSame('s01e01.mkv', $nav['prev']['name']);
        $this->assertStringContainsString('Season1', $nav['prev']['url']);
        $this->assertNotNull($nav['next']);
        $this->assertSame('s01e03.mkv', $nav['next']['name']);
        $this->assertStringContainsString('Season1', $nav['next']['url']);
    }

    public function testEpisodeNavUrlFormat(): void
    {
        touch($this->tmpDir . '/ep1.mkv');
        touch($this->tmpDir . '/ep2.mkv');

        $nav = computeEpisodeNav('ep1.mkv', $this->tmpDir, '/dl/my-token');

        $this->assertNotNull($nav['next']);
        $this->assertStringContainsString('/dl/my-token', $nav['next']['url']);
        $this->assertStringContainsString('play=1', $nav['next']['url']);
        $this->assertStringContainsString('p=', $nav['next']['pp']);
    }

    public function testEpisodeNavNonexistentDirectory(): void
    {
        $nav = computeEpisodeNav('nodir/file.mkv', $this->tmpDir, '/dl/test');

        $this->assertNull($nav['prev']);
        $this->assertNull($nav['next']);
    }

    // ── buildFfmpegCodecArgs ─────────────────────────────────────────────────

    public function testBuildFfmpegCodecArgs(): void
    {
        $result = buildFfmpegCodecArgs();
        $this->assertStringContainsString('-c:v libx264', $result);
        $this->assertStringContainsString('-preset medium', $result);
        $this->assertStringContainsString('-tune film', $result);
        $this->assertStringContainsString('-crf 20', $result);
        $this->assertStringContainsString('-threads 12', $result);
        $this->assertStringContainsString('-bf 3', $result);
        $this->assertStringContainsString('-refs 4', $result);
        $this->assertStringContainsString('-c:a aac', $result);
        $this->assertStringContainsString('-ac 2', $result);
        $this->assertStringContainsString('-b:a 192k', $result);
    }

    public function testBuildFfmpegCodecArgsCustomGop(): void
    {
        $result = buildFfmpegCodecArgs(50);
        $this->assertStringContainsString('-g 50', $result);
    }

    // ── validateBurnSub ─────────────────────────────────────────────────────

    public function testValidateBurnSubDisabled(): void
    {
        $this->assertSame(-1, validateBurnSub(-1));
    }

    public function testValidateBurnSubValidRange(): void
    {
        $this->assertSame(0, validateBurnSub(0));
        $this->assertSame(5, validateBurnSub(5));
        $this->assertSame(49, validateBurnSub(49));
    }

    public function testValidateBurnSubRejectsOutOfRange(): void
    {
        $this->assertSame(-1, validateBurnSub(50));
        $this->assertSame(-1, validateBurnSub(99999));
        $this->assertSame(-1, validateBurnSub(-100));
    }
}
