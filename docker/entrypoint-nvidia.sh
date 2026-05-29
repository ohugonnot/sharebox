#!/bin/bash
set -e

# ── Defaults ──────────────────────────────────────────────────────────────────
MEDIA_DIR="${SHAREBOX_MEDIA_DIR:-/media/}"
ADMIN_USER="${SHAREBOX_ADMIN_USER:-admin}"
ADMIN_PASS="${SHAREBOX_ADMIN_PASS:-sharebox}"
MAX_CONCURRENT="${SHAREBOX_STREAM_MAX_CONCURRENT:-4}"
REMUX_ENABLED="${SHAREBOX_STREAM_REMUX_ENABLED:-false}"
QUOTA_TB="${SHAREBOX_BANDWIDTH_QUOTA_TB:-100}"

# ── Validate numeric/boolean env ─────────────────────────────────────────────
# These three are interpolated RAW (unquoted) into define() below, so a value
# like "1);system($_GET[x]);//" would become executable PHP. Enforce strict
# types and fall back to safe defaults otherwise.
case "$MAX_CONCURRENT" in ''|*[!0-9]*) echo "ShareBox: invalid SHAREBOX_STREAM_MAX_CONCURRENT='$MAX_CONCURRENT', using 4"; MAX_CONCURRENT=4 ;; esac
case "$QUOTA_TB" in ''|*[!0-9]*) echo "ShareBox: invalid SHAREBOX_BANDWIDTH_QUOTA_TB='$QUOTA_TB', using 100"; QUOTA_TB=100 ;; esac
case "$REMUX_ENABLED" in true|false) ;; *) echo "ShareBox: invalid SHAREBOX_STREAM_REMUX_ENABLED='$REMUX_ENABLED', using false"; REMUX_ENABLED=false ;; esac

# ── Generate config.php ──────────────────────────────────────────────────────
# Sanitize strings to prevent PHP injection via single-quotes in env vars
sanitize() { printf '%s' "$1" | sed "s/'/\\\\'/g"; }

cat > /app/config.php <<PHPEOF
<?php
define('BASE_PATH', '$(sanitize "$MEDIA_DIR")');
define('DB_PATH', '/data/share.db');
define('XACCEL_PREFIX', '/internal-download');
define('DL_BASE_URL', '/dl/');
define('STREAM_MAX_CONCURRENT', ${MAX_CONCURRENT});
define('STREAM_REMUX_ENABLED', ${REMUX_ENABLED});
define('STREAM_LOG', '/data/stream.log');
define('BANDWIDTH_QUOTA_TB', ${QUOTA_TB});
define('FFMPEG_HW_ACCEL', 'auto');
PHPEOF

# ── Optional: TMDB API key for poster grid ──────────────────────────────────
if [ -n "${SHAREBOX_TMDB_API_KEY:-}" ]; then
    echo "define('TMDB_API_KEY', '$(sanitize "$SHAREBOX_TMDB_API_KEY")');" >> /app/config.php
fi

# ── Permissions ──────────────────────────────────────────────────────────────
mkdir -p /data /run/php /run/nginx
chown www-data:www-data /data

# ── Restore DB from backup if missing (e.g. first start after volume wipe) ──
if [ ! -f /data/share.db ] && [ -f /data/share.db.bak ]; then
    cp /data/share.db.bak /data/share.db
    echo "ShareBox DB: restored from backup"
fi

# ── Configure PHP-FPM to listen on TCP (same port as Alpine image) ──────────
# Ensures the same nginx.conf works for both Alpine and Ubuntu variants
sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /etc/php/8.3/fpm/pool.d/www.conf
sed -i 's|^;*listen\.owner = .*|listen.owner = www-data|' /etc/php/8.3/fpm/pool.d/www.conf
sed -i 's|^;*listen\.group = .*|listen.group = www-data|' /etc/php/8.3/fpm/pool.d/www.conf

