<?php
// PHPStan bootstrap — stub constants defined in config.php (not tracked in git)
if (!defined('BASE_PATH'))       define('BASE_PATH', '/tmp/');
if (!defined('DB_PATH'))         define('DB_PATH', '/tmp/share.db');
if (!defined('XACCEL_PREFIX'))   define('XACCEL_PREFIX', '/internal-download');
if (!defined('DL_BASE_URL'))     define('DL_BASE_URL', '/dl/');
if (!defined('MAX_ZIP_SIZE'))    define('MAX_ZIP_SIZE', 10 * 1024 * 1024 * 1024);
if (!defined('STREAM_MAX_CONCURRENT')) define('STREAM_MAX_CONCURRENT', 3);

// Stubs for auth functions (auth.php not in PHPStan paths — pulls session/DB)
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool { return false; }
}
if (!function_exists('require_auth')) {
    function require_auth(): void {}
}
if (!function_exists('get_current_user_name')) {
    function get_current_user_name(): ?string { return null; }
}
