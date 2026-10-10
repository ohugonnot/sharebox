<?php

/**
 * PHPUnit bootstrap — définit DB_PATH sur une DB temporaire AVANT que
 * quoi que ce soit charge db.php ou config.php.
 *
 * Garantie : les tests n'accèdent jamais à la base de production,
 * même si config.php est chargé en cours de route.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Temp DB isolée pour toute la suite de tests
$testDb = tempnam(sys_get_temp_dir(), 'sharebox_test_');

define('DB_PATH',               $testDb);
define('BASE_PATH',             '/tmp/sharebox_test_media/');
define('XACCEL_PREFIX',         '/internal-download');
define('DL_BASE_URL',           '/dl/');
define('STREAM_MAX_CONCURRENT', 4);
define('STREAM_REMUX_ENABLED',  false);
define('STREAM_LOG',            false);
define('BANDWIDTH_QUOTA_TB',    100);
define('UHD_LOCK_PATH',         sys_get_temp_dir() . '/sharebox_test_uhd.lock');
define('UHD_TAKEOVER_WAIT',     0.2);   // 3 s en prod : la suite n'a pas à les payer
define('PROBE_SLOT_DIR',        sys_get_temp_dir());  // jamais data/ : la suite tourne en root

// Nettoyage à la fin du process PHPUnit
register_shutdown_function(function () use ($testDb): void {
    foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $f) {
        if (file_exists($f)) @unlink($f);
    }
    // Les slots de sonde sont créés par la suite : les laisser derrière rendrait
    // le fichier inouvrable pour un run ultérieur sous un autre compte.
    foreach (glob(PROBE_SLOT_DIR . '/sharebox_probe_slot_*.lock') ?: [] as $f) {
        @unlink($f);
    }
});
