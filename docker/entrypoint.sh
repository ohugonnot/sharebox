#!/bin/sh
set -e

# ── Defaults ──────────────────────────────────────────────────────────────────
MEDIA_DIR="${SHAREBOX_MEDIA_DIR:-/media/}"
ADMIN_USER="${SHAREBOX_ADMIN_USER:-admin}"
ADMIN_PASS="${SHAREBOX_ADMIN_PASS:-sharebox}"
MAX_CONCURRENT="${SHAREBOX_STREAM_MAX_CONCURRENT:-4}"
REMUX_ENABLED="${SHAREBOX_STREAM_REMUX_ENABLED:-false}"
QUOTA_TB="${SHAREBOX_BANDWIDTH_QUOTA_TB:-100}"

# ── Generate config.php ──────────────────────────────────────────────────────
cat > /app/config.php <<PHPEOF
<?php
define('BASE_PATH', '${MEDIA_DIR}');
define('DB_PATH', '/data/share.db');
define('XACCEL_PREFIX', '/internal-download');
define('DL_BASE_URL', '/dl/');
define('STREAM_MAX_CONCURRENT', ${MAX_CONCURRENT});
define('STREAM_REMUX_ENABLED', ${REMUX_ENABLED});
define('STREAM_LOG', '/data/stream.log');
define('BANDWIDTH_QUOTA_TB', ${QUOTA_TB});
PHPEOF

# ── Optional: TMDB API key for poster grid ──────────────────────────────────
if [ -n "${SHAREBOX_TMDB_API_KEY:-}" ]; then
    echo "define('TMDB_API_KEY', '${SHAREBOX_TMDB_API_KEY}');" >> /app/config.php
fi

# ── Permissions ──────────────────────────────────────────────────────────────
mkdir -p /data /run/nginx
chown www-data:www-data /data

# ── Restore DB from backup if missing (e.g. first start after volume wipe) ──
if [ ! -f /data/share.db ] && [ -f /data/share.db.bak ]; then
    cp /data/share.db.bak /data/share.db
    echo "ShareBox DB: restored from backup"
fi

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
if [ -n "$MEDIA_GID" ] && [ "$MEDIA_GID" != "0" ] && [ "$MEDIA_GID" != "82" ]; then
    addgroup -g "$MEDIA_GID" mediagroup 2>/dev/null || true
    addgroup www-data mediagroup 2>/dev/null || true
fi

# ── Auto-share : créer un lien public vers /media si la DB est vide ────────
# Pratique pour les démos — le dossier est partagé dès le premier lancement
if [ ! -f /data/share.db ]; then
    SHAREBOX_AUTO_SHARE="yes"
fi
if [ "${SHAREBOX_AUTO_SHARE:-}" = "yes" ]; then
    php -r '
        require "/app/db.php";
        $db = get_db();
        $c = $db->query("SELECT COUNT(*) FROM links")->fetchColumn();
        if ($c == 0) {
            $db->prepare("INSERT INTO links (token, path, type, name) VALUES (?, ?, ?, ?)")
               ->execute(["browse", "$MEDIA_DIR", "directory", "ShareBox"]);
            echo "Auto-share: /dl/browse\n";
        }
    ' 2>/dev/null || true
    chown -R www-data:www-data /data
fi

# ── Demo data (optional) ────────────────────────────────────────────────────
if [ "${SHAREBOX_DEMO_DATA:-}" = "true" ]; then
    /bin/sh /docker/demo-data.sh
fi

# ── PHP limits (streaming + large uploads) ──────────────────────────────────
cat > /usr/local/etc/php/conf.d/sharebox.ini <<PHPINI
max_execution_time = 14400
max_input_time = 300
memory_limit = 512M
post_max_size = 100M
upload_max_filesize = 100M
PHPINI

# ── Cron (bandwidth history) ─────────────────────────────────────────────────
echo "* * * * * php /app/cron/record_netspeed.php" > /etc/crontabs/www-data
crond -b -l 8

# ── Start services ───────────────────────────────────────────────────────────
php-fpm -D
echo "ShareBox ready — admin: http://localhost/share"
exec nginx -g 'daemon off;'
