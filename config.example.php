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

// Monthly bandwidth quota in TB (used by dashboard widget). Requires vnstat.
// The quota section is hidden automatically if vnstat is not installed.
// define('BANDWIDTH_QUOTA_TB', 100);

// Maximum network speed in MB/s (dashboard bar scaling). Default: 125 (1 Gbps).
// Set to 1250 for 10 Gbps, 12 for 100 Mbps, etc.
// define('NET_MAX_MBS', 125);

// rtorrent SCGI socket path. The torrents dashboard section is shown only if
// this socket exists on disk. Comment out or set to '' to disable.
// define('RTORRENT_SOCK', '/var/run/ropixv2/.rtorrent.sock');
