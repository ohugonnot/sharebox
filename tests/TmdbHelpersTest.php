<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour tmdb_score_to_verified et tmdb_build_queries (functions.php).
 */
class TmdbHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../functions.php';
        require_once __DIR__ . '/../db.php';
    }

    // ── tmdb_score_to_verified : paliers ──────────────────────────────────────

    /**
     * @dataProvider scoreBelowThresholdProvider
     */
    public function testScoreBelowThresholdReturnsZero(int $score): void
    {
        $this->assertSame(0, tmdb_score_to_verified($score), "Score $score doit retourner 0");
    }

    public static function scoreBelowThresholdProvider(): array
    {
        return [
            'score 0'  => [0],
            'score 1'  => [1],
            'score 34' => [34],
        ];
    }

    /**
     * @dataProvider scoreMidTierProvider
     */
    public function testScoreMidTierReturns40(int $score): void
    {
        $this->assertSame(40, tmdb_score_to_verified($score), "Score $score doit retourner 40");
    }

    public static function scoreMidTierProvider(): array
    {
        return [
            'score 35 (borne basse)' => [35],
            'score 50'               => [50],
            'score 54 (borne haute)' => [54],
        ];
    }

    /**
     * @dataProvider scoreHighTierProvider
     */
    public function testScoreHighTierReturns60(int $score): void
    {
        $this->assertSame(60, tmdb_score_to_verified($score), "Score $score doit retourner 60");
    }

    public static function scoreHighTierProvider(): array
    {
        return [
            'score 55 (borne basse)' => [55],
            'score 70'               => [70],
            'score 79 (borne haute)' => [79],
        ];
    }

    /**
     * @dataProvider scoreTopTierProvider
     */
    public function testScoreTopTierReturns80(int $score): void
    {
        $this->assertSame(80, tmdb_score_to_verified($score), "Score $score doit retourner 80");
    }

    public static function scoreTopTierProvider(): array
    {
        return [
            'score 80 (borne basse)' => [80],
            'score 90'               => [90],
            'score 100'              => [100],
        ];
    }

    // ── tmdb_build_queries : titre simple ─────────────────────────────────────

    public function testSimpleTitleProducesAtLeastOneQuery(): void
    {
        $queries = tmdb_build_queries('Inception');
        $this->assertNotEmpty($queries);
        $this->assertContains('Inception', $queries);
    }

    // ── tmdb_build_queries : titre court (≤ 3 mots) → pas de troncature ───────

    public function testShortTitleNoHalfWordQuery(): void
    {
        $queries = tmdb_build_queries('One Piece');
        // Pas assez de mots pour une troncature à la moitié
        $this->assertCount(1, $queries, 'Titre court ne doit produire qu\'une seule variante');
    }

    // ── tmdb_build_queries : titre long → variante tronquée ──────────────────

    public function testLongTitleProducesHalfWordVariant(): void
    {
        $queries = tmdb_build_queries('Attack on Titan The Final Season');
        // Plus de 3 mots → une variante moitié-mots doit être ajoutée
        $this->assertGreaterThan(1, count($queries), 'Titre long doit produire une variante tronquée');
    }

    // ── tmdb_build_queries : mot-clé "HD" tronque le titre ───────────────────

    public function testHdKeywordProducesShortVariant(): void
    {
        $queries = tmdb_build_queries('One Piece HD');
        // "HD" est un keyword de troncature → variante sans HD
        $this->assertContains('One Piece', $queries, 'Doit produire une variante sans le suffixe HD');
    }

    // ── tmdb_build_queries : mot-clé "Collection" tronque le titre ───────────

    public function testCollectionKeywordProducesShortVariant(): void
    {
        $queries = tmdb_build_queries('Harry Potter Collection Complete');
        $this->assertContains('Harry Potter', $queries, 'Doit produire une variante sans "Collection Complete"');
    }

    // ── tmdb_build_queries : mot-clé "Intégrale" tronque le titre ────────────

    public function testIntegraleKeywordProducesShortVariant(): void
    {
        $queries = tmdb_build_queries('Dragon Ball Intégrale');
        $this->assertContains('Dragon Ball', $queries);
    }

    // ── tmdb_build_queries : pas de doublons dans les variantes ──────────────

    public function testNoDuplicateQueries(): void
    {
        // Si le titre est identique à la version tronquée, pas de doublon
        $queries = tmdb_build_queries('Inception');
        $this->assertSame(count($queries), count(array_unique($queries)), 'Les variantes ne doivent pas avoir de doublons');
    }

    // ── tmdb_build_queries : titre avec caractères spéciaux ──────────────────

    public function testTitleWithSpecialCharsIsPreserved(): void
    {
        $queries = tmdb_build_queries("L'Étrange Noël de M. Jack");
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString("L'Étrange", $queries[0]);
    }
}
