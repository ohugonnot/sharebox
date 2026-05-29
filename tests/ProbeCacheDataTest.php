<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db.php';

/**
 * Tests d'EXÉCUTION réels du helper probe_cache_data() et de ses 3 consommateurs
 * (getSubtitleCount / isHDRFile / hasAudioTrack) — extraits d'une duplication du
 * même SELECT. On insère de vraies lignes en base et on appelle les fonctions :
 * c'est le comportement qui est vérifié, pas le texte source.
 */
class ProbeCacheDataTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = get_db();
        $this->db->exec('DELETE FROM probe_cache');
    }

    private function seed(string $path, array $result): void
    {
        $stmt = $this->db->prepare('INSERT INTO probe_cache (path, mtime, result) VALUES (:p, :m, :r)');
        $stmt->execute([':p' => $path, ':m' => 1, ':r' => json_encode($result)]);
    }

    public function testReturnsNullWhenAbsent(): void
    {
        $this->assertNull(probe_cache_data($this->db, '/does/not/exist.mkv'));
    }

    public function testReturnsNullOnCorruptJson(): void
    {
        $this->db->prepare('INSERT INTO probe_cache (path, mtime, result) VALUES (?, 1, ?)')
                 ->execute(['/bad.mkv', 'not-json{']);
        $this->assertNull(probe_cache_data($this->db, '/bad.mkv'));
    }

    public function testDecodesStoredResult(): void
    {
        $this->seed('/a.mkv', ['audio' => [['codec' => 'aac']], 'subtitles' => ['en', 'fr']]);
        $data = probe_cache_data($this->db, '/a.mkv');
        $this->assertIsArray($data);
        $this->assertCount(2, $data['subtitles']);
    }

    public function testGetSubtitleCount(): void
    {
        $this->seed('/s.mkv', ['subtitles' => ['en', 'fr', 'jp']]);
        $this->assertSame(3, getSubtitleCount($this->db, '/s.mkv'));
        $this->assertSame(0, getSubtitleCount($this->db, '/missing.mkv'));
        $this->seed('/nosubs.mkv', ['audio' => [['codec' => 'aac']]]);
        $this->assertSame(0, getSubtitleCount($this->db, '/nosubs.mkv'));
    }

    public function testIsHDRFile(): void
    {
        $this->seed('/hdr.mkv', ['colorTransfer' => 'smpte2084']);
        $this->seed('/hlg.mkv', ['colorTransfer' => 'arib-std-b67']);
        $this->seed('/sdr.mkv', ['colorTransfer' => 'bt709']);
        $this->assertTrue(isHDRFile($this->db, '/hdr.mkv'));
        $this->assertTrue(isHDRFile($this->db, '/hlg.mkv'));
        $this->assertFalse(isHDRFile($this->db, '/sdr.mkv'));
        $this->assertFalse(isHDRFile($this->db, '/missing.mkv'));
    }

    public function testHasAudioTrack(): void
    {
        $this->seed('/withaudio.mkv', ['audio' => [['codec' => 'aac']]]);
        $this->seed('/noaudio.mkv', ['audio' => []]);
        $this->assertTrue(hasAudioTrack($this->db, '/withaudio.mkv'));
        $this->assertFalse(hasAudioTrack($this->db, '/noaudio.mkv'));
        // Pas de données probe → on suppose qu'il y a de l'audio (comportement historique).
        $this->assertTrue(hasAudioTrack($this->db, '/missing.mkv'));
    }
}
