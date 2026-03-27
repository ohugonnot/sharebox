<?php

use PHPUnit\Framework\TestCase;

class FfmpegHelpersTest extends TestCase
{
    // ── validateQuality ─────────────────────────────────────────────────

    /**
     * @dataProvider validQualityProvider
     */
    public function testValidateQualityAcceptsAllowed(int $input): void
    {
        $this->assertSame($input, validateQuality($input));
    }

    public static function validQualityProvider(): array
    {
        return [
            '480p'  => [480],
            '576p'  => [576],
            '720p'  => [720],
            '1080p' => [1080],
        ];
    }

    /**
     * @dataProvider invalidQualityProvider
     */
    public function testValidateQualityFallsBackTo720(int $input): void
    {
        $this->assertSame(720, validateQuality($input));
    }

    public static function invalidQualityProvider(): array
    {
        return [
            'zero'      => [0],
            'negative'  => [-1],
            '360p'      => [360],
            '1440p'     => [1440],
            '2160p'     => [2160],
            'arbitrary' => [999],
        ];
    }

    // ── buildFilterGraph ────────────────────────────────────────────────

    public function testBuildFilterGraphWithoutBurnSub(): void
    {
        $result = buildFilterGraph(720, 0);
        $this->assertStringContainsString('scale=-2', $result);
        $this->assertStringContainsString("min(720,ih)", $result);
        $this->assertStringContainsString('[0:v:0]', $result);
        $this->assertStringContainsString('[0:a:0]', $result);
        $this->assertStringContainsString('aresample=async=3000', $result);
        $this->assertStringNotContainsString('overlay', $result);
        $this->assertStringNotContainsString('scale2ref', $result);
    }

    public function testBuildFilterGraphWithBurnSub(): void
    {
        $result = buildFilterGraph(1080, 1, 2);
        $this->assertStringContainsString('[0:s:2]', $result);
        $this->assertStringContainsString('scale2ref', $result);
        $this->assertStringContainsString('overlay=eof_action=pass', $result);
        $this->assertStringContainsString("min(1080,ih)", $result);
        $this->assertStringContainsString('[0:a:1]', $result);
        $this->assertStringContainsString('aresample=async=3000', $result);
    }

    public function testBuildFilterGraphDifferentAudioTrack(): void
    {
        $result = buildFilterGraph(480, 3);
        $this->assertStringContainsString('[0:a:3]', $result);
        $this->assertStringContainsString("min(480,ih)", $result);
    }

    public function testBuildFilterGraphBurnSubZero(): void
    {
        // burnSub=0 should activate the burn-in path
        $result = buildFilterGraph(720, 0, 0);
        $this->assertStringContainsString('[0:s:0]', $result);
        $this->assertStringContainsString('scale2ref', $result);
        $this->assertStringContainsString('overlay', $result);
    }

    public function testBuildFilterGraphNegativeBurnSubDisablesBurnIn(): void
    {
        // burnSub=-1 (default) should NOT activate burn-in
        $result = buildFilterGraph(720, 0, -1);
        $this->assertStringNotContainsString('scale2ref', $result);
        $this->assertStringNotContainsString('overlay', $result);
    }

    // ── buildFfmpegInputArgs ────────────────────────────────────────────

    public function testBuildFfmpegInputArgsBasic(): void
    {
        $result = buildFfmpegInputArgs('/path/to/video.mkv');
        $this->assertStringStartsWith('ffmpeg', $result);
        $this->assertStringContainsString('-thread_queue_size 512', $result);
        $this->assertStringContainsString('-fflags +genpts+discardcorrupt', $result);
        $this->assertStringContainsString("-i '/path/to/video.mkv'", $result);
    }

    public function testBuildFfmpegInputArgsWithSeek(): void
    {
        $result = buildFfmpegInputArgs('/movie.mp4', ' -ss 120');
        $this->assertStringContainsString('-ss 120', $result);
        // seek must come BEFORE -i (coarse seek)
        $ssPos = strpos($result, '-ss 120');
        $iPos  = strpos($result, '-i ');
        $this->assertLessThan($iPos, $ssPos, '-ss must appear before -i for coarse seek');
    }

    public function testBuildFfmpegInputArgsEscapesPath(): void
    {
        $result = buildFfmpegInputArgs("/path/to/file with spaces & 'quotes'.mkv");
        // escapeshellarg wraps in single quotes and escapes
        $this->assertStringContainsString("-i '", $result);
    }

    // ── buildFfmpegCodecArgs ────────────────────────────────────────────

    public function testBuildFfmpegCodecArgsDefaults(): void
    {
        $result = buildFfmpegCodecArgs();
        $this->assertStringContainsString('-c:v libx264', $result);
        $this->assertStringContainsString('-preset ultrafast', $result);
        $this->assertStringContainsString('-crf 23', $result);
        $this->assertStringContainsString('-g 25', $result);
        $this->assertStringContainsString('-threads 4', $result);
        $this->assertStringContainsString('-c:a aac', $result);
        $this->assertStringContainsString('-ac 2', $result);
        $this->assertStringContainsString('-b:a 192k', $result);
        $this->assertStringContainsString('-shortest', $result);
    }

    public function testBuildFfmpegCodecArgsCustomGop(): void
    {
        $result = buildFfmpegCodecArgs(50);
        $this->assertStringContainsString('-g 50', $result);
        $this->assertStringNotContainsString('-g 25', $result);
    }

    // ── buildFmp4MuxerArgs ──────────────────────────────────────────────

    public function testBuildFmp4MuxerArgs(): void
    {
        $result = buildFmp4MuxerArgs();
        $this->assertStringContainsString('-avoid_negative_ts make_zero', $result);
        $this->assertStringContainsString('-start_at_zero', $result);
        $this->assertStringContainsString('-max_muxing_queue_size 1024', $result);
        $this->assertStringContainsString('-min_frag_duration 300000', $result);
        $this->assertStringContainsString('-movflags frag_keyframe+empty_moov+default_base_moof', $result);
    }

    // ── ALLOWED_QUALITIES constant ──────────────────────────────────────

    public function testAllowedQualitiesConstant(): void
    {
        $this->assertSame([480, 576, 720, 1080], ALLOWED_QUALITIES);
    }
}
