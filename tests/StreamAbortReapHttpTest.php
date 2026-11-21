<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression of 21/11/2026: a client leaving mid-stream ends the script inside `echo`, and
 * PHP then frees the proc_open handle without waiting. The zombie left under the worker
 * kept busybox `timeout` (Docker image) asleep for 4 h per aborted stream.
 * A fake ffmpeg first in PATH writes forever, so the script can only end on the abort.
 */
class StreamAbortReapHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static int $port = 0;
    private static string $work = '';
    private static string $fixture = '';

    public static function setUpBeforeClass(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('Never run as root: the server would create root-owned slot files in /tmp');
        }
        self::$work = sys_get_temp_dir() . '/sb_reap_' . bin2hex(random_bytes(6));
        mkdir(self::$work . '/bin', 0755, true);
        self::$fixture = self::$work . '/video.mkv';
        file_put_contents(self::$fixture, 'not a real video: ffmpeg is faked');

        // Cached probe: 1080p with audio, so no ffprobe and no UHD lock.
        $db = self::$work . '/db.sqlite';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->exec('CREATE TABLE probe_cache (path TEXT PRIMARY KEY, mtime INTEGER, result TEXT)');
        $pdo->prepare('INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)')
            ->execute([self::$fixture, filemtime(self::$fixture), json_encode(['videoHeight' => 1080, 'audio' => [['index' => 0]]])]);

        file_put_contents(self::$work . '/bin/ffmpeg', "#!/bin/sh\nexec yes FAKE-STREAM\n");
        chmod(self::$work . '/bin/ffmpeg', 0755);

        // Lets the test run against a copy of the handlers (to show it fails without the fix).
        $handlers  = getenv('SHAREBOX_REAP_HANDLERS_DIR') ?: realpath(__DIR__ . '/../handlers');
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
        \$handler = ['transcode' => 'stream_transcode.php', 'remux' => 'stream_remux.php'][\$_GET['stream'] ?? ''] ?? null;
        if (\$handler === null) { http_response_code(404); return true; }
        require \$c['handlers'] . '/' . \$handler;
        PHP);

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::cleanUp();
            self::markTestSkipped('Cannot allocate a local port');
        }
        self::$port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // `exec`: without it proc_open keeps a shell and killing it leaves the server orphaned.
        $cmd = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), self::$port, escapeshellarg(self::$work . '/router.php'));
        self::$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes, null, ['PATH' => self::$work . '/bin:/usr/local/bin:/usr/bin:/bin']);

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', self::$port, $e1, $e2, 0.2);
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
        if (self::$work === '' || !str_starts_with(self::$work, sys_get_temp_dir() . '/sb_reap_')) return;
        shell_exec('rm -rf -- ' . escapeshellarg(self::$work));
    }

    /** @return list<string> "pid state comm" of each child of the server, zombies included */
    private function serverChildren(): array
    {
        $server = proc_get_status(self::$proc)['pid'];
        $out = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
            $stat = @file_get_contents($f);
            if ($stat === false) continue;
            $close = (int)strrpos($stat, ')');
            $fields = explode(' ', substr($stat, $close + 2));
            if ((int)($fields[1] ?? 0) === $server) $out[] = (int)$stat . ' ' . $fields[0] . ' ' . substr($stat, (int)strpos($stat, '(') + 1, $close - (int)strpos($stat, '(') - 1);
        }
        return $out;
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['transcode' => ['transcode'], 'remux' => ['remux']];
    }

    /** @dataProvider modes */
    public function testClientAbortLeavesNoChildBehind(string $mode): void
    {
        $fp = fsockopen('127.0.0.1', self::$port, $errno, $errstr, 2);
        $this->assertNotFalse($fp);
        fwrite($fp, 'GET /?stream=' . $mode . '&f=' . rawurlencode(self::$fixture) . " HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        stream_set_timeout($fp, 10);
        $head = (string)fread($fp, 4096);
        $this->assertStringContainsString('FAKE-STREAM', $head . (string)fread($fp, 4096), 'the stream must have started');
        $this->assertNotSame([], $this->serverChildren(), 'ffmpeg must be running before the client leaves');
        fclose($fp);

        $left = ['?'];
        for ($i = 0; $i < 50 && $left !== []; $i++) {
            usleep(100000);
            $left = $this->serverChildren();
        }
        $this->assertSame([], $left, 'the ffmpeg launcher must be killed AND reaped once the client is gone');
    }
}
