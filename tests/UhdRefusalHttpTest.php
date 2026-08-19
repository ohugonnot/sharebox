<?php

use PHPUnit\Framework\TestCase;

/**
 * Test d'INTÉGRATION du refus UHD au niveau HTTP : on lance un php -S éphémère
 * qui exécute réellement handlers/stream_transcode.php, verrou UHD déjà tenu par
 * un autre spectateur. Les tests unitaires ne couvrent que les helpers ; ici on
 * vérifie le code de statut, le corps JSON et surtout qu'AUCUN ffmpeg n'est lancé
 * — le chemin de refus doit sortir avant proc_open.
 */
class UhdRefusalHttpTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $base = '';
    private static string $router = '';
    private static string $fixture = '';
    private static string $lock = '';
    private static string $db = '';

    public static function setUpBeforeClass(): void
    {
        // tempnam() CRÉE le fichier de base ; on le supprime, sinon chaque run
        // laisse un orphelin de 0 octet que le nettoyage final ne connaît pas.
        $base = tempnam(sys_get_temp_dir(), 'sb_uhd_');
        self::$fixture = $base . '.mkv';
        @unlink($base);
        file_put_contents(self::$fixture, 'pas une vraie video : le refus intervient avant ffmpeg');
        self::$lock = tempnam(sys_get_temp_dir(), 'sb_uhdlock_test_');
        self::$db   = tempnam(sys_get_temp_dir(), 'sb_uhddb_test_');

        // Le fichier passe pour de l'UHD via le cache de sonde — pas d'ffprobe réel.
        $pdo = new PDO('sqlite:' . self::$db);
        $pdo->exec('CREATE TABLE probe_cache (path TEXT PRIMARY KEY, mtime INTEGER, result TEXT)');
        $pdo->prepare('INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)')
            ->execute([realpath(self::$fixture), filemtime(self::$fixture), json_encode(['videoHeight' => 2160])]);

        $functions = realpath(__DIR__ . '/../functions.php');
        $handler   = realpath(__DIR__ . '/../handlers/stream_transcode.php');
        $routerBase = tempnam(sys_get_temp_dir(), 'sb_uhdrouter_');
        self::$router = $routerBase . '.php';
        @unlink($routerBase);
        file_put_contents(self::$router, <<<PHP
        <?php
        define('UHD_LOCK_PATH', '{self_lock}');
        define('PROBE_SLOT_DIR', sys_get_temp_dir());
        define('STREAM_LOG', false);
        define('STREAM_MAX_CONCURRENT', 3);
        require '$functions';
        \$db = new PDO('sqlite:{self_db}');
        \$resolvedPath = \$_GET['f'] ?? '';
        \$audioTrack = 0; \$startSec = 0; \$seekArgBefore = '';
        require '$handler';
        PHP);
        file_put_contents(self::$router, str_replace(
            ['{self_lock}', '{self_db}'],
            [self::$lock, self::$db],
            file_get_contents(self::$router)
        ));

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::markTestSkipped('Cannot allocate a local port');
        }
        $port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // `exec` : sans lui proc_open lance un shell, et proc_get_status renvoie le
        // pid du shell — tuer celui-ci laisse le serveur orphelin (fuite observée).
        $cmd = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg(self::$router));
        self::$proc = proc_open($cmd, [0 => ['pipe','r'], 1 => ['file','/dev/null','a'], 2 => ['file','/dev/null','a']], $pipes);
        self::$base = "http://127.0.0.1:$port";

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($fp) { fclose($fp); return; }
            usleep(100000);
        }
        // PHPUnit n'appelle pas tearDownAfterClass quand setUpBeforeClass échoue :
        // sans ce nettoyage, le serveur et les fichiers temporaires fuient.
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
                for ($i = 0; $i < 20 && proc_get_status(self::$proc)['running']; $i++) {
                    usleep(50000);
                }
                if (proc_get_status(self::$proc)['running']) {
                    @posix_kill($s['pid'], SIGKILL);
                }
            }
            proc_close(self::$proc);
        }
        foreach ([self::$router, self::$fixture, self::$lock, self::$db] as $f) {
            if ($f !== '') @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        @file_put_contents(self::$lock, '');
    }

    /** @return array{0:int,1:string} */
    private function get(string $query): array
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents(self::$base . '/?' . $query, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; }
        }
        return [$code, (string)$body];
    }

    private function holdLockAsSomeoneElse(): mixed
    {
        $fp = fopen(self::$lock, 'c+');
        flock($fp, LOCK_EX | LOCK_NB) || $this->fail('verrou de test déjà tenu');
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, 'u:un-autre-spectateur');
        fflush($fp);
        return $fp;
    }

    /**
     * L'endpoint slotcheck est atteignable sans authentification : sur un fichier
     * absent du cache, il ne doit surtout PAS déclencher d'ffprobe, sinon une
     * rafale de requêtes bon marché martèle le disque partagé.
     */
    public function testProbeNeverSpawnsFfprobeOnACacheMiss(): void
    {
        $uncached = self::$fixture . '.uncached.mkv';
        copy(self::$fixture, $uncached);
        $needle = escapeshellarg('[f]fprobe.*' . basename($uncached));
        try {
            [$code, $body] = $this->get('f=' . rawurlencode(realpath($uncached)) . '&stream=transcode&slotcheck=1');
            $this->assertSame(200, $code);
            $this->assertIsArray(json_decode($body, true), "réponse inattendue : $body");
            $this->assertSame(0, (int)shell_exec("pgrep -fc -- $needle 2>/dev/null || echo 0"),
                'slotcheck ne doit lancer aucun ffprobe');
        } finally {
            @unlink($uncached);
        }
    }

    /**
     * Un fichier léger ne doit rien apprendre sur l'occupation du verrou : sinon
     * n'importe quel porteur de lien peut sonder « quelqu'un regarde-t-il du
     * lourd en ce moment ? » en interrogeant un fichier quelconque.
     */
    public function testProbeOnALightFileLeaksNothingAboutTheGlobalSlot(): void
    {
        $light = self::$fixture . '.light.mkv';
        copy(self::$fixture, $light);
        $pdo = new PDO('sqlite:' . self::$db);
        $pdo->prepare('INSERT OR REPLACE INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)')
            ->execute([realpath($light), filemtime($light), json_encode(['videoHeight' => 1080])]);
        $held = $this->holdLockAsSomeoneElse();
        try {
            [$code, $body] = $this->get('f=' . rawurlencode(realpath($light)) . '&stream=transcode&slotcheck=1');
            $this->assertSame(200, $code);
            $this->assertSame(['uhd' => false, 'busy' => false], json_decode($body, true));
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
            @unlink($light);
        }
    }

    public function testProbeReportsFreeSlotWhenNobodyStreams(): void
    {
        [$code, $body] = $this->get('f=' . rawurlencode(realpath(self::$fixture)) . '&stream=transcode&slotcheck=1');
        $this->assertSame(200, $code);
        $this->assertSame(['uhd' => true, 'busy' => false], json_decode($body, true));
    }

    public function testProbeReportsBusyWhileAnotherViewerHoldsTheSlot(): void
    {
        $held = $this->holdLockAsSomeoneElse();
        [$code, $body] = $this->get('f=' . rawurlencode(realpath(self::$fixture)) . '&stream=transcode&slotcheck=1');
        flock($held, LOCK_UN); fclose($held);
        $this->assertSame(200, $code);
        $this->assertSame(['uhd' => true, 'busy' => true], json_decode($body, true));
    }

    public function testSecondViewerGets503AndNoFfmpegIsSpawned(): void
    {
        // Viser la fixture, pas tout la machine : un vrai flux de production qui
        // démarre dans la fenêtre ferait échouer un comptage global.
        // `[f]fmpeg` : sans les crochets, pgrep -f trouverait le shell qui porte
        // ce motif dans sa propre ligne de commande et compterait un faux positif.
        $needle = escapeshellarg('[f]fmpeg.*' . basename(self::$fixture));
        $held = $this->holdLockAsSomeoneElse();
        [$code, $body] = $this->get('f=' . rawurlencode(realpath(self::$fixture)) . '&stream=transcode&quality=720&audio=0');
        flock($held, LOCK_UN); fclose($held);

        $this->assertSame(503, $code, 'un second spectateur UHD doit être refusé');
        $this->assertSame(['error' => 'uhd_busy'], json_decode($body, true));
        $spawned = (int)shell_exec("pgrep -fc -- $needle 2>/dev/null || echo 0");
        $this->assertSame(0, $spawned, 'le refus ne doit lancer aucun ffmpeg sur ce fichier');
    }
}
