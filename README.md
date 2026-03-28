# ShareBox

**A lightweight, self-hosted alternative to Plex and Jellyfin -- focused on sharing and streaming, not library management.**

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-003B57?logo=sqlite&logoColor=white)
![Tests](https://github.com/ohugonnot/sharebox/actions/workflows/tests.yml/badge.svg)
![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)

Share files and folders instantly with human-readable links. Stream any video directly in the browser with on-the-fly transcoding -- no pre-processing, no media library, no accounts. Just share a link and play.

### Why not Plex / Jellyfin / Emby?

| | ShareBox | Plex / Jellyfin / Emby |
|---|---|---|
| **Purpose** | Share files + stream on demand | Personal media library |
| **Setup** | 2-minute install, single PHP app | Database, metadata agents, user system |
| **Dependencies** | PHP + ffmpeg + SQLite | Java/.NET runtime, database server, plugins |
| **Media library** | None -- just your filesystem | Required (scan, scrape, organize) |
| **User accounts** | None -- links with optional password | Full user management |
| **Transcoding** | On-the-fly, zero pre-processing | Pre-transcoding or on-the-fly |
| **Sharing** | Built-in: link + password + expiry | Requires external sharing plugins |
| **RAM usage** | ~25 MB per stream (PHP-FPM worker) | 500 MB - 2 GB+ |
| **Use case** | "Send someone a movie link" | "Browse my library on my TV" |

ShareBox is not a media center. It won't scrape metadata, build a library, or manage watch history. It's for when you want to **share a file or folder with someone** and let them stream it instantly -- nothing more, nothing less.

### Live Demo

Try it now -- no install needed:

**[Browse demo folder](https://ds72803.seedhost.eu:8282/dl/media-l18g)** -- 5 test videos showcasing all features:

| File | Codec | Features | Link |
|---|---|---|---|
| Sintel.2010.720p.mp4 | H.264 MP4 | Native playback (zero CPU) | [Play](https://ds72803.seedhost.eu:8282/dl/media-l18g?p=Sintel.2010.720p.mp4&play=1) |
| Test.Pattern.1080p.mp4 | H.264 MP4 | Native 1080p | [Play](https://ds72803.seedhost.eu:8282/dl/media-l18g?p=Test.Pattern.1080p.mp4&play=1) |
| Tears.of.Steel.2012.720p.mp4 | H.264 MOV | Native, large file (355 MB) | [Play](https://ds72803.seedhost.eu:8282/dl/media-l18g?p=Tears.of.Steel.2012.720p.mp4&play=1) |
| Subtitle.Demo.720p.mkv | H.264 MKV | Text subtitles (WebVTT overlay) | [Play](https://ds72803.seedhost.eu:8282/dl/media-l18g?p=Subtitle.Demo.720p.mkv&play=1) |
| MultiTrack.HEVC.720p.mkv | HEVC MKV | Transcode + 2 audio + 2 subs (EN/FR) | [Play](https://ds72803.seedhost.eu:8282/dl/media-l18g?p=MultiTrack.HEVC.720p.mkv&play=1) |

![Admin Panel](https://i.postimg.cc/dsFd7Cgz/image.png)

![Folder Sharing](https://i.postimg.cc/HLNk9fBn/image.png)

![Video Streaming](https://i.postimg.cc/Y9gMfj4p/image.png)

## Features

### Core

- **Zero runtime dependencies** -- pure PHP 8.1+, no framework. Composer used only for dev tooling (PHPUnit, PHPStan)
- **SQLite database** -- auto-created on first use, WAL mode, zero configuration
- **Human-readable links** -- slugs generated from filenames (e.g., `/dl/batman-begins-2005-x7k2`)
- **Password protection** -- optional, bcrypt-hashed, brute-force rate-limited (per-token session counter + `sleep(1)`)
- **Expiration** -- set links to auto-expire after a given duration
- **Folder sharing** -- browsable directory listing with sort (name/size), search filter, per-file play button
- **ZIP download** -- download entire folders as a single ZIP archive (size-capped via `MAX_ZIP_SIZE`)
- **QR code generation** -- pure JavaScript, no external library
- **Email sharing** -- send download links directly via email
- **Dark theme UI** -- clean, modern, mobile-responsive (DM Sans + JetBrains Mono)
- **Efficient file serving** -- nginx X-Accel-Redirect (sendfile, zero-copy, supports resume)

### Video Player

Full-featured browser-based video player with on-the-fly transcoding -- no pre-processing required.

- **Probe-first stream selection** -- ffprobe before playback: H.264/MP4 → native (zero CPU), H.264/MKV → remux, HEVC/AV1 → transcode. VP8/VP9 WebM detected and played natively when browser supports it
- **3 stream modes:**
  - **Native** -- browser plays the file directly via X-Accel-Redirect (MP4 H.264 + AAC)
  - **Remux** -- repackage MKV → fragmented MP4 without re-encoding video, audio transcoded to AAC (near-zero CPU)
  - **Transcode** -- full re-encode via libx264 ultrafast + AAC for HEVC/AV1/incompatible codecs
- **HLS mode for iOS Safari** -- segmented TS output for devices that don't support fragmented MP4 progressive streaming; background ffmpeg with cleanup daemon
- **PGS/VOBSUB burn-in** -- image subtitles (BluRay PGS, DVD VOBSUB) burned into the stream via `filter_complex`; `scale2ref` ensures correct positioning regardless of source resolution or anamorphic SAR
- **Adaptive quality** -- 480p, 576p, 720p, 1080p (auto-filtered by source height)
- **Seek support** -- all modes; coarse keyframe seek before `-i` for instant response on 4K HEVC; keyframe PTS correction via dedicated endpoint eliminates subtitle drift
- **Audio track selection** -- switch tracks on the fly (triggers transcode restart)
- **Subtitle tracks:**
  - Text tracks (SRT/ASS/SSA) extracted to WebVTT with SQLite cache, displayed as JS overlay with binary search cue lookup (O(log n) seek, O(1) amortized playback)
  - Image tracks (PGS/VOBSUB) burned in via transcode with `scale2ref` overlay
  - First text track pre-cached in background after probe (instant display on first click)
  - Per-file subtitle selection persisted in `localStorage`
- **Probe cache** -- ffprobe results stored in SQLite keyed by path+mtime; orphan cache purged periodically
- **Subtitle cache** -- extracted WebVTT stored in SQLite, pre-warmed on probe
- **vmtouch** page-cache warming for files < 2 GB
- **Concurrency control** -- `flock`-based semaphore limits concurrent ffmpeg processes (`STREAM_MAX_CONCURRENT`, default 4); probe slots separately limited (5 max)
- **A/V sync hardening** -- `aresample async=3000`, `-thread_queue_size 512`, `-max_muxing_queue_size 1024`, `-min_frag_duration 300000`
- **Probe fallback + proactive restart** -- 2 s fallback to native if probe is slow; if probe resolves within 5 s and optimal mode differs, stream restarts immediately
- **Stall watchdog** -- exponential backoff `base × 2^n` (cap 2 min), differentiated: remux 10 s, transcode 20 s, burn-in 30 s; resets after 30 s of stable playback
- **Mode badge** -- clickable badge shows current mode (NATIF / REMUX / x264 720p); click to cycle through all modes and qualities
- **Resync button** -- one-click A/V resync at current position
- **Keyboard shortcuts** -- Space/K play-pause, ←/→ ±10 s, J/L ±30 s, ↑/↓ volume ±5%, F fullscreen, Z zoom, P PiP, M mute, R resync, 0-9 jump to N×10%, N/B next/prev episode, `?` help overlay
- **Episode navigation** -- prev/next episode buttons with auto-next countdown (8 s) on video end; config (mode, audio, quality, subtitle) transferred to next episode
- **Resume playback** -- position saved every 5 s to `localStorage`; resume banner on reload with 8 s auto-accept
- **Zoom modes** -- Fit / Fill / Stretch cycle (persisted in `localStorage`)
- **Picture-in-Picture** -- native browser PiP support
- **Playback speed** -- 0.5×, 0.75×, 1×, 1.5×, 2× (persisted)
- **Volume** -- slider + scroll wheel + keyboard; debounced localStorage save; OSD feedback
- **iOS Safari** -- `webkitEnterFullscreen` on `<video>` (div fullscreen unsupported); HLS fallback for streaming
- **Landscape mobile** -- auto-immersive mode with auto-hide controls (same as fullscreen)
- **Seekbar tooltip** -- hover preview with timecode
- **rAF throttle** -- all seek-bar DOM writes batched via `requestAnimationFrame`
- **Dynamic document title** -- shows ▶/⏸ + timecode while playing

### Admin Panel

- **File browser** -- browse `BASE_PATH`, create share links for files or folders
- **Link management** -- list, search, delete; download count tracking
- **System dashboard** -- real-time server metrics:
  - CPU (%), RAM, Disk (usage + I/O busy%), Network (MB/s)
  - Color-coded status pills (green/orange/red) visible when collapsed
  - 7-day bandwidth history chart (Chart.js, gradient fills)
  - Active torrent list via rtorrent SCGI XML-RPC (filtered ≥ 50 KB/s)
  - CPU & HDD temperature monitoring (coretemp + drivetemp)
  - Bandwidth quota tracking
  - Adaptive polling: 10 s expanded, 60 s collapsed
  - State persisted in `localStorage`
- **CSRF protection** -- token-based on all POST actions
- **HTTP basic auth** -- configured at web server level

### Security

- Path traversal prevention via `realpath()` + base path validation
- Bcrypt password hashing with brute-force protection (session counter + sleep)
- `session_regenerate_id(true)` after authentication
- Mail header injection sanitisation
- ZIP download size capped
- Subtitle track index bounded (anti-DoS)
- Internal PHP files blocked by nginx
- HTTP security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`
- No `Access-Control-Allow-Origin` on subtitle endpoint (same-origin only)

### Testing & CI

- **156 tests, 261 assertions** across 13 test files
- CI: GitHub Actions on push/PR, PHP 8.1/8.2/8.3 matrix
- PHPStan level 5 static analysis
- Covers: security, slug generation, MIME detection, ffmpeg helpers, concurrency, database migrations, dashboard APIs

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1+ |
| SQLite | 3.x (via PHP PDO) |
| ffmpeg / ffprobe | Required for video streaming |
| Web server | nginx (recommended) or Apache |
| Cron (optional) | For bandwidth history graph: `* * * * * www-data php /srv/share/cron/record_netspeed.php` |

PHP extensions needed: `pdo_sqlite`, `session`, `json` (usually enabled by default).

## Quick Start

### Docker (recommended)

```bash
git clone https://github.com/ohugonnot/sharebox.git
cd sharebox
```

Edit `docker-compose.yml` and set your media path:

```yaml
volumes:
  - /path/to/your/media:/media:ro   # <-- your files here
environment:
  - SHAREBOX_ADMIN_USER=admin
  - SHAREBOX_ADMIN_PASS=changeme
```

```bash
docker compose up -d
```

Open `http://localhost:8080/share` -- done.

| Variable | Default | Description |
|---|---|---|
| `SHAREBOX_ADMIN_USER` | `admin` | Admin panel username |
| `SHAREBOX_ADMIN_PASS` | `sharebox` | Admin panel password |
| `SHAREBOX_MEDIA_DIR` | `/media/` | Path inside the container (match your volume mount) |
| `SHAREBOX_STREAM_MAX_CONCURRENT` | `4` | Max simultaneous ffmpeg processes |
| `SHAREBOX_STREAM_REMUX_ENABLED` | `false` | Enable remux mode for H.264 MKV |
| `SHAREBOX_BANDWIDTH_QUOTA_TB` | `100` | Monthly bandwidth quota in TB |

### One-line installer (Debian/Ubuntu)

```bash
curl -fsSL https://raw.githubusercontent.com/ohugonnot/sharebox/main/install.sh | sudo bash
```

The installer will:
- Install all dependencies (PHP, ffmpeg, web server)
- Ask for your files directory, admin username and password
- Auto-detect and configure nginx or Apache
- Set up HTTP basic auth and permissions
- Get you running in under 2 minutes

### Manual installation

<details>
<summary>Click to expand manual steps</summary>

#### 1. Clone the repository

```bash
git clone https://github.com/ohugonnot/sharebox.git /var/www/sharebox
cd /var/www/sharebox
```

#### 2. Create your configuration

```bash
cp config.example.php config.php
```

Edit `config.php` and set `BASE_PATH` to the directory you want to share files from.

#### 3. Set permissions

```bash
# The data/ directory must be writable by the web server
mkdir -p data
chown www-data:www-data data
```

#### 4. Configure your web server

See [Nginx Setup](#nginx) or [Apache Setup](#apache) below.

#### 5. Protect the admin panel

Create an htpasswd file for basic auth:

```bash
apt install apache2-utils   # if not already installed
htpasswd -c /etc/sharebox.htpasswd admin
```

</details>

---

## Web Server Configuration

### Nginx

Copy `nginx.conf.example` to your nginx configuration and adapt paths as needed.

Key points:
- The admin panel (`/share`) is protected by HTTP basic auth.
- Public download URLs (`/dl/...`) are unauthenticated.
- `X-Accel-Redirect` is used for efficient file serving (the `internal` location).
- The `data/` directory is blocked from direct access.

```bash
cp nginx.conf.example /etc/nginx/sites-available/sharebox.conf
# Edit the file, then:
ln -s /etc/nginx/sites-available/sharebox.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### Apache

ShareBox ships with an `.htaccess` file that handles URL rewriting for Apache. Make sure:

1. `mod_rewrite` is enabled:
   ```bash
   a2enmod rewrite
   ```

2. Your virtual host allows `.htaccess` overrides:
   ```apache
   <Directory /var/www/sharebox>
       AllowOverride All
   </Directory>
   ```

3. Protect the admin panel with basic auth. The `.htaccess` file includes rules for this -- create the password file:
   ```bash
   htpasswd -c /etc/sharebox.htpasswd admin
   ```

4. Reload Apache:
   ```bash
   systemctl reload apache2
   ```

> **Note:** Apache does not support X-Accel-Redirect. ShareBox will fall back to serving files directly through PHP when `XACCEL_PREFIX` is empty. Set `XACCEL_PREFIX` to `''` in your `config.php` when using Apache.

---

## Configuration

All configuration is in `config.php`:

```php
// Root directory for file browsing and sharing
define('BASE_PATH', '/path/to/your/files/');

// SQLite database path (auto-created)
define('DB_PATH', __DIR__ . '/data/share.db');

// X-Accel-Redirect prefix for nginx (set to '' for Apache)
define('XACCEL_PREFIX', '/internal-download');

// Base URL for download links
define('DL_BASE_URL', '/dl/');
```

| Constant | Description |
|---|---|
| `BASE_PATH` | Absolute path to the directory tree you want to share from. All shared files must be under this path. |
| `DB_PATH` | Path to the SQLite database file. Default: `data/share.db` relative to the app. |
| `XACCEL_PREFIX` | Nginx internal redirect prefix. Must match the `location` block in your nginx config. Set to `''` for Apache. |
| `DL_BASE_URL` | URL prefix for public download links. Must match your web server rewrite rules. |
| `STREAM_MAX_CONCURRENT` | Maximum simultaneous ffmpeg transcoding processes. Default: 4. |
| `STREAM_REMUX_ENABLED` | Enable remux mode for H.264 MKV files (video copy, audio transcode). Default: `false`. |
| `STREAM_LOG` | Path to stream log file (PHP + ffmpeg stderr). `false` to disable. |
| `MAX_ZIP_SIZE` | Maximum total size for ZIP downloads (bytes). Default: 10 GB. |

---

## Security Notes

- The **admin panel** (`index.php`, `ctrl.php`) must be protected by HTTP basic auth at the web server level. There is no built-in login system.
- The `data/` directory contains the SQLite database and must **not** be publicly accessible. Both the nginx config and `.htaccess` block access to it.
- Path traversal is prevented: all file paths are resolved with `realpath()` and validated against `BASE_PATH`.
- Share passwords are hashed with **bcrypt** (`password_hash` / `password_verify`).
- **Password brute-force protection** -- failed attempts increment a per-token session counter; `sleep(1)` on each failure, requests blocked after 10 attempts until the session is reset.
- Public download URLs (`/dl/...`) are the only unauthenticated endpoints.
- PHP execution is disabled in download-related locations to prevent code injection.
- CSRF tokens verified with `hash_equals` on all POST actions.
- `session_regenerate_id(true)` after password authentication to prevent session fixation.
- Mail header sanitisation prevents header injection attacks.
- ZIP download size is capped by `MAX_ZIP_SIZE` (default 10 GB).
- Internal PHP files (`db.php`, `config.php`, `functions.php`) are blocked by nginx.
- HTTP security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`.
- Subtitle extraction endpoint (`?subtitle=N`) serves same-origin only -- no `Access-Control-Allow-Origin` header emitted.
- **HTTPS is strongly recommended.** Use Let's Encrypt or a similar CA for production deployments.

---

## Project Structure

```
sharebox/
├── install.sh              # One-line automated installer
├── config.php              # Your local configuration (not tracked)
├── config.example.php      # Example configuration template
├── db.php                  # SQLite database layer (WAL mode, auto-migrations)
├── functions.php           # Shared utilities (slug, path validation, mime, ffmpeg helpers)
├── ctrl.php                # JSON API (browse, create, delete, email)
├── index.php               # Admin panel UI
├── download.php            # Public download handler, router & video player page
├── player.js               # Video player JS (~1050 lines) — state machine, subs, seekbar
├── player.css              # Video player styles (~170 lines)
├── app.js                  # Admin panel JavaScript
├── style.css               # Admin panel styles (dark theme)
├── dashboard.php           # System dashboard HTML (included in index.php)
├── dashboard.js            # Dashboard JS — polling, Chart.js, localStorage
├── handlers/
│   ├── probe.php           # ffprobe with SQLite cache + background subtitle pre-cache
│   ├── subtitle.php        # WebVTT extraction with SQLite cache
│   ├── keyframe.php        # Keyframe PTS lookup for seek correction
│   ├── stream_native.php   # Native streaming via X-Accel-Redirect
│   ├── stream_remux.php    # Remux MKV → fMP4 (video copy + audio AAC)
│   ├── stream_transcode.php # Full transcode x264 + AAC with optional burn-in
│   └── stream_hls.php      # HLS segmented output for iOS Safari
├── api/
│   ├── dashboard_helpers.php   # Testable pure parsers (/proc/*, hwmon)
│   ├── sysinfo.php             # CPU / RAM / Disk / I/O (500 ms window)
│   ├── speed.php               # Instantaneous network speed (8 s cache)
│   ├── netspeed_history.php    # 7-day hourly-aggregated bandwidth history
│   ├── quota.php               # Bandwidth quota tracking
│   └── active_torrents.php     # rtorrent SCGI XML-RPC interface
├── cron/
│   └── record_netspeed.php     # 1-min cron → net_speed table, 7-day purge
├── Dockerfile              # Docker image (Alpine + nginx + php-fpm + ffmpeg)
├── docker-compose.yml      # Docker Compose for quick start
├── docker/
│   ├── entrypoint.sh       # Container init (config gen, htpasswd, services)
│   ├── nginx.conf          # Full nginx config for Docker
│   └── php-fpm.conf        # PHP-FPM pool config for Docker
├── favicon.svg             # App icon
├── nginx.conf.example      # Nginx configuration template
├── .htaccess               # Apache rewrite rules
├── composer.json           # Dev dependencies (PHPUnit, PHPStan)
├── phpunit.xml             # Test configuration
├── phpstan-bootstrap.php   # PHPStan bootstrap (defines constants)
├── tests/                  # PHPUnit test suite (156 tests)
│   ├── SecurityTest.php
│   ├── SlugTest.php
│   ├── FormatAndMimeTest.php
│   ├── FormatTailleEdgeCasesTest.php
│   ├── SemaphoreTest.php
│   ├── ProbeSemaphoreTest.php
│   ├── FfmpegHelpersTest.php
│   ├── DatabaseTest.php
│   ├── DirSizeTest.php
│   ├── DownloadTest.php
│   ├── DashboardSysinfoTest.php
│   ├── DashboardSpeedTest.php
│   └── DashboardTorrentsTest.php
├── data/                   # SQLite database (auto-created, gitignored)
│   └── share.db
└── LICENSE
```

## Testing

```bash
composer install
vendor/bin/phpunit          # 156 tests, 261 assertions
vendor/bin/phpstan analyse  # Level 5 static analysis
```

CI runs on GitHub Actions (push + PR) across PHP 8.1, 8.2, and 8.3 in parallel.

The test suite covers:
- **Security** -- token regex validation, path traversal prevention (including symlinks)
- **Slug generation** -- film names, accents, truncation, uniqueness, collision avoidance
- **File utilities** -- size formatting (edge cases, TB+ values), MIME type detection, media type classification
- **FFmpeg helpers** -- filter graph building, input args, codec args, muxer args
- **Database** -- migrations, probe cache purge logic (file vs folder matching)
- **Concurrency** -- stream slot and probe slot acquisition/release
- **Directory size** -- recursive size calculation
- **Download routing** -- token validation, expiration, password auth flow
- **Dashboard APIs** -- sysinfo parsing, speed calculation, torrent SCGI interface

## License

[MIT](LICENSE)
