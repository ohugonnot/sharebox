<?php
// Test-only constants shared by the e2e router and the seeder. Never loads config.php.
$e2eDir = getenv('SB_E2E_DIR');
if (!$e2eDir || !is_dir($e2eDir)) {
    fwrite(STDERR, "SB_E2E_DIR missing\n");
    exit(1);
}
define('DB_PATH', $e2eDir . '/test.db');
define('BASE_PATH', $e2eDir . '/');
define('XACCEL_PREFIX', '');
define('DL_BASE_URL', '/dl/');
define('STREAM_MAX_CONCURRENT', 4);
define('STREAM_REMUX_ENABLED', false);
define('STREAM_LOG', false);
define('TRUSTED_AUTH_HEADER', 'REMOTE_USER');
define('ADMIN_USER', 'e2e');
define('AUTH_FAIL_SLEEP', 0);
define('AUTH_LOCKOUT_SLEEP', 0);
define('PROBE_SLOT_DIR', $e2eDir);
define('UHD_LOCK_PATH', $e2eDir . '/uhd.lock');
ini_set('session.save_path', $e2eDir);
