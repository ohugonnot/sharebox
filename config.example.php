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
// define('RTORRENT_SOCK', '/var/run/rtorrent/.rtorrent.sock');

// TMDB API key for automatic poster fetching in grid view.
// Get a free key at https://www.themoviedb.org/settings/api
// Without this, grid view shows letter placeholders instead of posters.
// define('TMDB_API_KEY', 'your_api_key_here');

// ── FFmpeg encoding (override defaults from functions.php) ──────────────────
// Adjust for your hardware. Defaults are tuned for an 8-core server.
// define('FFMPEG_PRESET', 'veryfast');    // ultrafast|superfast|veryfast|faster|fast|medium|slow
// define('FFMPEG_CRF', 22);              // 18-28 (lower = better quality, more CPU)
// define('FFMPEG_THREADS', 4);           // half your core count recommended
// define('FFMPEG_HDR_THREADS', 6);       // tonemapping needs more threads
// define('FFMPEG_TUNE', '');             // '' or 'film'
// define('FFMPEG_BFRAMES', 0);           // 0 = x264 defaults, 2-3 for quality
// define('FFMPEG_REFS', 0);              // 0 = x264 defaults, 3-4 for quality
