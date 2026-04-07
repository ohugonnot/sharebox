<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour extract_title_year, tmdb_score_candidate et les fixes audit.
 */
class TmdbScoringTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../functions.php';
        require_once __DIR__ . '/../db.php';
    }

    // ── extract_title_year : prend la dernière année ──

    public function testExtractTitleYearTakesLastYear(): void
    {
        $r = extract_title_year('2001 A Space Odyssey 1968');
        $this->assertSame(1968, $r['year'], 'Doit prendre la dernière année (release), pas la première (titre)');
        $this->assertStringContainsString('2001 A Space Odyssey', $r['title']);
    }

    public function testExtractTitleYearRangeTakesFirst(): void
    {
        $r = extract_title_year('Breaking Bad (2008-2013) [BD]');
        $this->assertSame(2008, $r['year'], 'Range d\'années proches → prendre la première (TMDB indexe par début de série)');
    }

    public function testExtractTitleYearSingleYear(): void
    {
        $r = extract_title_year('Inception.2010.1080p.BluRay');
        $this->assertSame(2010, $r['year']);
        $this->assertSame('Inception', $r['title']);
    }

    public function testExtractTitleYearNoYear(): void
    {
        $r = extract_title_year('One Piece');
        $this->assertNull($r['year']);
        $this->assertSame('One Piece', $r['title']);
    }

    // ── extract_title_year : tags VOST, DUAL, DC filtrés ──

    public function testExtractTitleYearFiltersVost(): void
    {
        $r = extract_title_year('Movie.Name.VOST.720p');
        $this->assertSame('Movie Name', $r['title']);
    }

    public function testExtractTitleYearFiltersDual(): void
    {
        $r = extract_title_year('Movie.Name.DUAL.1080p');
        $this->assertSame('Movie Name', $r['title']);
    }

    public function testExtractTitleYearFiltersDC(): void
    {
        $r = extract_title_year('Blade.Runner.DC.2160p');
        $this->assertSame('Blade Runner', $r['title']);
    }

    // ── tmdb_score_candidate : pénalité longueur ──

    public function testScoreShortTitlePenalized(): void
    {
        // "One" vs "One Piece" — doit être pénalisé car longueurs divergent
        $score = tmdb_score_candidate('One', null, [
            'title' => 'One Piece',
            'type' => 'tv',
            'vote_count' => 5000,
        ]);
        $this->assertLessThan(50, $score, 'Un titre court vs long doit être pénalisé');
    }

    public function testScoreExactMatchNotPenalized(): void
    {
        $score = tmdb_score_candidate('One Piece', null, [
            'title' => 'One Piece',
            'type' => 'tv',
            'vote_count' => 5000,
        ]);
        $this->assertGreaterThan(70, $score, 'Match exact ne doit pas être pénalisé');
    }

    // ── tmdb_score_candidate : translittération non-latin ──

    public function testScoreNonLatinTitleNotZero(): void
    {
        // Titre en kanji — ne doit pas retourner 0 grâce au fallback mb_strtolower
        $score = tmdb_score_candidate('進撃の巨人', null, [
            'title' => 'Attack on Titan',
            'original_title' => '進撃の巨人',
            'type' => 'tv',
            'vote_count' => 3000,
        ]);
        $this->assertGreaterThan(0, $score, 'Titre kanji identique à original_title ne doit pas scorer 0');
    }

    // ── Handlers : 415 sur non-video ──

    public function testRemuxHandlerHas415OnNonVideo(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_remux.php');
        $this->assertStringContainsString('http_response_code(415)', $source);
    }

    public function testTranscodeHandlerHas415OnNonVideo(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_transcode.php');
        $this->assertStringContainsString('http_response_code(415)', $source);
    }

    // ── Handlers : proc_open + kill PID ──

    public function testRemuxHandlerUsesProcOpen(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_remux.php');
        $this->assertStringContainsString('proc_open', $source, 'Remux doit utiliser proc_open pour le kill PID');
        $this->assertStringContainsString('posix_kill', $source, 'Remux doit avoir posix_kill dans shutdown');
    }

    public function testTranscodeHandlerUsesProcOpen(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_transcode.php');
        $this->assertStringContainsString('proc_open', $source, 'Transcode doit utiliser proc_open pour le kill PID');
        $this->assertStringContainsString('posix_kill', $source, 'Transcode doit avoir posix_kill dans shutdown');
    }

    // ── Player JS : probe vérifie r.ok ──

    public function testPlayerJsProbeChecksResponseOk(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le probe fetch doit vérifier r.ok
        $this->assertStringContainsString(
            "if (!r.ok) throw",
            $source,
            'Le fetch probe doit vérifier r.ok et throw si erreur HTTP'
        );
    }

    // ── download.php : mobile detect matchMedia ──

    public function testMobileDetectUsesMatchMedia(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        $this->assertStringNotContainsString(
            "'ontouchstart' in window",
            $source,
            'Ne doit plus utiliser ontouchstart (casse les laptops tactiles)'
        );
        $this->assertStringContainsString(
            'hover:none',
            $source,
            'Doit utiliser matchMedia hover:none pour détecter le mobile'
        );
    }

    // ── DB : index pending ──

    public function testDbHasPendingIndex(): void
    {
        $db = get_db();
        $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='folder_posters'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('idx_fp_pending', $indexes, 'Index idx_fp_pending doit exister sur folder_posters');
    }
}
