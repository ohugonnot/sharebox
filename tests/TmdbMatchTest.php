<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour tmdb_match (boucle word-removal) et le passage de l'année en
 * paramètre TMDB typé dans tmdb_search_candidates.
 *
 * tmdb_match fait des appels réseau : on ne le teste pas en bout-à-bout. On vérifie
 * la LOGIQUE testable sans réseau — extraction de titres de release crades, sémantique
 * du scoring word-removal (requête raccourcie), et construction d'URL (source).
 */
class TmdbMatchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../functions.php';
        require_once __DIR__ . '/../db.php';
    }

    // ── extract_title_year : noms de release crades ───────────────────────────

    /**
     * @dataProvider releaseNameProvider
     */
    public function testExtractTitleYearOnReleaseNames(string $name, string $expectedTitle, ?int $expectedYear): void
    {
        $r = extract_title_year($name);
        $this->assertSame($expectedTitle, $r['title'], "Titre attendu pour: $name");
        $this->assertSame($expectedYear, $r['year'], "Année attendue pour: $name");
    }

    public static function releaseNameProvider(): array
    {
        return [
            'movie classic' => ['Movie.Name.2021.1080p.BluRay.x264-GROUP', 'Movie Name', 2021],
            'multi 2160p'   => ['Batman.Begins.2005.MULTI.2160p.UHD.BluRay.x265-GROUP', 'Batman Begins', 2005],
            'french bdrip'  => ['Interstellar.2014.FRENCH.BDRip.XviD-ABC', 'Interstellar', 2014],
            'no year'       => ['One.Piece.S01.VOSTFR.1080p', 'One Piece', null],
            'webdl'         => ['Dune.2021.IMAX.1080p.WEB-DL.DDP5.1', 'Dune IMAX', 2021],
            // Numéros d'épisode à 3-4 chiffres (anime/soap) — motivent s\d{1,2}e?\d{0,4}
            'episode 4 digits' => ['One.Piece.S01E1164.VOSTFR.1080p', 'One Piece', null],
            'episode 3 digits' => ['Naruto.Shippuden.S01E500.VOSTFR', 'Naruto Shippuden', null],
            'episode + year'   => ['Breaking.Bad.S05E14.2013.1080p', 'Breaking Bad', 2013],
        ];
    }

    // ── tmdb_match : titre vide → null sans réseau ────────────────────────────

    public function testEmptyTitleReturnsNullWithoutNetwork(): void
    {
        // Un titre vide produit une query < 2 chars dès la 1re itération → break immédiat,
        // donc pas d'appel réseau et retour null.
        $this->assertNull(tmdb_match('', null, false, 'fake-key'));
        $this->assertNull(tmdb_match(' ', null, false, 'fake-key'));

        // Pas d'appel réseau → $responded doit rester false (sinon le worker brûlerait
        // une tentative match_attempts à tort sur un titre non exploitable).
        $responded = true;
        tmdb_match('', null, false, 'fake-key', null, $responded);
        $this->assertFalse($responded, 'Titre vide → aucun appel TMDB → responded=false');
    }

    // ── tmdb_score_candidate : la requête raccourcie matche mieux ─────────────
    // Cœur de la sémantique word-removal : « 1BR The Apartement » score mal contre
    // le candidat « 1BR », alors que la requête raccourcie « 1BR » score parfaitement.

    public function testShortenedQueryScoresBetterThanFullReleaseTitle(): void
    {
        $candidate = [
            'id' => 1, 'title' => '1BR', 'original_title' => '1BR',
            'year' => '2019', 'type' => 'movie', 'vote_count' => 800,
        ];
        $scoreFull  = tmdb_score_candidate('1BR The Apartement', null, $candidate);
        $scoreShort = tmdb_score_candidate('1BR', null, $candidate);
        $this->assertGreaterThan(
            $scoreFull,
            $scoreShort,
            'La requête raccourcie au vrai titre doit scorer mieux que le nom de release complet'
        );
    }

    // ── tmdb_score_candidate : bonus année exact ──────────────────────────────

    public function testExactYearAddsBonus(): void
    {
        $candidate = [
            'id' => 1, 'title' => 'Inception', 'original_title' => 'Inception',
            'year' => '2010', 'type' => 'movie', 'vote_count' => 5000,
        ];
        $withYear = tmdb_score_candidate('Inception', 2010, $candidate);
        $noYear   = tmdb_score_candidate('Inception', null, $candidate);
        $this->assertGreaterThan($noYear, $withYear, 'Une année exacte doit ajouter un bonus de score');
    }

    // ── tmdb_search_candidates : année en VRAI paramètre TMDB typé (source) ───

    public function testSearchCandidatesUsesTypedYearParam(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        $this->assertStringContainsString(
            'first_air_date_year',
            $source,
            'Le endpoint tv doit filtrer par first_air_date_year'
        );
        $this->assertStringNotContainsString(
            "query=\" . urlencode(\$title . ' ' . \$year)",
            $source,
            "L'année ne doit plus être concaténée dans la query string"
        );
    }

    // ── tmdb_search_candidates : utilise le cache SQLite ──────────────────────

    public function testSearchCandidatesUsesCachedFetch(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        // La boucle word-removal re-tape souvent les mêmes requêtes → cache obligatoire.
        $this->assertMatchesRegularExpression(
            '/function tmdb_search_candidates.*?tmdb_fetch_cached/s',
            $source,
            'tmdb_search_candidates doit utiliser tmdb_fetch_cached pour le cache inter-passes'
        );
    }
}
