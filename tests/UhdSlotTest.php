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
        if (!str_contains(UHD_LOCK_PATH, 'test')) {
            $this->markTestSkipped('UHD_LOCK_PATH pointe sur le verrou de prod');
        }
    }

    protected function tearDown(): void
    {
        @unlink(UHD_LOCK_PATH);
        unset($_SESSION['sharebox_user'], $_SERVER['REMOTE_ADDR']);
    }

    private function probeDb(int $height): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE probe_cache (path TEXT PRIMARY KEY, result TEXT)');
        $stmt = $db->prepare('INSERT INTO probe_cache (path, result) VALUES (:p, :r)');
        $stmt->execute([':p' => '/media/film.mkv', ':r' => json_encode(['videoHeight' => $height])]);
        return $db;
    }

    public function testDetectsUhdAboveThreshold(): void
    {
        $this->assertTrue(isUHDFile($this->probeDb(2160), '/media/film.mkv'));
        $this->assertTrue(isUHDFile($this->probeDb(STREAM_UHD_MIN_HEIGHT), '/media/film.mkv'));
    }

    public function testIgnores1080pAndUnknown(): void
    {
        $this->assertFalse(isUHDFile($this->probeDb(1080), '/media/film.mkv'));
        $this->assertFalse(isUHDFile($this->probeDb(2160), '/media/absent.mkv'));
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
     * Le player relance un transcode à chaque seek : le titulaire ne doit
     * jamais se voir refuser son propre créneau (régression du 19/08).
     */
    public function testSameViewerNeverRefusedOnReseek(): void
    {
        $first = acquireUHDSlot('u:alice');
        $reseek = acquireUHDSlot('u:alice');
        $this->assertIsResource($reseek, 'un seek du titulaire ne doit pas être refusé');
        releaseStreamSlot($reseek);
        releaseStreamSlot($first);
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
