# ShareBox — Installation Guide

> **New here?** Start with the [README](../README.md) for a feature overview and quick-start.  
> This guide covers every platform in detail.

---

## Table of contents

1. [Docker (recommended)](#1-docker)
2. [Raspberry Pi](#2-raspberry-pi)
3. [Synology NAS](#3-synology-nas)
4. [Unraid](#4-unraid)
5. [Bare metal — Debian / Ubuntu](#5-bare-metal--debianubuntu)
6. [Common tasks](#6-common-tasks)

---

## 1. Docker

The fastest way to get running on any machine with Docker installed.

### Quick start

```bash
git clone https://github.com/ohugonnot/sharebox.git && cd sharebox
docker compose up -d
```

Open `http://localhost:8080/share` — done.  
The default compose ships with demo content so you can see it working immediately.

### Use your own media

Edit `docker-compose.yml` before starting:

```yaml
services:
  sharebox:
    build: .
    ports:
      - "8080:80"
    volumes:
      - sharebox-data:/data
      - /path/to/your/media:/media:ro   # <-- your media directory (read-only)
    environment:
      - SHAREBOX_ADMIN_USER=admin
      - SHAREBOX_ADMIN_PASS=changeme
      - SHAREBOX_TMDB_API_KEY=your_tmdb_api_key
    restart: unless-stopped

volumes:
  sharebox-data:
```

The `sharebox-data` named volume holds the SQLite database and poster cache — it persists across container restarts and rebuilds.

<details>
<summary>All environment variables</summary>

| Variable | Default | Description |
|---|---|---|
| `SHAREBOX_ADMIN_USER` | `admin` | Admin panel username |
| `SHAREBOX_ADMIN_PASS` | `sharebox` | Admin panel password (**change this**) |
| `SHAREBOX_MEDIA_DIR` | `/media/` | Media path inside the container |
| `SHAREBOX_TMDB_API_KEY` | *(none)* | TMDB API key for poster grid |
| `SHAREBOX_DEMO_DATA` | `false` | Seed sample media on first start |
| `SHAREBOX_STREAM_MAX_CONCURRENT` | `4` | Max simultaneous ffmpeg processes |
| `SHAREBOX_STREAM_REMUX_ENABLED` | `false` | Enable remux for H.264 MKV (experimental) |
| `SHAREBOX_BANDWIDTH_QUOTA_TB` | `100` | Monthly bandwidth quota for dashboard |

</details>

### Updating

```bash
docker compose pull   # or: docker compose build --pull
docker compose up -d
```

Your data volume is untouched.

### Reverse proxy + HTTPS (nginx + Let's Encrypt)

<details>
<summary>nginx config with Certbot</summary>

Install certbot: `sudo apt install certbot python3-certbot-nginx`

Create `/etc/nginx/sites-available/sharebox`:

```nginx
server {
    listen 80;
    server_name your.domain.com;

    # Admin panel (HTTP basic auth)
    location ^~ /share {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_read_timeout 3600s;   # long for transcoding
    }

    # Public download URLs
    location ^~ /dl/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_buffering off;
        proxy_read_timeout 3600s;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/sharebox /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d your.domain.com
```

</details>

---

## 2. Raspberry Pi

ShareBox runs fine on a Pi 4. Transcoding is CPU-heavy — keep expectations realistic:
- **2 GB RAM**: browsing and native/HLS playback work well, transcoding is slow
- **4 GB RAM**: comfortable for 1-2 concurrent transcodes at 480p-720p
- **USB 3 SSD**: strongly recommended over SD card for media and the SQLite database

### OS

Use **Raspberry Pi OS Lite 64-bit** (Debian Bookworm base). The 64-bit image is required for modern PHP packages.

### Install dependencies

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx ffmpeg php8.2-fpm php8.2-sqlite3 php8.2-mbstring git apache2-utils
```

> If `php8.2-fpm` is not found, try `php-fpm` — Bookworm ships 8.2 by default.

### Clone and configure

```bash
sudo git clone https://github.com/ohugonnot/sharebox.git /var/www/sharebox
sudo cp /var/www/sharebox/config.example.php /var/www/sharebox/config.php
sudo nano /var/www/sharebox/config.php
```

Minimal `config.php`:

```php
<?php
define('BASE_PATH', '/mnt/media/');   // path to your media
define('DB_PATH', __DIR__ . '/data/share.db');
define('XACCEL_PREFIX', '/internal-download');
define('DL_BASE_URL', '/dl/');
```

```bash
sudo mkdir -p /var/www/sharebox/data
sudo chown www-data:www-data /var/www/sharebox/data
sudo chmod 750 /var/www/sharebox/data
```

### Admin password

```bash
sudo htpasswd -c /etc/sharebox.htpasswd admin
```

> **Two authentication modes.** ShareBox ships with a built-in **session login**
> (the `/share/login.php` form — the default for the Docker image, no web-server
> auth needed). Alternatively, you can put **HTTP auth at the reverse proxy**
> (Basic via `htpasswd` as above, or Digest) and let ShareBox trust the
> authenticated identity through a header — set `TRUSTED_AUTH_HEADER` (e.g.
> `REMOTE_USER`) in `config.php`. The `htpasswd` step below is only needed for
> this proxy-auth mode. If a logged-in username matches `ADMIN_USER`, it gets the
> `admin` role; everyone else is a regular `user`.

### Nginx config

Create `/etc/nginx/sites-available/sharebox`:

```nginx
server {
    listen 80;
    server_name _;   # replace with hostname or IP

    include /var/www/sharebox/nginx.conf.example;
}
```

Or add the include inside your existing default server block, then:

```bash
sudo ln -s /etc/nginx/sites-available/sharebox /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### External USB drive for media

```bash
# Find your drive
lsblk

# Create mount point and add to /etc/fstab (persistent mount)
sudo mkdir /mnt/media
sudo blkid /dev/sda1   # note the UUID

# Add to /etc/fstab:
# UUID=xxxx-xxxx  /mnt/media  exfat  defaults,nofail  0  2
# (use ntfs-3g, exfat, or ext4 depending on your drive format)
sudo mount -a
```

Set `BASE_PATH` in `config.php` to `/mnt/media/`.

### Performance tips for Pi

- **Disable remux** (already off by default): `define('STREAM_REMUX_ENABLED', false);`
- **Lower max concurrent streams**: `define('STREAM_MAX_CONCURRENT', 1);` on 2 GB Pi
- **Reduce encoding quality** for faster transcoding: `define('FFMPEG_PRESET', 'ultrafast'); define('FFMPEG_CRF', 28);`
- **H.264 content plays natively** — zero CPU. Transcode only triggers for HEVC/AV1.

---

## 3. Synology NAS

Use **Container Manager** (Docker-based, available on DS920+ and newer with DSM 7.2+).

### Step-by-step

1. Open **Container Manager** > **Project** > **Create**
2. Choose a project name: `sharebox`
3. Set the path to a shared folder, e.g. `/docker/sharebox`
4. Paste this `docker-compose.yml`:

```yaml
version: "3.8"
services:
  sharebox:
    image: ghcr.io/ohugonnot/sharebox:latest
    ports:
      - "8080:80"
    volumes:
      - /volume1/docker/sharebox/data:/data
      - /volume1/Media:/media:ro        # your Synology media folder
    environment:
      - SHAREBOX_ADMIN_USER=admin
      - SHAREBOX_ADMIN_PASS=changeme
      - SHAREBOX_TMDB_API_KEY=your_key
    restart: unless-stopped
```

5. Click **Build** — Container Manager pulls the image and starts the container.
6. Access at `http://NAS-IP:8080/share`

### Volume mapping

| Container path | Map to | Purpose |
|---|---|---|
| `/data` | `/volume1/docker/sharebox/data` | Database + poster cache |
| `/media` | `/volume1/Media` (or wherever your files live) | Media files (read-only) |

### Port forwarding (for external access)

In DSM: **Control Panel > External Access > Router Configuration** — forward external port 443 to the NAS, then set up a reverse proxy in **Application Portal > Reverse Proxy** pointing to `localhost:8080`.

---

## 4. Unraid

### Via Community Applications

1. Open the **Apps** tab and search for `sharebox`
2. Click **Install**, fill in:
   - **Media path**: your Unraid share (e.g. `/mnt/user/Media`)
   - **Data path**: `/mnt/user/appdata/sharebox`
   - **Port**: `8080` (or any free port)
   - **Admin user / password**
3. Apply — the container starts automatically.

### Manual Docker template

If the CA template isn't available yet, add the container manually:

| Field | Value |
|---|---|
| Name | `sharebox` |
| Repository | `ghcr.io/ohugonnot/sharebox:latest` |
| Port mapping | `8080 → 80` |
| Volume: data | `/mnt/user/appdata/sharebox` → `/data` |
| Volume: media | `/mnt/user/Media` → `/media` (read-only) |
| Env: `SHAREBOX_ADMIN_PASS` | `changeme` |
| Env: `SHAREBOX_TMDB_API_KEY` | your key |

---

## 5. Bare metal — Debian/Ubuntu

### One-line installer (easiest)

```bash
curl -fsSL https://raw.githubusercontent.com/ohugonnot/sharebox/main/install.sh | sudo bash
```

Prompts for media path, admin credentials, and web server choice. Installs everything and configures nginx or Apache automatically.

### Manual installation

```bash
sudo apt install -y git ffmpeg nginx php8.2-fpm php8.2-sqlite3 php8.2-mbstring apache2-utils

sudo git clone https://github.com/ohugonnot/sharebox.git /var/www/sharebox
sudo cp /var/www/sharebox/config.example.php /var/www/sharebox/config.php
# Edit config.php: set BASE_PATH, XACCEL_PREFIX, optionally TMDB_API_KEY

sudo mkdir -p /var/www/sharebox/data
sudo chown www-data:www-data /var/www/sharebox/data
sudo chmod 750 /var/www/sharebox/data

sudo htpasswd -c /etc/sharebox.htpasswd admin
```

<details>
<summary>Nginx config (full server block)</summary>

Create `/etc/nginx/sites-available/sharebox.conf`:

```nginx
server {
    listen 80;
    server_name your.domain.com;

    location = /share/favicon.svg {
        auth_basic off;
        alias /var/www/sharebox/favicon.svg;
    }

    location ^~ /share {
        alias /var/www/sharebox;
        auth_basic "ShareBox";
        auth_basic_user_file /etc/sharebox.htpasswd;

        location ^~ /share/data { deny all; }

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $request_filename;
        }

        index index.php;
        try_files $uri $uri/ /share/index.php?$query_string;
    }

    location ~ "^/dl/([a-z0-9][a-z0-9-]{1,50})$" {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/sharebox/download.php;
        fastcgi_param QUERY_STRING token=$1&$args;
    }

    location /internal-download/ {
        internal;
        alias /;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/sharebox.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

</details>

<details>
<summary>Apache config</summary>

```apache
Alias /share /var/www/sharebox
Alias /dl /var/www/sharebox

<Directory /var/www/sharebox>
    AllowOverride All
    Require all granted
    AuthType Basic
    AuthName "ShareBox"
    AuthUserFile /etc/sharebox.htpasswd
    Require valid-user
</Directory>

<LocationMatch "^/dl/">
    Require all granted
</LocationMatch>
```

In `config.php`, set `define('XACCEL_PREFIX', '');` — Apache doesn't support X-Accel-Redirect.

```bash
sudo a2enmod rewrite
sudo a2ensite sharebox
sudo systemctl reload apache2
```

</details>

### Cron jobs

```bash
sudo cp /var/www/sharebox/cron/sharebox.cron /etc/cron.d/sharebox
# Edit paths if your install dir is different from /var/www/sharebox
```

This sets up:
- Network speed recording (every minute, for dashboard graph)
- Passive SQLite backup to `share.db.bak` (hourly)
- HLS temp directory cleanup (every 15 min)
- SQLite cache pruning (daily at 4 AM)

---

## 6. Common tasks

### Getting a TMDB API key

TMDB is free for personal use.

1. Create an account at [themoviedb.org](https://www.themoviedb.org/signup)
2. Go to **Settings > API > Create > Developer**
3. Fill in the form (app name: "ShareBox personal", use: personal)
4. Copy the **API Read Access Token** (the long v4 token) — or the shorter **API Key (v3)**
5. Set it in `config.php`: `define('TMDB_API_KEY', 'your_key');`  
   Or as a Docker env var: `SHAREBOX_TMDB_API_KEY=your_key`

Without a TMDB key, the grid view shows letter placeholders instead of posters. Everything else works normally.

### Setting up HTTPS (Let's Encrypt)

```bash
# For nginx:
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your.domain.com

# For Apache:
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d your.domain.com
```

Certbot auto-renews via a systemd timer — nothing else to configure.

### Updating ShareBox

**Docker:**
```bash
docker compose pull && docker compose up -d
```

**Bare metal / Pi:**
```bash
cd /var/www/sharebox
sudo git pull
sudo systemctl reload nginx   # or apache2
```

No database migrations needed — the schema is auto-updated on first use.

### Backup strategy

Only one directory matters: **`data/`** (or the Docker volume mapped to `/data`).

It contains:
- `share.db` — SQLite database (links, passwords, settings)
- Poster cache

```bash
# Simple backup
cp -r /var/www/sharebox/data /backup/sharebox-$(date +%Y%m%d)

# Or for Docker:
docker run --rm -v sharebox-data:/data -v /backup:/out alpine \
  tar czf /out/sharebox-$(date +%Y%m%d).tar.gz /data
```

`config.php` and your media files are not touched by ShareBox — back up config.php separately if you've customized it.

### Troubleshooting

**Blank page / 500 error**
```bash
# Check PHP-FPM is running
sudo systemctl status php8.2-fpm

# Check permissions on data/
ls -la /var/www/sharebox/data   # should be owned by www-data
```

**Transcoding is very slow**
- Check which mode is being used (shown in the player debug overlay, hold Shift+D)
- Native and remux are fast; transcode (HEVC/AV1 → H.264) is CPU-intensive
- Lower quality: `define('FFMPEG_PRESET', 'ultrafast'); define('FFMPEG_CRF', 28);`

**Posters not loading**
- Verify your TMDB API key is valid: `curl "https://api.themoviedb.org/3/search/movie?api_key=YOUR_KEY&query=batman"`
- Check PHP can reach the internet: `php -r "echo file_get_contents('https://api.themoviedb.org');"`

**Subtitles not showing**
- Supported: SRT, ASS/SSA (converted to WebVTT), PGS/VOBSUB (burned in)
- ffprobe must be installed alongside ffmpeg: `ffprobe -version`

**Download links expire unexpectedly**
- Check the expiration date set when creating the link in the admin panel
- Links with no expiration never expire

**`data/` directory is not writable**
```bash
sudo chown -R www-data:www-data /var/www/sharebox/data
sudo chmod 750 /var/www/sharebox/data
```

---

Found an issue with this guide? [Open an issue](https://github.com/ohugonnot/sharebox/issues) or submit a PR — contributions are welcome.
