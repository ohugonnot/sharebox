# ShareBox

**Self-hosted file sharing with built-in video streaming.**

Share files and folders instantly with human-readable links. Stream videos directly in the browser with on-the-fly transcoding -- no pre-processing required.

![Admin Panel](https://i.postimg.cc/dsFd7Cgz/image.png)

![Video Streaming](https://i.postimg.cc/HLNk9fBn/image.png)

## Features

- **Zero dependencies** -- pure PHP, no Composer, no framework
- **SQLite database** -- auto-created on first use, zero configuration
- **Human-readable links** -- slugs generated from filenames (e.g., `/dl/batman-begins-2005-x7k2`)
- **Password protection** -- optional, bcrypt-hashed
- **Expiration** -- set links to auto-expire after a given duration
- **Video streaming** -- built-in player with ffmpeg transcoding
  - Smart remux for browser-compatible codecs (near-zero CPU)
  - Full transcode fallback for HEVC/x265/unsupported codecs
  - Adaptive quality: 480p, 720p, 1080p
  - Seek support in transcoded streams
  - Audio track selection
  - Subtitle extraction to WebVTT
- **Folder sharing** -- browsable directory listing with per-file download
- **ZIP download** -- download entire folders as a single ZIP archive
- **QR code generation** -- pure JavaScript, no external library
- **Email sharing** -- send download links directly via email
- **Dark theme UI** -- clean, modern, mobile-responsive interface
- **Efficient file serving** -- nginx X-Accel-Redirect (sendfile) support
- **Admin panel** -- protected by HTTP basic auth, manage all share links

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

---

## Security Notes

- The **admin panel** (`index.php`, `ctrl.php`) must be protected by HTTP basic auth at the web server level. There is no built-in login system.
- The `data/` directory contains the SQLite database and must **not** be publicly accessible. Both the nginx config and `.htaccess` block access to it.
- Path traversal is prevented: all file paths are resolved with `realpath()` and validated against `BASE_PATH`.
- Share passwords are hashed with **bcrypt** (`password_hash` / `password_verify`).
- Public download URLs (`/dl/...`) are the only unauthenticated endpoints.
- PHP execution is disabled in download-related locations to prevent code injection.
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
├── .htaccess           # Apache rewrite rules
├── data/               # SQLite database (auto-created, gitignored)
│   └── share.db
└── LICENSE
```

## License

[MIT](LICENSE)
