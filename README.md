# ShareBox

**Self-hosted file sharing with built-in video streaming.**

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-003B57?logo=sqlite&logoColor=white)
![Tests](https://github.com/ohugonnot/sharebox/actions/workflows/tests.yml/badge.svg)
![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)

Share files and folders instantly with human-readable links. Stream videos directly in the browser with on-the-fly transcoding -- no pre-processing required.

![Admin Panel](https://i.postimg.cc/dsFd7Cgz/image.png)

![Folder Sharing](https://i.postimg.cc/HLNk9fBn/image.png)

![Video Streaming](https://i.postimg.cc/Y9gMfj4p/image.png)

## Features

- **Zero runtime dependencies** -- pure PHP, no framework. Composer used only for dev tooling (PHPUnit)
- **SQLite database** -- auto-created on first use, zero configuration
- **Human-readable links** -- slugs generated from filenames (e.g., `/dl/batman-begins-2005-x7k2`)
- **Password protection** -- optional, bcrypt-hashed
- **Expiration** -- set links to auto-expire after a given duration
- **Video streaming** -- built-in player with ffmpeg transcoding
  - **Probe-first stream selection** -- ffprobe before playback: H.264 → remux (near-zero CPU), HEVC/AV1 → transcode
  - Smart remux for H.264: repackage to fMP4 without re-encoding video, audio transcoded to AAC
  - Full transcode for HEVC/x265/AV1 and incompatible codecs (libx264 ultrafast)
  - **PGS/VOBSUB burn-in** -- image subtitles (BluRay PGS, DVD VOBSUB) burned into the stream via `filter_complex`; `scale2ref` ensures correct positioning regardless of source resolution or anamorphic SAR
  - Adaptive quality: 480p, 720p, 1080p
  - Seek support in all stream modes (keyframe-accurate)
  - Audio track selection
  - Subtitle track selection: text tracks extracted to WebVTT (JS overlay), image tracks burned in
  - ffprobe results cached in SQLite (instant reload, no re-probe on unchanged files)
  - vmtouch page-cache warming for files < 2 GB (reduces I/O latency at stream start)
  - A/V sync hardening: `aresample async=3000`, `-g 50`, `-thread_queue_size 512`, `-max_muxing_queue_size 1024`
  - **Stall watchdog with exponential backoff** -- retry timeout grows as `base × 2^n` (cap 2 min), differentiated by mode: remux 10 s, transcode 20 s, burn-in 30 s
  - **Resync button** -- one-click A/V resync at current position without reloading the page
  - **Keyboard shortcuts** -- Space/K play-pause, ←/→ seek ±10 s, F fullscreen, M mute
  - **Volume slider** -- compact range input with orange fill; volume, mute and playback speed persisted in `localStorage`
  - **Seekbar tooltip** -- hover preview shows timecode at cursor position
  - Binary search subtitle cue lookup (O(log n) on seek, O(1) amortized forward)
  - rAF throttle on `timeupdate` -- all seek-bar DOM writes go through `requestAnimationFrame`
- **Folder sharing** -- browsable directory listing with per-file download
- **ZIP download** -- download entire folders as a single ZIP archive
- **QR code generation** -- pure JavaScript, no external library
- **Email sharing** -- send download links directly via email
- **Dark theme UI** -- clean, modern, mobile-responsive interface
- **Efficient file serving** -- nginx X-Accel-Redirect (sendfile) support
- **Admin panel** -- protected by HTTP basic auth, manage all share links
- **CSRF protection** -- token-based protection on all admin actions
- **Security hardened** -- session fixation prevention, mail header injection protection, ZIP size limits
- **PHPUnit test suite** -- 44 tests covering security, slug generation, file format utilities
- **SQLite probe cache** -- `probe_cache` table stores ffprobe results keyed by path+mtime

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1+ |
| SQLite | 3.x (via PHP PDO) |
| ffmpeg / ffprobe | Required for video streaming |
| Web server | nginx (recommended) or Apache |

PHP extensions needed: `pdo_sqlite`, `session`, `json` (usually enabled by default).

## Quick Start

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
| `MAX_ZIP_SIZE` | Maximum total size for ZIP downloads (bytes). Default: 10 GB. |

---

## Security Notes

- The **admin panel** (`index.php`, `ctrl.php`) must be protected by HTTP basic auth at the web server level. There is no built-in login system.
- The `data/` directory contains the SQLite database and must **not** be publicly accessible. Both the nginx config and `.htaccess` block access to it.
- Path traversal is prevented: all file paths are resolved with `realpath()` and validated against `BASE_PATH`.
- Share passwords are hashed with **bcrypt** (`password_hash` / `password_verify`).
- Public download URLs (`/dl/...`) are the only unauthenticated endpoints.
- PHP execution is disabled in download-related locations to prevent code injection.
- CSRF tokens verified with `hash_equals` on all POST actions.
- `session_regenerate_id(true)` after password authentication to prevent session fixation.
- Mail header sanitisation prevents header injection attacks.
- ZIP download size is capped by `MAX_ZIP_SIZE` (default 10 GB).
- Internal PHP files (`db.php`, `config.php`, `functions.php`) are blocked by nginx.
- HTTP security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`.
- Restrictive CORS policy on subtitle extraction endpoint.
- **HTTPS is strongly recommended.** Use Let's Encrypt or a similar CA for production deployments.

---

## Project Structure

```
sharebox/
├── install.sh          # One-line automated installer
├── config.php          # Your local configuration (not tracked)
├── config.example.php  # Example configuration template
├── db.php              # SQLite database layer (auto-creates tables)
├── ctrl.php            # JSON API (browse, create, delete, email)
├── index.php           # Admin panel UI
├── download.php        # Public download handler & video player
├── app.js              # Admin panel JavaScript
├── style.css           # Styles (dark theme)
├── favicon.svg         # App icon
├── nginx.conf.example  # Nginx configuration template
├── functions.php       # Shared utility functions (slug, path validation, mime)
├── .htaccess           # Apache rewrite rules
├── composer.json       # Dev dependencies (PHPUnit)
├── phpunit.xml         # Test configuration
├── tests/              # PHPUnit test suite
│   ├── SecurityTest.php
│   ├── SlugTest.php
│   ├── FormatAndMimeTest.php
│   └── SemaphoreTest.php
├── data/               # SQLite database (auto-created, gitignored)
│   └── share.db
└── LICENSE
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

The test suite covers:
- **Security** — token regex validation, path traversal prevention (including symlinks)
- **Slug generation** — film names, accents, truncation, uniqueness, collision avoidance
- **File utilities** — size formatting, MIME type detection, media type classification
- **Concurrency** — stream slot acquisition and release

## License

[MIT](LICENSE)
