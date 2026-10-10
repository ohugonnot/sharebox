<?php

use PHPUnit\Framework\TestCase;

/**
 * Un seul spectateur peut transcoder de l'UHD à la fois : deux décodages
 * HEVC 2160p concurrents passent tous les deux sous le temps réel.
 */
class UhdSlotTest extends TestCase
{
    protected function setUp(): void
    {
        // Garde-fou : la suite tourne en root et créerait des fichiers que
        // www-data ne pourrait plus ouvrir, coupant la détection en production.
        // Tester la valeur RENVOYÉE, pas la constante : une régression dans
        // uhdLockPath() elle-même passerait sous un contrôle de la constante.
        $prodData = realpath(__DIR__ . '/..') . '/data/';
        if (!str_contains(uhdLockPath(), 'test') || str_starts_with(uhdLockPath(), $prodData)) {
            $this->markTestSkipped('uhdLockPath() pointe sur le verrou de prod');
        }
        if (str_starts_with(probeSlotPath(1), $prodData)) {
            $this->markTestSkipped('les slots de sonde pointent sur ceux de prod');
        }
    }

    protected function tearDown(): void
    {
        @rmdir(UHD_LOCK_PATH);
        @unlink(UHD_LOCK_PATH);
        unset($_SESSION['sharebox_user'], $_SERVER['REMOTE_ADDR']);
    }

    private function probeDb(int $height): PDO
    {
        $db = new PDO('sqlite::memory:');
        // mtime 0 = celui d'un chemin inexistant (@filemtime() renvoie false) : la
        // lecture de cache est sensible au mtime, la ligne doit correspondre.
        $db->exec('CREATE TABLE probe_cache (path TEXT NOT NULL PRIMARY KEY, mtime INTEGER NOT NULL, result TEXT NOT NULL)');
        $db->exec('CREATE TABLE video_height_cache (path TEXT NOT NULL PRIMARY KEY, mtime INTEGER NOT NULL, height INTEGER NOT NULL)');
        $stmt = $db->prepare('INSERT INTO probe_cache (path, mtime, result) VALUES (:p, 0, :r)');
        $stmt->execute([':p' => '/media/film.mkv', ':r' => json_encode(['videoHeight' => $height])]);
        return $db;
    }

    public function testDetectsUhdAboveThreshold(): void
    {
        $this->assertTrue(isUHDFile($this->probeDb(2160), '/media/film.mkv'));
        $this->assertTrue(isUHDFile($this->probeDb(STREAM_UHD_MIN_HEIGHT), '/media/film.mkv'));
    }

    /**
     * Les sorties 4K réelles sont en format cinéma (3840x1600, 3840x1608...) :
     * leur HAUTEUR reste sous 2160. Un seuil calé sur 2160 n'en détecterait
     * quasiment aucune — vérifié sur la bibliothèque, 7 fichiers sur 7.
     */
    public function testDetectsCinematic4kWhoseHeightStaysBelow2160(): void
    {
        foreach ([1600, 1608, 1746, 1920] as $h) {
            $this->assertTrue(
                isUHDFile($this->probeDb($h), '/media/film.mkv'),
                "une source 3840x$h doit compter comme haute résolution"
            );
        }
    }

    public function testIgnores1080p(): void
    {
        $this->assertFalse(isUHDFile($this->probeDb(1080), '/media/film.mkv'));
    }

    /**
     * Hauteur indéterminable : on compte le fichier comme lourd. L'inverse
     * ferait tomber le verrou dès que les slots de sonde sont saturés — ou
     * squattés — ce qui rouvrirait la porte à plusieurs décodages concurrents.
     */
    public function testTreatsUndeterminableHeightAsHeavy(): void
    {
        $this->assertNull(sourceVideoHeight($this->probeDb(2160), '/media/absent.mkv', false));
        $this->assertTrue(isUHDFile($this->probeDb(2160), '/media/absent.mkv', false));
    }

