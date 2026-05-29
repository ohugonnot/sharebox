<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests d'EXÉCUTION des constructeurs d'arguments ffmpeg (functions.php).
 * On APPELLE les fonctions et on vérifie les args produits — contrairement aux
 * anciens tests qui grepaient le code source. Couvre la logique critique de
 * sélection software/GPU, HDR, HLS et l'échappement shell.
 */
class FfmpegBuildersTest extends TestCase
{
    // ── buildFilterGraph ────────────────────────────────────────────────

    public function testFilterGraphDefaultScalesAndMapsAudio(): void
    {
        $f = buildFilterGraph(720, 0);
        $this->assertStringContainsString('scale=-2', $f);
        $this->assertStringContainsString('format=yuv420p', $f);
        $this->assertStringContainsString('[0:a:0]', $f);
        $this->assertStringNotContainsString('overlay', $f); // pas de burn-in sans sous-titre
    }

    public function testFilterGraphBurnSubAddsSubtitleOverlay(): void
    {
        $f = buildFilterGraph(1080, 1, 2);
        $this->assertStringContainsString('[0:s:2]', $f);
        $this->assertStringContainsString('scale2ref', $f);
        $this->assertStringContainsString('overlay', $f);
        $this->assertStringContainsString('[0:a:1]', $f);
    }

    public function testFilterGraphVaapiUsesGpuScaleAndHwupload(): void
    {
        $f = buildFilterGraph(720, 0, -1, 'none', 'vaapi');
        $this->assertStringContainsString('scale_vaapi', $f);
        $this->assertStringContainsString('hwupload', $f);
    }

    public function testFilterGraphHdrUsesTonemap(): void
    {
        $f = buildFilterGraph(1080, 0, -1, 'hdr');
        $this->assertStringContainsString('tonemap', $f);
        $this->assertStringContainsString('zscale', $f);
    }

    // ── buildFfmpegCodecArgs ────────────────────────────────────────────

    public function testCodecArgsSoftwareByDefault(): void
    {
        $a = buildFfmpegCodecArgs();
        $this->assertStringContainsString('-c:v libx264', $a);
        $this->assertStringContainsString('-c:a aac', $a);
    }

    public function testCodecArgsVaapi(): void
    {
        $this->assertStringContainsString('h264_vaapi', buildFfmpegCodecArgs(48, false, false, 'vaapi'));
    }

    public function testCodecArgsNvenc(): void
    {
        $this->assertStringContainsString('h264_nvenc', buildFfmpegCodecArgs(48, false, false, 'nvenc'));
    }

    public function testCodecArgsV4l2m2mUsesExplicitBitrate(): void
    {
        $a = buildFfmpegCodecArgs(48, false, false, 'v4l2m2m');
        $this->assertStringContainsString('h264_v4l2m2m', $a);
        $this->assertStringContainsString('-b:v', $a); // pas de CRF sur Pi → bitrate explicite
    }

    public function testCodecArgsHdrForcesSoftwareEvenWithGpu(): void
    {
        // HDR → tonemapping CPU obligatoire : le GPU demandé doit être ignoré.
        $a = buildFfmpegCodecArgs(48, true, false, 'vaapi');
        $this->assertStringContainsString('libx264', $a);
        $this->assertStringNotContainsString('h264_vaapi', $a);
    }

    public function testCodecArgsHlsForcesKeyframes(): void
    {
        $this->assertStringContainsString('force_key_frames', buildFfmpegCodecArgs(48, false, true));
    }

    // ── buildFfmpegInputArgs ────────────────────────────────────────────

    public function testInputArgsEscapePathAndForceGenpts(): void
    {
        $a = buildFfmpegInputArgs('/media/my movie.mkv');
        $this->assertStringContainsString('+genpts', $a);
        $this->assertStringContainsString("'/media/my movie.mkv'", $a); // escapeshellarg
        $this->assertStringNotContainsString('-hwaccel', $a);
    }

    public function testInputArgsVaapiAddsHwaccelFlags(): void
    {
        $a = buildFfmpegInputArgs('/x.mkv', '', 'vaapi');
        $this->assertStringContainsString('-hwaccel vaapi', $a);
        $this->assertStringContainsString('-hwaccel_output_format vaapi', $a);
    }

    // ── buildFmp4MuxerArgs ──────────────────────────────────────────────

    public function testFmp4MuxerHasFragmentFlags(): void
    {
        $a = buildFmp4MuxerArgs();
        $this->assertStringContainsString('frag_keyframe', $a);
        $this->assertStringContainsString('empty_moov', $a);
    }
}
