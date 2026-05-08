<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour l'endpoint telemetry (ctrl.php?cmd=stream_event) et le helper
 * telemetry_log(). Vérifie l'allowlist des events, la truncation des champs,
 * et que le client (player.js) émet aux bons moments.
 */
class TelemetryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../functions.php';
    }

    // ── telemetry_log() existe et utilise le bon canal ──────────────────

    public function testTelemetryLogFunctionExists(): void
    {
        $this->assertTrue(
            function_exists('telemetry_log'),
            'telemetry_log() doit être défini comme alias de sharebox_log avec channel=telemetry'
        );
    }

    public function testTelemetryLogIsAliasForSharedboxLog(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        $this->assertMatchesRegularExpression(
            "/function telemetry_log\(string \\\$msg\): void.*sharebox_log\(\\\$msg, 'telemetry'\)/",
            $source,
            'telemetry_log doit appeler sharebox_log avec le canal telemetry'
        );
    }

    // ── ctrl.php : endpoint stream_event ────────────────────────────────

    public function testStreamEventCaseExistsInCtrl(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        $this->assertStringContainsString(
            "case 'stream_event':",
            $source,
            'ctrl.php doit avoir un case stream_event'
        );
    }

    public function testStreamEventRequiresPost(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        // Extraire le bloc stream_event (du case jusqu'au break suivant)
        preg_match("/case 'stream_event':(.*?)case '/s", $source, $m);
        $this->assertNotEmpty($m, 'Le case stream_event doit exister');
        $this->assertStringContainsString(
            "REQUEST_METHOD'] !== 'POST'",
            $m[1],
            'stream_event doit rejeter les requêtes non-POST (405)'
        );
    }

    public function testStreamEventValidatesAllowlist(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        preg_match("/case 'stream_event':(.*?)case '/s", $source, $m);
        $this->assertNotEmpty($m);
        // L'allowlist doit contenir au moins ces events
        foreach (['start', 'playing', 'stall', 'fail', 'mode_cascade'] as $event) {
            $this->assertStringContainsString(
                "'" . $event . "'",
                $m[1],
                'stream_event allowlist doit inclure ' . $event
            );
        }
        // Doit appeler in_array avec strict mode (true)
        $this->assertStringContainsString(
            'in_array($event, $allowed, true)',
            $m[1],
            'L\'allowlist check doit utiliser in_array strict (paramètre true)'
        );
    }

    public function testStreamEventTruncatesUserInputs(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        preg_match("/case 'stream_event':(.*?)case '/s", $source, $m);
        $this->assertNotEmpty($m);
        // mode et file_token doivent être tronqués (DoS protection)
        $this->assertStringContainsString(
            'substr((string)$input[\'mode\']',
            $m[1],
            'stream_event doit tronquer le champ mode pour éviter les abuses'
        );
        $this->assertStringContainsString(
            'substr((string)$input[\'file_token\']',
            $m[1],
            'stream_event doit tronquer le champ file_token'
        );
    }

    public function testStreamEventCastsErrorCodeToInt(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        preg_match("/case 'stream_event':(.*?)case '/s", $source, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString(
            "(int)\$input['error_code']",
            $m[1],
            'error_code doit être casté en int (sanitization)'
        );
        $this->assertStringContainsString(
            "(int)\$input['elapsed_ms']",
            $m[1],
            'elapsed_ms doit être casté en int'
        );
    }

    public function testStreamEventCallsTelemetryLog(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        preg_match("/case 'stream_event':(.*?)case '/s", $source, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString(
            'telemetry_log(',
            $m[1],
            'stream_event doit appeler telemetry_log() pour persister l\'event'
        );
        $this->assertStringContainsString(
            'json_encode(',
            $m[1],
            'stream_event doit logger en JSON (format JSONL)'
        );
    }

    // ── CSRF : protection partagée avec les autres endpoints POST ────────

    public function testStreamEventGoesThroughCsrfCheck(): void
    {
        $source = file_get_contents(__DIR__ . '/../ctrl.php');
        // Le case stream_event est dans le switch — le CSRF check est avant
        // (au niveau de REQUEST_METHOD === 'POST' au début du fichier).
        // Vérifier que ce check global existe.
        $this->assertStringContainsString(
            "hash_equals(\$_SESSION['csrf_token'] ?? '', \$csrfToken)",
            $source,
            'Tous les POST doivent passer par hash_equals() sur csrf_token'
        );
    }

    // ── Player JS : sendTelemetry helper ────────────────────────────────

    public function testPlayerJsHasSendTelemetryFunction(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $this->assertStringContainsString(
            'function sendTelemetry(',
            $source,
            'player.js doit définir sendTelemetry()'
        );
        // Doit utiliser keepalive: true (survit au unload de la page)
        $this->assertStringContainsString(
            'keepalive: true',
            $source,
            'sendTelemetry doit utiliser keepalive:true pour les events de fin de session'
        );
        // Doit être désactivé sans CSRF (anonyme/public)
        $this->assertStringContainsString(
            'if (!watchCsrf) return',
            $source,
            'sendTelemetry doit no-op quand watchCsrf est absent'
        );
    }

    public function testPlayerJsEmitsStartOnStream(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        preg_match('/function startStream\(resumeAt\)\s*\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'startStream doit exister');
        $this->assertStringContainsString(
            "sendTelemetry('start'",
            $m[1],
            'startStream doit émettre un event start'
        );
    }

    public function testPlayerJsEmitsFailOnError(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        preg_match('/function onFail\(\)\s*\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'onFail doit exister');
        $this->assertStringContainsString(
            "sendTelemetry('fail'",
            $m[1],
            'onFail doit émettre un event fail avec le code erreur'
        );
    }

    public function testPlayerJsEmitsStallOnWatchdog(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le stall watchdog doit émettre une telemetry quand il déclenche
        preg_match('/S\.stallTimer = setTimeout.*?startStream\(realTime\(\)\);/s', $source, $m);
        $this->assertNotEmpty($m, 'Le stall watchdog doit exister');
        $this->assertStringContainsString(
            "sendTelemetry('stall'",
            $m[0],
            'Le stall watchdog doit émettre un event stall avant retry'
        );
    }

    public function testPlayerJsEmitsModeCascade(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le bloc cascade dans onFail doit emit mode_cascade
        preg_match('/cascade.*?transcode.*?startStream\(pos\);/s', $source, $m);
        $this->assertNotEmpty($m, 'Le bloc cascade doit exister dans onFail');
        $this->assertStringContainsString(
            "sendTelemetry('mode_cascade'",
            $m[0],
            'La cascade vers transcode doit émettre un event mode_cascade'
        );
    }

    public function testPlayerJsEmitsFirstPlayingOnce(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // firstPlayingEmitted doit être déclaré et utilisé
        $this->assertStringContainsString(
            'firstPlayingEmitted',
            $source,
            'player.js doit avoir un flag firstPlayingEmitted pour éviter de spammer'
        );
        // Doit être reset à chaque startStream (nouveau stream = nouvelle mesure)
        preg_match('/function startStream\(resumeAt\)\s*\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString(
            'firstPlayingEmitted = false',
            $m[1],
            'startStream doit reset firstPlayingEmitted (un playing par attempt)'
        );
    }

    // ── Endpoint URL : utilise le même path que mark_watched ─────────────

    public function testPlayerJsSendTelemetryUsesCorrectEndpoint(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $this->assertStringContainsString(
            "/share/ctrl.php?cmd=stream_event",
            $source,
            'sendTelemetry doit POST vers /share/ctrl.php?cmd=stream_event'
        );
    }
}
