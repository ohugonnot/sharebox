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
        // db.php is loaded once here (cf. DatabaseTest pattern) — warnings about
        // already-defined constants from config.php are silenced by PHPUnit.
        require_once __DIR__ . '/../db.php';
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
        // tmdb_fetch ou tmdb_fetch_cached doit être appelé pour les endpoints search/multi/company/collection
        $count = substr_count($source, 'tmdb_fetch_cached(') + substr_count($source, 'tmdb_fetch(');
        $this->assertGreaterThanOrEqual(
            4,
            $count,
            'handlers/tmdb.php doit utiliser tmdb_fetch[_cached]() pour ses calls TMDB (≥4 occurrences attendues)'
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

    // ── Iter 2 : cache HTTP TMDB ────────────────────────────────────────

    public function testTmdbCacheTableExists(): void
    {
        $db = get_db();
        $row = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tmdb_cache'")->fetch();
        $this->assertNotFalse($row, 'La table tmdb_cache doit exister (créée par db.php)');
    }

    public function testTmdbCacheTableHasRequiredColumns(): void
    {
        $db = get_db();
        $cols = $db->query("PRAGMA table_info(tmdb_cache)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        $this->assertContains('cache_key', $colNames, 'tmdb_cache.cache_key (PK) requis');
        $this->assertContains('value', $colNames, 'tmdb_cache.value requis');
        $this->assertContains('expires_at', $colNames, 'tmdb_cache.expires_at requis');
    }

    public function testTmdbCacheTableHasExpiresIndex(): void
    {
        $db = get_db();
        $idx = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='tmdb_cache'")->fetchAll(PDO::FETCH_COLUMN);
        $hasExpiresIdx = false;
        foreach ($idx as $i) if (str_contains($i, 'expires')) $hasExpiresIdx = true;
        $this->assertTrue($hasExpiresIdx, 'Un index sur tmdb_cache.expires_at est requis pour les purges efficaces');
    }

    public function testTmdbFetchCachedExists(): void
    {
        $this->assertTrue(
            function_exists('tmdb_fetch_cached'),
            'tmdb_fetch_cached() doit être défini dans functions.php'
        );
    }

    public function testTmdbFetchCachedBypassesCacheWhenTtlZero(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch_cached\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m, 'tmdb_fetch_cached doit exister');
        // ttlSec <= 0 = bypass cache (force refresh)
        $this->assertStringContainsString(
            '$ttlSec <= 0',
            $m[0],
            'tmdb_fetch_cached doit accepter ttlSec=0 pour bypass le cache'
        );
    }

    public function testTmdbFetchCachedHasProbabilisticGc(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch_cached\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // Probabilistic GC pour éviter un cron dédié
        $this->assertStringContainsString(
            'mt_rand',
            $m[0],
            'tmdb_fetch_cached doit avoir un GC probabiliste sur cache write'
        );
        $this->assertStringContainsString(
            'expires_at <',
            $m[0],
            'Le GC doit DELETE WHERE expires_at < now'
        );
    }

    public function testTmdbFetchCachedFallsBackOnDbFailure(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        preg_match('/function tmdb_fetch_cached\(.*?\n\}/s', $source, $m);
        $this->assertNotEmpty($m);
        // Si DB indisponible → call direct sans cache (graceful degradation)
        $this->assertStringContainsString(
            'return tmdb_fetch($url)',
            $m[0],
            'tmdb_fetch_cached doit fallback sur tmdb_fetch sans cache si DB échoue'
        );
    }

    public function testTmdbFetchCachedActuallyCaches(): void
    {
        $db = get_db();
        // Insérer manuellement une entrée de cache
        $url = 'https://example-test.invalid/cached-test';
        $key = md5($url);
        $payload = ['cached' => true, 'value' => 42];
        $db->prepare("INSERT OR REPLACE INTO tmdb_cache (cache_key, value, expires_at) VALUES (?, ?, ?)")
           ->execute([$key, json_encode($payload), time() + 3600]);

        // tmdb_fetch_cached doit retourner le cache sans hitter le réseau
        $start = microtime(true);
        $result = tmdb_fetch_cached($url);
        $elapsed = microtime(true) - $start;

        $this->assertSame($payload, $result, 'tmdb_fetch_cached doit retourner la valeur cachée');
        $this->assertLessThan(
            0.1,
            $elapsed,
            'Cache hit doit être < 100ms (pas de call réseau)'
        );

        // Cleanup
        $db->prepare("DELETE FROM tmdb_cache WHERE cache_key = ?")->execute([$key]);
    }

    // ── Iter 3 : TTL refresh folder_posters ──────────────────────────────

    public function testWorkerHasTtlRefreshPhase(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        $this->assertStringContainsString(
            'TMDB_REFRESH_DAYS',
            $source,
            'Worker doit utiliser une constante TMDB_REFRESH_DAYS overridable'
        );
        $this->assertStringContainsString(
            'TMDB_REFRESH_BATCH',
            $source,
            'Worker doit utiliser TMDB_REFRESH_BATCH pour limiter le nombre par run'
        );
    }

    public function testWorkerRefreshOnlyAffectsNonVerified(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        // La phase refresh doit cibler verified < 100 (pas les choix humains)
        $this->assertMatchesRegularExpression(
            '/REFRESH start.*?verified < 100/s',
            $source,
            'La phase refresh doit cibler uniquement les entries verified < 100'
        );
        // Le UPDATE doit aussi inclure verified < 100 dans le WHERE
        $this->assertStringContainsString(
            'WHERE path = :p AND verified < 100',
            $source,
            'Le UPDATE refresh doit avoir un WHERE verified < 100 (race condition guard)'
        );
    }

    public function testWorkerRefreshUsesDirectFetchNotCache(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        // La phase refresh veut data fraîche → tmdb_fetch direct, PAS tmdb_fetch_cached
        preg_match('/REFRESH start.*?REFRESH done/s', $source, $m);
        $this->assertNotEmpty($m, 'La phase refresh doit exister');
        $this->assertStringContainsString(
            'tmdb_fetch(',
            $m[0],
            'refresh doit utiliser tmdb_fetch (pas le cached) pour avoir donnée fraîche'
        );
    }

    public function testWorkerRefreshTouchesOnFailureToAvoidLoop(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        preg_match('/REFRESH start.*?REFRESH done/s', $source, $m);
        $this->assertNotEmpty($m);
        // Si le fetch échoue, on touche updated_at pour ne pas re-trier la même entry au prochain run
        $this->assertStringContainsString(
            'Touch updated_at',
            $m[0],
            'En cas d\'échec fetch, refresh doit touch updated_at pour avancer le curseur'
        );
    }

    public function testTmdbFetchCachedExpiredEntryNotReturned(): void
    {
        $db = get_db();
        $url = 'https://example-test.invalid/expired-test';
        $key = md5($url);
        // Entrée expirée (expires_at dans le passé)
        $db->prepare("INSERT OR REPLACE INTO tmdb_cache (cache_key, value, expires_at) VALUES (?, ?, ?)")
           ->execute([$key, json_encode(['stale' => true]), time() - 100]);

        // tmdb_fetch_cached doit ignorer l'entrée expirée → tentera un fetch (qui échouera sur invalid host)
        $result = tmdb_fetch_cached($url, 1);  // ttl 1s
        $this->assertNull($result, 'Entrée expirée ne doit pas être retournée');

        $db->prepare("DELETE FROM tmdb_cache WHERE cache_key = ?")->execute([$key]);
    }
}
