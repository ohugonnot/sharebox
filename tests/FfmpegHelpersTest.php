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

    public function testBuildFilterGraphHdrMode(): void
    {
        $result = buildFilterGraph(1080, 0, -1, 'hdr');
        // Downscale avant le pipeline float32 (width-based, 1080 → 1920)
        $this->assertStringContainsString('zscale=w=', $result);
        $this->assertStringContainsString('min(1920', $result);
        $this->assertStringContainsString('t=linear:npl=100:p=bt709', $result);
        $this->assertStringContainsString('format=gbrpf32le', $result);
        $this->assertStringContainsString('tonemap=mobius:desat=0', $result);
        $this->assertStringContainsString('zscale=t=bt709:m=bt709:r=tv', $result);
        $this->assertStringContainsString('format=yuv420p', $result);
        // Pas de scale lanczos séparé (intégré dans zscale)
        $this->assertStringNotContainsString('lanczos', $result);
    }

    public function testBuildFilterGraphHdrWidthMapping(): void
    {
        // Vérifie que quality (hauteur) → largeur 16:9 correcte
        $r720 = buildFilterGraph(720, 0, -1, 'hdr');
        $this->assertStringContainsString('min(1280', $r720);

        $r480 = buildFilterGraph(480, 0, -1, 'hdr');
        $this->assertStringContainsString('min(854', $r480);

        $r2160 = buildFilterGraph(2160, 0, -1, 'hdr');
        $this->assertStringContainsString('min(3840', $r2160);
    }

    public function testBuildFilterGraphHdrWithBurnSub(): void
    {
        $result = buildFilterGraph(1080, 0, 0, 'hdr');
        // Sous-titres PGS : scale2ref + overlay AVANT le pipeline HDR
        $this->assertStringContainsString('scale2ref', $result);
        $this->assertStringContainsString('overlay', $result);
        $this->assertStringContainsString('tonemap=mobius', $result);
    }

    /** @dataProvider filterModeProvider */
    public function testBuildFilterGraphAllModes(string $mode, string $mustContain, string $mustNotContain = ''): void
    {
        $result = buildFilterGraph(720, 0, -1, $mode);
        $this->assertStringContainsString($mustContain, $result);
        $this->assertStringContainsString('format=yuv420p', $result);
        $this->assertStringContainsString('[0:v:0]', $result);
        if ($mustNotContain) {
            $this->assertStringNotContainsString($mustNotContain, $result);
        }
    }

    public static function filterModeProvider(): array
    {
        return [
            'detail'      => ['detail',      'cas=0.5'],
            'night'       => ['night',       'eq=gamma=1.4'],
            'deinterlace' => ['deinterlace', 'bwdif=mode=send_frame'],
            'anime'       => ['anime',       'deband='],
            'none'        => ['none',        'lanczos', 'tonemap'],
        ];
    }

    // ── buildFfmpegInputArgs ────────────────────────────────────────────

    public function testBuildFfmpegInputArgsBasic(): void
    {
        $result = buildFfmpegInputArgs('/path/to/video.mkv');
        $this->assertStringContainsString('ffmpeg', $result);
        $this->assertStringContainsString('timeout 14400', $result);
        $this->assertStringContainsString('ionice', $result);
        $this->assertStringContainsString('nice -n 5', $result);
        $this->assertStringContainsString('-nostdin', $result);
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
        $this->assertStringContainsString('-tune zerolatency', $result);
        $this->assertStringContainsString('-profile:v main -level 4.1', $result);
        $this->assertStringContainsString('-crf 23', $result);
        $this->assertStringContainsString('-g 25', $result);
        $this->assertStringContainsString('-threads 10', $result);
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
        $this->assertStringContainsString('-max_muxing_queue_size 4096', $result);
        $this->assertStringContainsString('-min_frag_duration 300000', $result);
        $this->assertStringContainsString('-movflags frag_keyframe+empty_moov+default_base_moof', $result);
    }

    // ── ALLOWED_QUALITIES constant ──────────────────────────────────────

    public function testAllowedQualitiesConstant(): void
    {
        $this->assertSame([480, 576, 720, 1080], ALLOWED_QUALITIES);
    }
}
