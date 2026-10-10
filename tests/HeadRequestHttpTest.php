<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression of 04/10/2026: under FPM, PHP drops the body of a HEAD without any failed
 * write, so the client leaving was never seen and ffmpeg ran until the end of the film.
 * A fake ffmpeg first in PATH logs each call; each HEAD has a GET twin, otherwise a
 * broken handler would pass too.
 */
class HeadRequestHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $work = '';
    private static string $fixture = '';

    public static function setUpBeforeClass(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('Never run as root: the server would create root-owned slot files in /tmp');
        }
        self::$work = sys_get_temp_dir() . '/sb_head_' . bin2hex(random_bytes(6));
        mkdir(self::$work . '/bin', 0755, true);
        self::$fixture = self::$work . '/video.mkv';
        file_put_contents(self::$fixture, 'not a real video: ffmpeg is faked');

        // Cached probe: 1080p with audio, so no ffprobe and no UHD lock.
        $db = self::$work . '/db.sqlite';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->exec('CREATE TABLE probe_cache (path TEXT PRIMARY KEY, mtime INTEGER, result TEXT)');
        $pdo->prepare('INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)')
            ->execute([self::$fixture, filemtime(self::$fixture), json_encode(['videoHeight' => 1080, 'audio' => [['index' => 0]]])]);

        // exec sleep: the fake keeps its output open like a real transcode.
        file_put_contents(self::$work . '/bin/ffmpeg', "#!/bin/sh\necho \"\$*\" >> "
            . escapeshellarg(self::$work . '/calls') . "\nprintf 'FAKE-STREAM'\nexec sleep 2\n");
        chmod(self::$work . '/bin/ffmpeg', 0755);

        // Lets the test run against a copy of the handlers (to show it fails without the guard).
        $handlers  = getenv('SHAREBOX_HEAD_HANDLERS_DIR') ?: realpath(__DIR__ . '/../handlers');
        $functions = realpath(__DIR__ . '/../functions.php');
        $vars = var_export([
            'lock' => self::$work . '/uhd.lock', 'log' => self::$work . '/stream.log',
            'db' => $db, 'functions' => $functions, 'handlers' => $handlers,
        ], true);
        file_put_contents(self::$work . '/router.php', <<<PHP
        <?php
        \$c = $vars;
        define('UHD_LOCK_PATH', \$c['lock']);
        define('PROBE_SLOT_DIR', dirname(\$c['lock']));
        define('STREAM_LOG', \$c['log']);
        define('FFMPEG_HW_ACCEL', 'none');
        // Slot files are shared with prod (3 slots): going past them avoids a 503.
        define('STREAM_MAX_CONCURRENT', 8);
        require \$c['functions'];
        \$db = new PDO('sqlite:' . \$c['db']);
        \$resolvedPath = \$_GET['f'] ?? '';
        \$audioTrack = 0; \$startSec = 0; \$seekArgBefore = '';
        \$audioMap = ' -map 0:v:0 -map 0:a:0';
        \$token = 'test'; \$subPath = '';
        \$handler = ['transcode' => 'stream_transcode.php', 'remux' => 'stream_remux.php', 'hls' => 'stream_hls.php'][\$_GET['stream'] ?? ''] ?? null;
        if (\$handler === null) { http_response_code(404); return true; }
        require \$c['handlers'] . '/' . \$handler;
        PHP);

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::cleanUp();
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // `exec`: without it proc_open keeps a shell and killing it leaves the server orphaned.
        $cmd = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg(self::$work . '/router.php'));
        self::$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes, null, ['PATH' => self::$work . '/bin:/usr/local/bin:/usr/bin:/bin']);
        self::$base = "http://127.0.0.1:$port/";

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($fp) { fclose($fp); return; }
            usleep(100000);
        }
        self::cleanUp();
        self::markTestSkipped('php -S did not start');
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanUp();
    }

    private static function cleanUp(): void
    {
        if (is_resource(self::$proc)) {
            $s = proc_get_status(self::$proc);
            if ($s['running']) {
                @posix_kill($s['pid'], SIGTERM);
                for ($i = 0; $i < 20 && proc_get_status(self::$proc)['running']; $i++) usleep(50000);
                if (proc_get_status(self::$proc)['running']) @posix_kill($s['pid'], SIGKILL);
            }
            proc_close(self::$proc);
        }
        if (self::$work === '' || !str_starts_with(self::$work, sys_get_temp_dir() . '/sb_head_')) return;
        // The HLS GET leaves its detached cleanup shell behind (2 min idle wait, holding a stream slot).
        shell_exec('pkill -f -- ' . escapeshellarg(preg_quote(self::$work)) . ' 2>/dev/null');
        shell_exec('rm -rf -- ' . escapeshellarg(self::$work));
    }

    /** @return array{code:int,type:string,body:string,elapsed:float} */
    private function request(string $method, string $mode): array
    {
        @unlink(self::$work . '/calls');
        $ch = curl_init(self::$base . '?stream=' . $mode . '&f=' . rawurlencode(self::$fixture));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_TIMEOUT => 40]);
        $t = microtime(true);
        $body = (string)curl_exec($ch);
        return [
            'code'    => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'type'    => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'body'    => $body,
            'elapsed' => microtime(true) - $t,
        ];
    }

    private function ffmpegCalls(): string
    {
        clearstatcache();
        return (string)@file_get_contents(self::$work . '/calls');
    }

    /** @return array<string, array{string, string, string}> */
    public static function modes(): array
    {
        return [
            'transcode' => ['transcode', 'video/mp4', '-f mp4'],
            'remux'     => ['remux', 'video/mp4', '-c:v copy'],
            'hls'       => ['hls', 'application/vnd.apple.mpegurl', '-f hls'],
        ];
    }

    /** @dataProvider modes */
    public function testHeadSpawnsNoFfmpeg(string $mode, string $type): void
    {
        $r = $this->request('HEAD', $mode);
        $this->assertSame(200, $r['code']);
        $this->assertStringStartsWith($type, $r['type']);
        $this->assertSame('', $this->ffmpegCalls(), 'HEAD must not start ffmpeg');
        $this->assertLessThan(1.0, $r['elapsed'], 'HEAD must not wait for ffmpeg');
    }

    /** @dataProvider modes */
    public function testGetStillSpawnsFfmpeg(string $mode, string $type, string $marker): void
    {
        $r = $this->request('GET', $mode);
        $this->assertStringContainsString($marker, $this->ffmpegCalls(), 'GET must start ffmpeg');
        if ($mode === 'hls') {
            // The fake ffmpeg writes no segment: the handler gives up with a 504 once it is dead.
            $this->assertSame(504, $r['code']);
            return;
        }
        $this->assertSame(200, $r['code']);
        $this->assertSame('FAKE-STREAM', $r['body']);
    }
}