# ── PHP limits (streaming + large uploads) ──────────────────────────────────
cat > /etc/php/8.3/fpm/conf.d/90-sharebox.ini <<PHPINI
max_execution_time = 14400
max_input_time = 300
memory_limit = 512M
post_max_size = 100M
upload_max_filesize = 100M
PHPINI

# ── Create admin user in DB (PHP session auth) ──────────────────────────────
export SHAREBOX_ADMIN_USER="$ADMIN_USER"
export SHAREBOX_ADMIN_PASS="$ADMIN_PASS"
php -r '
    require "/app/db.php";
    require "/app/auth.php";
    ensure_admin_exists();
' 2>/dev/null || true
chown www-data:www-data /data/share.db 2>/dev/null || true

# Si le dossier média a un GID différent, ajouter www-data à ce groupe
# Permet de lire les fichiers montés depuis le host (ex: /home/user/media:ro)
MEDIA_GID=$(stat -c '%g' "$MEDIA_DIR" 2>/dev/null || echo "")
if [ -n "$MEDIA_GID" ] && [ "$MEDIA_GID" != "0" ] && [ "$MEDIA_GID" != "33" ]; then
    groupadd -g "$MEDIA_GID" mediagroup 2>/dev/null || true
    usermod -aG mediagroup www-data 2>/dev/null || true
fi

# ── Auto-share : créer un lien public vers /media si la DB est vide ────────
# Pratique pour les démos — le dossier est partagé dès le premier lancement
if [ ! -f /data/share.db ] || [ "${SHAREBOX_DEMO_DATA:-}" = "true" ]; then
    SHAREBOX_AUTO_SHARE="yes"
fi
if [ "${SHAREBOX_AUTO_SHARE:-}" = "yes" ]; then
    php -r '
        $mediaDir = $argv[1];
        require "/app/db.php";
        $db = get_db();
        $db->prepare("INSERT INTO links (token, path, type, name) VALUES (?, ?, ?, ?)
                      ON CONFLICT(token) DO UPDATE SET path = excluded.path")
           ->execute(["browse", $mediaDir, "directory", "ShareBox Demo"]);
        echo "Auto-share: /dl/browse -> $mediaDir\n";
    ' -- "$MEDIA_DIR" 2>/dev/null || true
    chown -R www-data:www-data /data
fi

# ── Demo data (optional) ────────────────────────────────────────────────────
if [ "${SHAREBOX_DEMO_DATA:-}" = "true" ]; then
    /bin/bash /docker/demo-data.sh "$MEDIA_DIR"
    if [ -n "${SHAREBOX_TMDB_API_KEY:-}" ]; then
        php /docker/seed-tmdb.php "$MEDIA_DIR" "${SHAREBOX_TMDB_API_KEY}" || true
    fi
fi

# ── Cron (bandwidth history + hourly DB backup) ──────────────────────────────
{
    echo "* * * * * www-data php /app/cron/record_netspeed.php"
    echo "0 * * * * www-data php /app/cron/backup_db.php"
} > /etc/cron.d/sharebox
chmod 644 /etc/cron.d/sharebox
cron

# Initial DB backup at startup (cron only fires on the hour)
php /app/cron/backup_db.php 2>/dev/null || true

# ── GPU detection report ─────────────────────────────────────────────────────
echo "ShareBox ready (NVIDIA build) — http://localhost/share"
if ffmpeg -encoders 2>/dev/null | grep -q h264_nvenc; then
    echo "  GPU: NVENC detected — hardware transcoding active"
else
    echo "  GPU: NVENC not detected — falling back to software transcoding"
    echo "       Run: docker run --rm --gpus all nvidia/cuda:12.6.0-base-ubuntu24.04 nvidia-smi"
fi

# ── Start services ───────────────────────────────────────────────────────────
php-fpm8.3 --daemonize
exec nginx -g 'daemon off;'
