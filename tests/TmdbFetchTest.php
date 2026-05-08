<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour le helper tmdb_fetch (cURL + retry exponentiel).
 * Vérifie la migration depuis @file_get_contents et la gestion des erreurs.
 */
class TmdbFetchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../functions.php';
    }

    // ── Source : tmdb_fetch utilise cURL et pas file_get_contents ──────

    public function testTmdbFetchUsesCurl(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m, 'tmdb_fetch doit exister');
        $this->assertStringContainsString(
            'curl_init',
            $m[0],
            'tmdb_fetch doit utiliser curl_init (pas file_get_contents)'
        );
        $this->assertStringNotContainsString(
            'file_get_contents',
            $m[0],
            'tmdb_fetch ne doit plus utiliser file_get_contents'
        );
    }

    public function testTmdbFetchHasConnectAndTransferTimeouts(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // Connect timeout séparé du transfer timeout (évite hang sur DNS lent)
        $this->assertStringContainsString(
            'CURLOPT_CONNECTTIMEOUT',
            $m[0],
            'tmdb_fetch doit avoir un CURLOPT_CONNECTTIMEOUT séparé'
        );
        $this->assertStringContainsString(
            'CURLOPT_TIMEOUT',
            $m[0],
            'tmdb_fetch doit avoir un CURLOPT_TIMEOUT (transfer)'
        );
    }

    public function testTmdbFetchUsesCurlShareForDnsReuse(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // curl_share pour DNS + SSL session reuse entre appels
        $this->assertStringContainsString(
            'curl_share_init',
            $m[0],
            'tmdb_fetch doit utiliser curl_share_init pour DNS reuse'
        );
        $this->assertStringContainsString(
            'CURL_LOCK_DATA_DNS',
            $m[0],
            'curl_share doit partager DNS resolution'
        );
    }

    public function testTmdbFetchCategorizesErrors(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // Différenciation des erreurs pour debug
        foreach (['timeout', 'dns', 'connect', 'auth', 'rate_limit', 'server_5xx'] as $cat) {
            $this->assertStringContainsString(
                "'" . $cat . "'",
                $m[0],
                'tmdb_fetch doit catégoriser l\'erreur ' . $cat
            );
        }
    }

    public function testTmdbFetchSkipsRetryOnNonRetryableErrors(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // 401/404/4xx ne doivent pas retry (gaspillage)
        $this->assertMatchesRegularExpression(
            "/in_array\(.*'auth'.*'not_found'.*'client_4xx'/",
            $m[0],
            'tmdb_fetch doit skipper retry sur auth/not_found/4xx'
        );
    }

    public function testTmdbFetchExponentialBackoff(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // Backoff exponentiel : 500ms × 2^n
        $this->assertStringContainsString(
            '1 << $attempt',
            $m[0],
            'tmdb_fetch doit avoir un backoff exponentiel (1 << attempt)'
        );
    }

    public function testTmdbFetchRedactsApiKey(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString(
            "api_key=***",
            $m[0],
            'tmdb_fetch doit redacter la clé API dans les logs'
        );
        $this->assertStringContainsString(
            'preg_replace',
            $m[0],
            'tmdb_fetch doit utiliser preg_replace pour redacter api_key'
        );
    }

    // ── Migration : tmdb.php n'utilise plus @file_get_contents pour TMDB ─

    public function testHandlerSearchMigratedToTmdbFetch(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/tmdb.php');
        // Les calls TMDB API doivent passer par tmdb_fetch
        preg_match_all('/@file_get_contents\([^\)]*themoviedb[^\)]*\)/', $source, $matches);
        $this->assertEmpty(
            $matches[0],
            'Aucun @file_get_contents ne doit subsister pour les calls TMDB API'
        );
        // tmdb_fetch doit être appelé pour les endpoints search/multi/company/collection
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($source, 'tmdb_fetch('),
            'handlers/tmdb.php doit utiliser tmdb_fetch() pour ses calls TMDB (≥4 occurrences attendues)'
        );
    }

    // ── Functional : tmdb_fetch retourne null sur erreur réseau ──────────

    public function testTmdbFetchReturnsNullOnDnsFailure(): void
    {
        // URL avec un host inexistant — DNS failure rapide
        $result = tmdb_fetch('https://this-domain-does-not-exist-sharebox-test.invalid/api', null, 0);
        $this->assertNull(
            $result,
            'tmdb_fetch doit retourner null sur DNS failure (pas de throw)'
        );
    }

    public function testTmdbFetchReturnsNullOnTimeout(): void
    {
        // 10.255.255.1 est typiquement non-routable — connect timeout en quelques secondes
        // maxRetries=0 pour ne pas attendre 8s × 3 tentatives
        $start = microtime(true);
        $result = tmdb_fetch('https://10.255.255.1/api', null, 0);
        $elapsed = microtime(true) - $start;
        $this->assertNull($result, 'tmdb_fetch doit retourner null sur connect timeout');
        $this->assertLessThan(
            5,
            $elapsed,
            'tmdb_fetch doit timeout en < 5s (CURLOPT_CONNECTTIMEOUT 3s + marge)'
        );
    }
}