    /**
     * Une entrée de cache périmée (fichier remplacé au même chemin) ne doit pas
     * faire foi : sans contrôle du mtime, une version plus lourde échapperait au verrou.
     */
    public function testStaleCacheEntryIsNotTrusted(): void
    {
        $db = $this->probeDb(1080);
        $db->prepare('UPDATE probe_cache SET mtime = 424242 WHERE path = :p')
           ->execute([':p' => '/media/film.mkv']);
        $this->assertNull(
            sourceVideoHeight($db, '/media/film.mkv', false),
            'une hauteur mise en cache pour un autre mtime doit être ignorée'
        );
    }

    public function testHolderIdPrefersSessionUserOverIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $this->assertSame('ip:10.0.0.1', uhdHolderId());
        $_SESSION['sharebox_user'] = 'folken';
        $this->assertSame('u:folken', uhdHolderId());
    }

    public function testSecondViewerIsRefused(): void
    {
        $first = acquireUHDSlot('u:alice');
        $this->assertIsResource($first);
        $this->assertNull(acquireUHDSlot('u:bob'));
        releaseStreamSlot($first);
    }

    public function testSlotFreedAfterRelease(): void
    {
        $first = acquireUHDSlot('u:alice');
        releaseStreamSlot($first);
        $second = acquireUHDSlot('u:bob');
        $this->assertIsResource($second);
        releaseStreamSlot($second);
    }

    /**
     * Le player relance un transcode à chaque seek : quand le flux précédent
     * s'est terminé, le titulaire reprend son créneau sans attendre
     * (régression du 19/08 : un verrou global le refusait sur son propre seek).
     */
    public function testSameViewerTakesOverOncePreviousFlowEnded(): void
    {
        $first = acquireUHDSlot('u:alice');
        releaseStreamSlot($first);
        $reseek = acquireUHDSlot('u:alice');
        $this->assertIsResource($reseek, 'un seek du titulaire ne doit pas être refusé');
        releaseStreamSlot($reseek);
    }

    /**
     * Contrepartie : si le flux précédent tient toujours après l'attente, on
     * refuse même le titulaire. Le laisser passer permettrait à un seul
     * appelant d'empiler autant de décodages 2160p qu'il ouvre de requêtes.
     */
    public function testSameViewerRefusedWhilePreviousFlowStillHolds(): void
    {
        $first = acquireUHDSlot('u:alice');
        $this->assertNull(
            acquireUHDSlot('u:alice'),
            'un flux encore actif doit être refusé, même au même titulaire'
        );
        releaseStreamSlot($first);
    }

    /**
     * Verrou inouvrable (droits, disque plein) : on laisse passer plutôt que de
     * couper l'UHD pour tout le monde sur un problème sans rapport.
     */
    public function testFailsOpenWhenLockFileCannotBeOpened(): void
    {
        @unlink(UHD_LOCK_PATH);
        mkdir(UHD_LOCK_PATH);           // un répertoire : fopen() échouera
        try {
            $fp = acquireUHDSlot('u:alice');
            $this->assertIsResource($fp, 'un verrou inouvrable ne doit pas bloquer le transcode');
            releaseStreamSlot($fp);
            $this->assertFalse(uhdSlotBusy('u:bob'), 'et ne doit pas non plus faire croire à une occupation');
        } finally {
            @rmdir(UHD_LOCK_PATH);
        }
    }

    /**
     * Le watchdog doit rester HORS des flock, sinon il n'obtient la main qu'après
     * la mort de ffmpeg et ne peut jamais tuer un flux abandonné. Vérifié au banc :
     * à l'intérieur « toujours vivant après 8 s », à l'extérieur « tué ».
     */
    public function testHlsCleanupKeepsWatchdogOutsideTheLocks(): void
    {
        $cmd = buildHlsCleanupCmd('WATCHDOG; ', 'RECLAIM', '/tmp/slot.lock');
        $this->assertLessThan(
            strpos($cmd, 'flock -x 9'),
            strpos($cmd, 'WATCHDOG'),
            'le watchdog doit précéder le flock, pas être enveloppé dedans'
        );
        $this->assertStringContainsString("(flock -x 9; RECLAIM) 9>'/tmp/slot.lock'", $cmd);
        $this->assertStringEndsWith('>/dev/null 2>&1 &', $cmd);
    }

    /**
     * PHP exec() passe par /bin/sh — dash sur Debian — qui refuse les descripteurs
     * à plus d'un chiffre. Une chaîne valide sous bash peut donc mourir au parse en
     * production : on la fait analyser par le vrai interpréteur, pas par bash.
     */
    public function testHlsCleanupCommandParsesUnderBinSh(): void
    {
        $reclaim = 'while [ 1 -lt 2 ]; do sleep 1; done; (flock -x 8; rm -rf /tmp/x) 8>/tmp/y';
        foreach (['/tmp/slot.lock', ''] as $slotPath) {
            $cmd = buildHlsCleanupCmd('while kill -0 1 2>/dev/null; do sleep 1; done; ', $reclaim, $slotPath);
            $script = tempnam(sys_get_temp_dir(), 'sb_shcheck_');
            file_put_contents($script, $cmd);
            exec('/bin/sh -n ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
            @unlink($script);
            $this->assertSame(0, $rc, "/bin/sh refuse la commande (slotPath='$slotPath') : " . implode(' ', $out));
        }
    }

    public function testHlsCleanupWithoutSlotStillDetachesAndRunsBothParts(): void
    {
        $cmd = buildHlsCleanupCmd('WATCHDOG; ', 'RECLAIM', '');
        $this->assertSame('(WATCHDOG; RECLAIM) >/dev/null 2>&1 &', $cmd);
    }

    /**
     * Le repli ffprobe est la raison d'être du correctif : sans lui, un fichier
     * jamais indexé — c'est-à-dire presque toute la bibliothèque — échappait au verrou.
     */
    public function testProbesHeightOnCacheMiss(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'sb_uhdvid_');   // tempnam CRÉE le fichier de base
        $video = $base . '.mkv';
        @unlink($base);
        $make = sprintf(
            'ffmpeg -v error -f lavfi -i color=c=black:s=320x240:d=0.1 -c:v libx264 -y %s 2>/dev/null',
            escapeshellarg($video)
        );
        @shell_exec($make);
        if (!file_exists($video) || filesize($video) === 0) {
            @unlink($video);
            $this->markTestSkipped('ffmpeg indisponible pour fabriquer la fixture');
        }
        try {
            $db = new PDO('sqlite::memory:');
            $db->exec('CREATE TABLE probe_cache (path TEXT NOT NULL PRIMARY KEY, mtime INTEGER NOT NULL, result TEXT NOT NULL)');
            $db->exec('CREATE TABLE video_height_cache (path TEXT NOT NULL PRIMARY KEY, mtime INTEGER NOT NULL, height INTEGER NOT NULL)');
            $this->assertSame(240, sourceVideoHeight($db, $video), 'la hauteur doit venir d\'un ffprobe réel');

            // La sonde doit être amortie : l'endpoint de streaming est atteignable
            // avec un simple lien, une re-sonde à chaque requête est un levier d'abus.
            $cached = $db->query('SELECT height, mtime FROM video_height_cache')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(240, (int)$cached['height'], 'la hauteur sondée doit être mémorisée');
            $this->assertSame(filemtime($video), (int)$cached['mtime']);
            $this->assertSame(240, sourceVideoHeight($db, $video, false), 'le second appel doit sortir du cache, sans sonder');

            // Fichier remplacé au même chemin : l'entrée mémorisée ne fait plus foi.
            touch($video, filemtime($video) + 100);
            clearstatcache(true, $video);
            $this->assertNull(sourceVideoHeight($db, $video, false), 'un mtime différent doit invalider la hauteur mémorisée');
        } finally {
            @unlink($video);
        }
    }

    /**
     * Sur HLS, ffmpeg est détaché et survit à la requête : sans reprise, un seek du
     * même spectateur se ferait refuser son propre créneau. Le pid noté dans le
     * verrou permet de remplacer le flux précédent au lieu de le subir.
     */
    public function testSameViewerReplacesItsOwnDetachedStream(): void
    {
        $first = acquireUHDSlot('u:alice');
        $pid = (int)trim((string)shell_exec('setsid sh -c "exec sleep 30" >/dev/null 2>&1 & echo $!'));
        $this->assertGreaterThan(0, $pid);
        uhdSlotRecordPid($first, 'u:alice', $pid);
        $raw = (string)file_get_contents(uhdLockPath());
        $this->assertNotSame('u:alice', trim($raw), 'le pid doit être noté, pas seulement le titulaire');
        $this->assertSame('u:alice', uhdSlotHolder($raw));
        // Le pid seul ne suffit pas : l'espace de pid se recycle en quelques jours
        // et on tuerait le processus d'un autre utilisateur sur une coïncidence.
        $this->assertSame(
            ['u:alice', (string)$pid, procStartTime($pid)],
            explode('|', $raw, 3),
            'le verrou doit porter titulaire|pid|démarrage'
        );
        $this->assertFalse(uhdSlotBusy('u:alice'), 'le titulaire ne se voit pas occupant malgré le pid');
        $this->assertTrue(uhdSlotBusy('u:bob'));

        releaseStreamSlot($first);
        @posix_kill($pid, SIGKILL);
    }

    /**
     * procStartTime identifie un processus sans ambiguïté malgré le recyclage des
     * pid, et supporte un nom de commande contenant espaces et parenthèses.
     */
    /**
     * Reprise réelle : un processus détaché tient le verrou (cas HLS, où ffmpeg
     * survit à la requête). Le titulaire doit récupérer son créneau, et l'ancien
     * flux doit mourir — sans ça, un seek se refuse lui-même.
     */
    public function testTakeoverKillsThePreviousDetachedHolder(): void
    {
        $lock = uhdLockPath();
        $script = sprintf(
            '$f=fopen(%s,"c+"); flock($f,LOCK_EX); $s=explode(" ",substr(file_get_contents("/proc/".getmypid()."/stat"),'
            . 'strrpos(file_get_contents("/proc/".getmypid()."/stat"),")")+2))[19];'
            . 'ftruncate($f,0); rewind($f); fwrite($f,"u:alice|".getmypid()."|".$s); fflush($f); sleep(30);',
            var_export($lock, true)
        );
        $child = (int)trim((string)shell_exec(
            'setsid ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' >/dev/null 2>&1 & echo $!'
        ));
        $this->assertGreaterThan(0, $child);
        try {
            for ($i = 0; $i < 40 && trim((string)@file_get_contents($lock)) === ''; $i++) {
                usleep(50000);
            }
            $this->assertStringStartsWith('u:alice|' . $child . '|', trim((string)file_get_contents($lock)));

            $fp = acquireUHDSlot('u:alice');
            $this->assertIsResource($fp, 'le titulaire doit reprendre son créneau');
            for ($i = 0; $i < 40 && @posix_kill($child, 0); $i++) {
                usleep(50000);
            }
            $this->assertFalse(@posix_kill($child, 0), 'le flux précédent doit avoir été interrompu');
            releaseStreamSlot($fp);
        } finally {
            @posix_kill($child, SIGKILL);
        }
    }

    /**
     * Sondes toutes occupées : on attend jusqu'à PROBE_WAIT avant de conclure que
     * la hauteur est indéterminable, au lieu d'abandonner au premier échec.
     */
    public function testWaitsForAProbeSlotBeforeGivingUp(): void
    {
        $held = [];
        for ($i = 1; $i <= PROBE_MAX_CONCURRENT; $i++) {
            $fp = fopen(probeSlotPath($i), 'w');
            $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB), "slot $i déjà tenu");
            $held[] = $fp;
        }
        try {
            $db = new PDO('sqlite::memory:');
            $t0 = microtime(true);
            $this->assertNull(sourceVideoHeight($db, '/media/peu-importe.mkv'));
            $this->assertGreaterThanOrEqual(
                PROBE_WAIT * 0.8,
                microtime(true) - $t0,
                'la sonde doit patienter avant de conclure'
            );
        } finally {
            foreach ($held as $fp) { flock($fp, LOCK_UN); fclose($fp); }
        }
    }

    /**
     * Un verrou dont le champ pid vaut 1 ne doit JAMAIS déclencher le kill :
     * posix_kill(-1, …) diffuse le signal à tous les processus signalables, ce
     * qui coucherait le serveur web entier. Idem pour le pid du process courant.
     */
    public function testNeverKillsPidOneNorItself(): void
    {
        // En root, un plancher retire (mutation testing) transforme posix_kill(-1) en
        // diffusion SIGTERM a toute la machine : ce test a deja couche le serveur.
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('suite lancee en root : ce test peut signaler tous les process');
        }
        foreach ([1, getmypid()] as $dangerous) {
            @unlink(uhdLockPath());
            file_put_contents(uhdLockPath(), 'u:alice|' . $dangerous . '|' . procStartTime($dangerous));
            $blocker = fopen(uhdLockPath(), 'c+');
            $this->assertTrue(flock($blocker, LOCK_EX | LOCK_NB));
            try {
                // Le verrou est tenu et l'identité correspond : sans le plancher, le
                // code tuerait ce pid. Il doit refuser plutôt que de signaler.
                $this->assertNull(acquireUHDSlot('u:alice'), "pid $dangerous ne doit pas être ciblé");
                $this->assertTrue(posix_kill(getmypid(), 0), 'le process de test doit être intact');
            } finally {
                flock($blocker, LOCK_UN);
                fclose($blocker);
            }
        }
    }

    public function testProcStartTimeIdentifiesAProcess(): void
    {
        $this->assertNull(procStartTime(0));
        $this->assertNull(procStartTime((int)trim((string)@file_get_contents('/proc/sys/kernel/pid_max')) - 1));
        $mine = procStartTime(getmypid());
        $this->assertNotNull($mine);
        $this->assertMatchesRegularExpression('/^\d+$/', $mine);
        $this->assertSame($mine, procStartTime(getmypid()), 'la valeur doit être stable');
    }

    public function testProbeSlotPathHonoursTheOverride(): void
    {
        $this->assertSame(PROBE_SLOT_DIR . '/sharebox_probe_slot_3.lock', probeSlotPath(3));
        $this->assertStringNotContainsString(
            realpath(__DIR__ . '/..') . '/data/',
            probeSlotPath(1),
            'un chemin de prod en dur ferait écrire la suite dans le webroot'
        );
    }

    /**
     * La sonde doit être bornée : sans `timeout`, un fichier illisible bloque le
     * worker PHP et retient un slot. On remplace `timeout` par un mouchard dans le
     * PATH pour vérifier qu'il est réellement invoqué, et avec quel délai.
     */
    public function testProbeIsWrappedInTimeout(): void
    {
        $bin = sys_get_temp_dir() . '/sb_fakebin_' . getmypid();
        @mkdir($bin);
        $log = $bin . '/args.txt';
        file_put_contents($bin . '/timeout', "#!/bin/sh\necho \"\$@\" > " . escapeshellarg($log) . "\nexit 0\n");
        chmod($bin . '/timeout', 0755);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $bin . ':' . $oldPath);
        try {
            $db = new PDO('sqlite::memory:');
            $db->exec('CREATE TABLE probe_cache (path TEXT NOT NULL PRIMARY KEY, mtime INTEGER NOT NULL, result TEXT NOT NULL)');
            $db->exec('CREATE TABLE video_height_cache (path TEXT NOT NULL PRIMARY KEY, mtime INTEGER NOT NULL, height INTEGER NOT NULL)');
            sourceVideoHeight($db, '/media/peu-importe.mkv');
            $this->assertFileExists($log, 'ffprobe doit être lancé via `timeout`');
            $args = trim((string)file_get_contents($log));
            $this->assertStringStartsWith(PROBE_TIMEOUT . ' ffprobe', $args, "reçu : $args");
        } finally {
            putenv('PATH=' . $oldPath);
            @unlink($log);
            @unlink($bin . '/timeout');
            @rmdir($bin);
        }
    }

    public function testBusyCheckIsBlindToItsOwnHolder(): void
    {
        $fp = acquireUHDSlot('u:alice');
        $this->assertFalse(uhdSlotBusy('u:alice'), 'le titulaire ne se voit pas lui-même comme occupant');
        $this->assertTrue(uhdSlotBusy('u:bob'));
        releaseStreamSlot($fp);
        $this->assertFalse(uhdSlotBusy('u:bob'));
    }
}
