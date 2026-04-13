<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour le brute-force rate limiting de auth.php.
 * Les fichiers de compteur sont dans sys_get_temp_dir() — on utilise
 * une IP de test unique par suite pour éviter les collisions et on nettoie après.
 */
class RateLimitTest extends TestCase
{
    private string $testIp;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../auth.php';
    }

    protected function setUp(): void
    {
        // IP unique par test pour éviter les interférences entre cas
        $this->testIp = '192.0.2.' . rand(1, 254) . '_' . uniqid();
        $this->clearFile();
    }

    protected function tearDown(): void
    {
        $this->clearFile();
    }

    private function clearFile(): void
    {
        $file = sys_get_temp_dir() . '/sharebox_login_' . md5($this->testIp);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // ── Premier appel : aucune tentative enregistrée ─────────────────────────

    public function testFirstAttemptNotRateLimited(): void
    {
        $this->assertTrue(check_rate_limit($this->testIp), 'Sans historique, la première tentative doit passer');
    }

    // ── Sous le seuil : 4 échecs → encore autorisé ────────────────────────────

    public function testBelowThresholdStillAllowed(): void
    {
        for ($i = 0; $i < 4; $i++) {
            record_failed_attempt($this->testIp);
        }
        $this->assertTrue(check_rate_limit($this->testIp), '4 échecs < 5 → doit encore passer');
    }

    // ── Seuil atteint : 5 échecs → bloqué ────────────────────────────────────

    public function testAfterFiveFailuresIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            record_failed_attempt($this->testIp);
        }
        $this->assertFalse(check_rate_limit($this->testIp), 'Après 5 échecs, doit être rate-limité');
    }

    // ── Au-delà du seuil : 10 échecs → toujours bloqué ───────────────────────

    public function testWellAboveThresholdIsRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            record_failed_attempt($this->testIp);
        }
        $this->assertFalse(check_rate_limit($this->testIp));
    }

    // ── clear_rate_limit : réinitialise le compteur ───────────────────────────

    public function testClearResetsRateLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            record_failed_attempt($this->testIp);
        }
        $this->assertFalse(check_rate_limit($this->testIp), 'Pré-condition : doit être bloqué avant le clear');

        clear_rate_limit($this->testIp);

        $this->assertTrue(check_rate_limit($this->testIp), 'Après clear, doit être débloqué');
    }

    // ── clear_rate_limit : idempotent sur IP inconnue ─────────────────────────

    public function testClearOnUnknownIpIsIdempotent(): void
    {
        // Ne doit pas lever d'exception si le fichier n'existe pas
        clear_rate_limit('198.51.100.99_unknown_' . uniqid());
        $this->assertTrue(true); // Aucune exception levée
    }

    // ── Fichier corrompu : doit passer (fail open) ────────────────────────────

    public function testCorruptFileAllowsAccess(): void
    {
        $file = sys_get_temp_dir() . '/sharebox_login_' . md5($this->testIp);
        file_put_contents($file, '{invalid json{{');

        $this->assertTrue(check_rate_limit($this->testIp), 'Fichier JSON corrompu → doit passer (fail open)');
    }

    // ── Fichier vide : doit passer (fail open) ────────────────────────────────

    public function testEmptyFileAllowsAccess(): void
    {
        $file = sys_get_temp_dir() . '/sharebox_login_' . md5($this->testIp);
        file_put_contents($file, '');

        $this->assertTrue(check_rate_limit($this->testIp), 'Fichier vide → doit passer (fail open)');
    }

    // ── record_failed_attempt : incrémente correctement ──────────────────────

    public function testRecordIncrements(): void
    {
        record_failed_attempt($this->testIp);
        record_failed_attempt($this->testIp);
        record_failed_attempt($this->testIp);

        $file = sys_get_temp_dir() . '/sharebox_login_' . md5($this->testIp);
        $data = json_decode(file_get_contents($file), true);

        $this->assertSame(3, $data['count'], 'Le compteur doit être à 3 après 3 appels');
    }

    // ── Expiration : données > 15 min → débloqué ─────────────────────────────

    public function testExpiredWindowAllowsAccess(): void
    {
        $file = sys_get_temp_dir() . '/sharebox_login_' . md5($this->testIp);
        // Simuler 5 tentatives vieilles de 16 minutes
        $data = ['count' => 5, 'first' => time() - 960];
        file_put_contents($file, json_encode($data));

        $this->assertTrue(check_rate_limit($this->testIp), 'Fenêtre expirée → doit être débloqué');
        $this->assertFileDoesNotExist($file, 'Le fichier expiré doit être supprimé');
    }
}
