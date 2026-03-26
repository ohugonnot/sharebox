<?php
/**
 * ShareBox configuration
 * Copy this file to config.php and adjust values for your environment.
 */

// Root directory for file browsing and sharing.
// All shared files must be located under this path.
define('BASE_PATH', '/path/to/your/files/');

// Path to the SQLite database (auto-created on first use).
define('DB_PATH', __DIR__ . '/data/share.db');

// X-Accel-Redirect prefix for nginx (must match the internal location block).
// Set to '' (empty string) if using Apache.
define('XACCEL_PREFIX', '/internal-download');

// Base URL for public download links.
// Must match your web server rewrite rules.
define('DL_BASE_URL', '/dl/');

// Maximum total size allowed for ZIP downloads (in bytes). Default: 10 Go.
define('MAX_ZIP_SIZE', 10 * 1024 * 1024 * 1024);

// Monthly bandwidth quota in TB (used by dashboard widget). Default: 100 TB.
// define('BANDWIDTH_QUOTA_TB', 100);
