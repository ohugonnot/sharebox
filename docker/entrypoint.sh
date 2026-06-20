#!/bin/sh
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
PHPEOF

# ── Optional: TMDB API key for poster grid ──────────────────────────────────
if [ -n "${SHAREBOX_TMDB_API_KEY:-}" ]; then
    echo "define('TMDB_API_KEY', '$(sanitize "$SHAREBOX_TMDB_API_KEY")');" >> /app/config.php
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
if [ ! -f /data/share.db ] || [ "${SHAREBOX_DEMO_DATA:-}" = "true" ]; then
    SHAREBOX_AUTO_SHARE="yes"
fi
if [ "${SHAREBOX_AUTO_SHARE:-}" = "yes" ]; then
    php -r '
        $mediaDir = $argv[1];
        require "/app/db.php";
        $db = get_db();
        // Upsert: create or fix the browse link
        $db->prepare("INSERT INTO links (token, path, type, name) VALUES (?, ?, ?, ?)
                      ON CONFLICT(token) DO UPDATE SET path = excluded.path")
           ->execute(["browse", $mediaDir, "directory", "ShareBox Demo"]);
        echo "Auto-share: /dl/browse -> $mediaDir\n";
    ' -- "$MEDIA_DIR" 2>/dev/null || true
    chown -R www-data:www-data /data
fi

# ── Demo data (optional) ────────────────────────────────────────────────────
# Crée uniquement les médias d'exemple. Le matching des affiches est délégué au VRAI
# worker (lancé en tâche de fond après le démarrage des services) : la démo emprunte
# ainsi le même chemin de code qu'une install réelle, au lieu d'un seed en dur.
if [ "${SHAREBOX_DEMO_DATA:-}" = "true" ]; then
    /bin/sh /docker/demo-data.sh "$MEDIA_DIR"
fi

# ── PHP limits (streaming + large uploads) ──────────────────────────────────
cat > /usr/local/etc/php/conf.d/sharebox.ini <<PHPINI
max_execution_time = 14400
max_input_time = 300
memory_limit = 512M
post_max_size = 100M
upload_max_filesize = 100M
PHPINI

# ── Cron (bandwidth history + hourly DB backup) ──────────────────────────────
{
    echo "* * * * * php /app/cron/record_netspeed.php"
    echo "0 * * * * php /app/cron/backup_db.php"
    # Worker affiches TMDB : découvre les nouveaux dossiers et matche via TMDB.
    # Absent par défaut de l'image jusqu'ici → un install Docker n'avait jamais de
    # cron poster (seul le ?posters au browse alimentait la grille).
    [ -n "${SHAREBOX_TMDB_API_KEY:-}" ] && echo "*/10 * * * * php /app/tools/tmdb-worker.php >> /data/tmdb-worker.log 2>&1"
} > /etc/crontabs/www-data
crond -b -l 8

# Initial DB backup at startup (cron only fires on the hour)
php /app/cron/backup_db.php 2>/dev/null || true

# Root-side PHP above (admin bootstrap + backup) may have created share.db-wal /
# -shm owned by root; php-fpm runs as www-data and must write them (WAL needs -shm
# writable even for reads). Re-own before starting services.
chown www-data:www-data /data/share.db /data/share.db-wal /data/share.db-shm /data/share.db.bak 2>/dev/null || true

# ── Start services ───────────────────────────────────────────────────────────
php-fpm -D

# ── Bootstrap démo : peuple les affiches via le vrai worker ──────────────────
# En tâche de fond (www-data, propriétaire de la DB) pour ne pas bloquer le démarrage —
# les affiches apparaissent en ~1 min, exactement comme le premier passage de cron
# sur une install fraîche. Pas de seed en dur : même chemin de code que la prod.
if [ "${SHAREBOX_DEMO_DATA:-}" = "true" ] && [ -n "${SHAREBOX_TMDB_API_KEY:-}" ]; then
    # $MEDIA_DIR passé via une variable exportée (pas d'interpolation dans la chaîne -c) →
    # aucune injection shell possible même si l'env contient des guillemets/backticks.
    export _DEMO_MEDIA_DIR="$MEDIA_DIR"
    su -s /bin/sh www-data -c 'php /docker/demo-bootstrap.php "$_DEMO_MEDIA_DIR" >> /data/tmdb-worker.log 2>&1' &
fi

echo "ShareBox ready — admin: http://localhost/share"
exec nginx -g 'daemon off;'
