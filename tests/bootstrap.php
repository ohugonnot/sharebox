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

// Nettoyage à la fin du process PHPUnit
register_shutdown_function(function () use ($testDb): void {
    foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $f) {
        if (file_exists($f)) @unlink($f);
    }
});
