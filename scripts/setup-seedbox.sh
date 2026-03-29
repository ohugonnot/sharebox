#!/bin/bash
# ShareBox Seedbox Setup
# Installs system-level configs: crons, scripts, sudoers, tmpfiles
# Run as root from the sharebox directory.

set -e
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SHAREBOX_DIR="$(dirname "$SCRIPT_DIR")"

if [ "$EUID" -ne 0 ]; then
    echo "Run as root."
    exit 1
fi

echo "=== ShareBox Seedbox Setup ==="
echo "  Project: $SHAREBOX_DIR"
echo ""

# 1. Install seedbox scripts
echo "[1/5] Installing seedbox scripts..."
cp "$SCRIPT_DIR/seedbox-adduser" /usr/local/bin/seedbox-adduser
cp "$SCRIPT_DIR/seedbox-deluser" /usr/local/bin/seedbox-deluser
chmod +x /usr/local/bin/seedbox-adduser /usr/local/bin/seedbox-deluser

# 2. Install sudoers
echo "[2/5] Installing sudoers..."
cp "$SCRIPT_DIR/sudoers-sharebox" /etc/sudoers.d/sharebox
chmod 440 /etc/sudoers.d/sharebox
visudo -c

# 3. Install tmpfiles (rtorrent socket dir)
echo "[3/5] Installing tmpfiles..."
cp "$SCRIPT_DIR/rtorrent.tmpfiles" /etc/tmpfiles.d/rtorrent.conf
mkdir -p /var/run/rtorrent
groupadd -f seedbox
chown root:seedbox /var/run/rtorrent
chmod 775 /var/run/rtorrent

# 4. Install cron jobs
echo "[4/5] Installing cron jobs..."
# Update paths in cron file to match actual install location
sed "s|/var/www/sharebox|${SHAREBOX_DIR}|g" "$SHAREBOX_DIR/cron/sharebox.cron" > /etc/cron.d/sharebox
chmod 644 /etc/cron.d/sharebox

# 5. Set permissions
echo "[5/5] Setting permissions..."
chown -R www-data:www-data "$SHAREBOX_DIR/data" 2>/dev/null || mkdir -p "$SHAREBOX_DIR/data" && chown www-data:www-data "$SHAREBOX_DIR/data"
touch /etc/nginx/.htpasswd
chown root:www-data /etc/nginx/.htpasswd
chmod 640 /etc/nginx/.htpasswd

echo ""
echo "=== Done ==="
echo "  Cron jobs: cat /etc/cron.d/sharebox"
echo "  Scripts:   seedbox-adduser / seedbox-deluser"
echo "  Sudoers:   /etc/sudoers.d/sharebox"
