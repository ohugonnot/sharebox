<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for hardware-accelerated transcoding helpers.
 * All GPU-specific encoder tests pass the encoder explicitly — no GPU required.
 */
class HwAccelTest extends TestCase
{
    // ── detect_hw_encoder ───────────────────────────────────────────────

    public function testDetectHwEncoderReturnsValidValue(): void
    {
        $result = detect_hw_encoder();
        $this->assertContains($result, ['none', 'vaapi', 'nvenc', 'v4l2m2m'],
            'detect_hw_encoder() must return a known backend name');
    }

    public function testDetectHwEncoderReturnsNoneWhenNoGpu(): void
    {
        // In CI / the demo server there is no GPU device.
        // We can only assert the return is valid — the actual value depends on
        // what FFMPEG_HW_ACCEL is defined to (bootstrap leaves it as 'auto').
        // When hardware is truly absent detect_hw_encoder() must not throw.
        $result = detect_hw_encoder();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * En mode auto, un backend GPU ne doit être choisi QUE si son device existe —
     * sinon ffmpeg liste l'encodeur (compilé) mais l'encodage plante au runtime
     * (ex. nvenc sans GPU : "Cannot load libcuda.so.1"). Régression réelle observée
     * en prod sur un serveur sans carte dont le ffmpeg embarquait nvenc.
     */
    public function testGpuBackendChosenOnlyWhenDevicePresent(): void
    {
        $result = detect_hw_encoder();
        if ($result === 'nvenc') {
            $this->assertTrue(
                file_exists('/dev/nvidia0') || file_exists('/dev/nvidiactl'),
                'nvenc ne doit être choisi que si un device NVIDIA est présent'
            );
        } elseif ($result === 'v4l2m2m') {
            $this->assertTrue(file_exists('/dev/video10'), 'v4l2m2m exige /dev/video10');
        } else {
            $this->assertContains($result, ['none', 'vaapi']);
        }
    }

    // ── buildFfmpegCodecArgs with hardware encoders ──────────────────────

    public function testBuildFfmpegCodecArgsSoftware(): void
    {
        $result = buildFfmpegCodecArgs(FFMPEG_GOP_SIZE_DEFAULT, false, false, 'none');
        $this->assertStringContainsString('-c:v libx264', $result);
        $this->assertStringContainsString('-preset', $result);
        $this->assertStringContainsString('-crf', $result);
        $this->assertStringContainsString('-c:a aac', $result);
        $this->assertStringNotContainsString('h264_vaapi', $result);
        $this->assertStringNotContainsString('h264_nvenc', $result);
        $this->assertStringNotContainsString('h264_v4l2m2m', $result);
    }

    public function testBuildFfmpegCodecArgsVaapi(): void
    {
        $result = buildFfmpegCodecArgs(50, false, false, 'vaapi');
        $this->assertStringContainsString('-c:v h264_vaapi', $result);
        $this->assertStringContainsString('-qp 24', $result);
        $this->assertStringContainsString('-bf 0', $result);
        $this->assertStringContainsString('-g 50', $result);
        $this->assertStringContainsString('-c:a aac', $result);
        $this->assertStringNotContainsString('libx264', $result);
    }

    public function testBuildFfmpegCodecArgsNvenc(): void
    {
        $result = buildFfmpegCodecArgs(50, false, false, 'nvenc');
        $this->assertStringContainsString('-c:v h264_nvenc', $result);
        $this->assertStringContainsString('-preset p4', $result);
        $this->assertStringContainsString('-cq 24', $result);
        $this->assertStringContainsString('-bf 0', $result);
        $this->assertStringContainsString('-g 50', $result);
        $this->assertStringContainsString('-c:a aac', $result);
        $this->assertStringNotContainsString('libx264', $result);
    }

    public function testBuildFfmpegCodecArgsV4l2m2m(): void
    {
        $result = buildFfmpegCodecArgs(50, false, false, 'v4l2m2m');
        $this->assertStringContainsString('-c:v h264_v4l2m2m', $result);
        $this->assertStringContainsString('-b:v 3M', $result);
        $this->assertStringContainsString('-g 50', $result);
        $this->assertStringContainsString('-c:a aac', $result);
        $this->assertStringNotContainsString('libx264', $result);
        // V4L2M2M ne supporte pas CRF/CQ
        $this->assertStringNotContainsString('-crf', $result);
        $this->assertStringNotContainsString('-cq', $result);
    }

    public function testBuildFfmpegCodecArgsHdrForcedSoftwareEvenWithVaapi(): void
    {
        // HDR tonemapping requires CPU pipeline — GPU encoder must be ignored
        $result = buildFfmpegCodecArgs(250, true, false, 'vaapi');
        $this->assertStringContainsString('-c:v libx264', $result);
        $this->assertStringNotContainsString('h264_vaapi', $result);
        $this->assertStringContainsString('-threads 6', $result); // HDR_THREADS
    }

    public function testBuildFfmpegCodecArgsHlsWithVaapi(): void
    {
        $result = buildFfmpegCodecArgs(96, false, true, 'vaapi');
        $this->assertStringContainsString('-c:v h264_vaapi', $result);
        $this->assertStringContainsString('-force_key_frames', $result);
    }

    // ── buildFilterGraph with VAAPI ──────────────────────────────────────

    public function testBuildFilterGraphVaapiUsesScaleVaapi(): void
    {
        $result = buildFilterGraph(720, 0, -1, 'none', 'vaapi');
        $this->assertStringContainsString('scale_vaapi=', $result);
        $this->assertStringContainsString('hwupload', $result);
        $this->assertStringNotContainsString('lanczos', $result);
    }

    public function testBuildFilterGraphVaapiFilterModeHdrFallsBackToSoftwareScale(): void
    {
        // HDR filter uses CPU pipeline — must NOT inject scale_vaapi/hwupload
        $result = buildFilterGraph(1080, 0, -1, 'hdr', 'vaapi');
        $this->assertStringNotContainsString('scale_vaapi', $result);
        $this->assertStringNotContainsString('hwupload', $result);
        $this->assertStringContainsString('tonemap=mobius', $result);
    }

    public function testBuildFilterGraphSoftwareModeUnchanged(): void
    {
        // Software path must be identical whether hwEncoder is 'none' or absent
        $sw   = buildFilterGraph(720, 0, -1, 'none', 'none');
        $base = buildFilterGraph(720, 0);
        $this->assertSame($sw, $base);
    }

    public function testBuildFilterGraphVaapiWithBurnSub(): void
    {
        // VAAPI scale + subtitle overlay
        $result = buildFilterGraph(720, 0, 1, 'none', 'vaapi');
        $this->assertStringContainsString('scale_vaapi=', $result);
        $this->assertStringContainsString('hwupload', $result);
        $this->assertStringContainsString('[0:s:1]', $result);
        $this->assertStringContainsString('overlay', $result);
    }

    // ── buildFfmpegInputArgs with VAAPI ──────────────────────────────────

    public function testBuildFfmpegInputArgsVaapiAddsHwaccelFlags(): void
    {
        $result = buildFfmpegInputArgs('/test/video.mkv', '', 'vaapi');
        $this->assertStringContainsString('-hwaccel vaapi', $result);
        $this->assertStringContainsString('-hwaccel_device', $result);
        $this->assertStringContainsString('-hwaccel_output_format vaapi', $result);
    }

    public function testBuildFfmpegInputArgsSoftwareNoHwaccel(): void
    {
        $result = buildFfmpegInputArgs('/test/video.mkv', '', 'none');
        $this->assertStringNotContainsString('-hwaccel', $result);
    }

    public function testBuildFfmpegInputArgsNvencNoHwaccel(): void
    {
        // NVENC does not need input hwaccel flags
        $result = buildFfmpegInputArgs('/test/video.mkv', '', 'nvenc');
        $this->assertStringNotContainsString('-hwaccel', $result);
    }

    public function testBuildFfmpegInputArgsBackwardCompatibility(): void
    {
        // Calling with only one argument must behave as before (no hwaccel flags)
        $result = buildFfmpegInputArgs('/test/video.mkv');
        $this->assertStringNotContainsString('-hwaccel', $result);
        $this->assertStringContainsString('ffmpeg', $result);
        $this->assertStringContainsString("-i '/test/video.mkv'", $result);
    }
}
