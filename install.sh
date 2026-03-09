#!/usr/bin/env bash
# ============================================================================
# ShareBox — Automated Installer
# Self-hosted file sharing with built-in video streaming
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/YOURUSER/sharebox/main/install.sh | sudo bash
#   # or
#   wget -qO- https://raw.githubusercontent.com/YOURUSER/sharebox/main/install.sh | sudo bash
# ============================================================================

set -euo pipefail

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

info()  { echo -e "${CYAN}[*]${NC} $*"; }
ok()    { echo -e "${GREEN}[+]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
err()   { echo -e "${RED}[x]${NC} $*"; exit 1; }

# --- Root check ---
[[ $EUID -eq 0 ]] || err "This installer must be run as root (use sudo)."

# --- OS detection ---
if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS_ID="${ID,,}"
    OS_VERSION="${VERSION_ID:-}"
else
    err "Cannot detect OS. Only Debian and Ubuntu are supported."
fi

case "$OS_ID" in
    debian|ubuntu) ;;
    *) err "Unsupported OS: $OS_ID. Only Debian and Ubuntu are supported." ;;
esac

# --- Banner ---
echo ""
echo -e "${BOLD}${YELLOW}"
echo "  ____  _                     ____            "
echo " / ___|| |__   __ _ _ __ ___| __ )  _____  __"
echo " \___ \| '_ \ / _\` | '__/ _ \  _ \ / _ \ \/ /"
echo "  ___) | | | | (_| | | |  __/ |_) | (_) >  < "
echo " |____/|_| |_|\__,_|_|  \___|____/ \___/_/\_\\"
echo -e "${NC}"
echo -e " ${BOLD}Self-hosted file sharing & streaming${NC}"
echo ""

# --- Configuration prompts ---
INSTALL_DIR="/var/www/sharebox"
SHARE_PATH=""
ADMIN_USER=""
ADMIN_PASS=""
WEB_SERVER=""
PHP_SOCK=""

# Detect web server
if command -v nginx &>/dev/null && systemctl is-active --quiet nginx 2>/dev/null; then
    WEB_SERVER="nginx"
elif command -v apache2 &>/dev/null && systemctl is-active --quiet apache2 2>/dev/null; then
    WEB_SERVER="apache"
fi

echo -e "${BOLD}Configuration${NC}"
echo ""

# Install directory
read -rp "$(echo -e "${CYAN}Install directory${NC} [$INSTALL_DIR]: ")" input
INSTALL_DIR="${input:-$INSTALL_DIR}"

# Files directory
while [[ -z "$SHARE_PATH" ]]; do
    read -rp "$(echo -e "${CYAN}Directory to share files from${NC} (e.g. /home/user/files): ")" SHARE_PATH
    if [[ -n "$SHARE_PATH" && ! -d "$SHARE_PATH" ]]; then
        warn "Directory $SHARE_PATH does not exist."
        read -rp "Create it? [Y/n]: " create
        if [[ "${create,,}" != "n" ]]; then
            mkdir -p "$SHARE_PATH"
            ok "Created $SHARE_PATH"
        else
            SHARE_PATH=""
        fi
    fi
done
# Ensure trailing slash
SHARE_PATH="${SHARE_PATH%/}/"

# Admin credentials
while [[ -z "$ADMIN_USER" ]]; do
    read -rp "$(echo -e "${CYAN}Admin username${NC}: ")" ADMIN_USER
done
while [[ -z "$ADMIN_PASS" ]]; do
    read -srp "$(echo -e "${CYAN}Admin password${NC}: ")" ADMIN_PASS
    echo ""
done

# Web server choice
if [[ -z "$WEB_SERVER" ]]; then
    echo ""
    echo "Which web server do you want to use?"
    echo "  1) nginx (recommended)"
    echo "  2) Apache"
    read -rp "Choice [1]: " ws_choice
    case "${ws_choice:-1}" in
        2) WEB_SERVER="apache" ;;
        *) WEB_SERVER="nginx" ;;
    esac
fi

echo ""
info "Web server: $WEB_SERVER"
info "Install dir: $INSTALL_DIR"
info "Share path: $SHARE_PATH"
info "Admin user: $ADMIN_USER"
echo ""
read -rp "Continue? [Y/n]: " confirm
[[ "${confirm,,}" == "n" ]] && { echo "Aborted."; exit 0; }

echo ""

# ============================================================================
# STEP 1: Install dependencies
# ============================================================================
info "Installing dependencies..."
apt-get update -qq

PACKAGES=(git ffmpeg)

if [[ "$WEB_SERVER" == "nginx" ]]; then
    PACKAGES+=(nginx)
else
    PACKAGES+=(apache2)
fi

# Find PHP version (prefer 8.2, fallback to 8.1, 8.3)
PHP_VER=""
for v in 8.2 8.3 8.1; do
    if apt-cache show "php${v}-fpm" &>/dev/null; then
        PHP_VER="$v"
        break
    fi
done

if [[ -z "$PHP_VER" ]]; then
    err "No supported PHP version found (8.1/8.2/8.3). Install PHP manually first."
fi

PACKAGES+=("php${PHP_VER}-fpm" "php${PHP_VER}-sqlite3" "php${PHP_VER}-mbstring")

# apache2-utils for htpasswd
if ! command -v htpasswd &>/dev/null; then
    PACKAGES+=(apache2-utils)
fi

apt-get install -y -qq "${PACKAGES[@]}" > /dev/null 2>&1
ok "Dependencies installed (PHP $PHP_VER, ffmpeg, $WEB_SERVER)"

# Detect PHP-FPM socket
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
if [[ ! -S "$PHP_SOCK" ]]; then
    systemctl start "php${PHP_VER}-fpm" 2>/dev/null || true
    sleep 1
fi

# ============================================================================
# STEP 2: Download ShareBox
# ============================================================================
info "Installing ShareBox..."

if [[ -d "$INSTALL_DIR/.git" ]]; then
    info "Existing install found, pulling updates..."
    cd "$INSTALL_DIR"
    git pull -q
else
    if [[ -d "$INSTALL_DIR" ]]; then
        warn "$INSTALL_DIR already exists (not a git repo). Backing up..."
        mv "$INSTALL_DIR" "${INSTALL_DIR}.bak.$(date +%s)"
    fi
    git clone -q https://github.com/YOURUSER/sharebox.git "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"
ok "ShareBox installed in $INSTALL_DIR"

# ============================================================================
# STEP 3: Configuration
# ============================================================================
info "Configuring..."

# Determine XACCEL_PREFIX based on web server
if [[ "$WEB_SERVER" == "nginx" ]]; then
    XACCEL="'/internal-download'"
else
    XACCEL="''"
fi

cat > "$INSTALL_DIR/config.php" <<PHPEOF
<?php
define('BASE_PATH', '${SHARE_PATH}');
define('DB_PATH', __DIR__ . '/data/share.db');
define('XACCEL_PREFIX', ${XACCEL});
define('DL_BASE_URL', '/dl/');
PHPEOF

# Create data directory
mkdir -p "$INSTALL_DIR/data"

# Detect web server user
WEB_USER="www-data"
if id "nginx" &>/dev/null; then
    WEB_USER="nginx"
fi

chown -R "$WEB_USER:$WEB_USER" "$INSTALL_DIR/data"
chmod 750 "$INSTALL_DIR/data"

ok "Configuration written"

# ============================================================================
# STEP 4: Admin credentials (htpasswd)
# ============================================================================
info "Creating admin credentials..."
HTPASSWD_FILE="/etc/sharebox.htpasswd"
htpasswd -cb "$HTPASSWD_FILE" "$ADMIN_USER" "$ADMIN_PASS" 2>/dev/null
chmod 640 "$HTPASSWD_FILE"
chown root:"$WEB_USER" "$HTPASSWD_FILE"
ok "Admin user '$ADMIN_USER' created"

# ============================================================================
# STEP 5: Web server configuration
# ============================================================================
info "Configuring $WEB_SERVER..."

if [[ "$WEB_SERVER" == "nginx" ]]; then

    # Find the main server block config
    NGINX_CONF="/etc/nginx/sites-available/sharebox.conf"
    NGINX_APPS_DIR="/etc/nginx/apps"

    # Check if using apps/ include pattern (swizzin-style)
    if [[ -d "$NGINX_APPS_DIR" ]] && grep -rq "include.*apps" /etc/nginx/sites-enabled/ 2>/dev/null; then
        NGINX_CONF="$NGINX_APPS_DIR/sharebox.conf"
        info "Detected apps/ include pattern, writing to $NGINX_CONF"
    fi

    cat > "$NGINX_CONF" <<NGINXEOF
# ShareBox — auto-generated by installer

# Favicon (public, no auth)
location = /share/favicon.svg {
    auth_basic off;
    alias ${INSTALL_DIR}/favicon.svg;
}

# Admin panel (protected)
location ^~ /share {
    auth_basic "ShareBox";
    auth_basic_user_file ${HTPASSWD_FILE};

    location ^~ /share/data {
        deny all;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$request_filename;
    }

    index index.php;
    try_files \$uri \$uri/ /share/index.php?\$query_string;
}

# Public download URLs
location ~ "^/dl/([a-z0-9][a-z0-9-]{1,50})\$" {
    include fastcgi_params;
    fastcgi_pass unix:${PHP_SOCK};
    fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/download.php;
    fastcgi_param QUERY_STRING token=\$1&\$args;
}

# Internal file serving (X-Accel-Redirect)
location /internal-download/ {
    internal;
    alias /;
}
NGINXEOF

    # If not using apps/ pattern, create symlink
    if [[ "$NGINX_CONF" == "/etc/nginx/sites-available/sharebox.conf" ]]; then
        ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/sharebox.conf
    fi

    # Also need to ensure nginx root includes /share path — add symlink
    if [[ ! -e "/srv/share" ]] && [[ "$INSTALL_DIR" != "/srv/share" ]]; then
        ln -sf "$INSTALL_DIR" /srv/share 2>/dev/null || true
    fi

    nginx -t 2>/dev/null || err "Nginx configuration test failed. Check $NGINX_CONF"
    systemctl reload nginx
    ok "Nginx configured and reloaded"

else
    # Apache
    APACHE_CONF="/etc/apache2/sites-available/sharebox.conf"

    cat > "$APACHE_CONF" <<APACHEEOF
# ShareBox — auto-generated by installer

Alias /share ${INSTALL_DIR}
Alias /dl ${INSTALL_DIR}

<Directory ${INSTALL_DIR}>
    AllowOverride All
    Require all granted

    AuthType Basic
    AuthName "ShareBox"
    AuthUserFile ${HTPASSWD_FILE}
    Require valid-user
</Directory>

# Public download URLs — no auth
<LocationMatch "^/dl/">
    Require all granted
</LocationMatch>
APACHEEOF

    a2enmod rewrite 2>/dev/null
    a2ensite sharebox 2>/dev/null
    apache2ctl -t 2>/dev/null || err "Apache configuration test failed."
    systemctl reload apache2
    ok "Apache configured and reloaded"
fi

# ============================================================================
# DONE
# ============================================================================
echo ""
echo -e "${GREEN}${BOLD}============================================${NC}"
echo -e "${GREEN}${BOLD}  ShareBox installed successfully!${NC}"
echo -e "${GREEN}${BOLD}============================================${NC}"
echo ""
echo -e "  ${BOLD}Admin panel:${NC}  https://your-server/share"
echo -e "  ${BOLD}Username:${NC}     $ADMIN_USER"
echo -e "  ${BOLD}Password:${NC}     (as entered)"
echo ""
echo -e "  ${BOLD}Install dir:${NC}  $INSTALL_DIR"
echo -e "  ${BOLD}Config:${NC}       $INSTALL_DIR/config.php"
echo -e "  ${BOLD}Sharing from:${NC} $SHARE_PATH"
echo ""
if [[ "$WEB_SERVER" == "nginx" ]]; then
    echo -e "  ${BOLD}Nginx conf:${NC}   $NGINX_CONF"
else
    echo -e "  ${BOLD}Apache conf:${NC} $APACHE_CONF"
fi
echo -e "  ${BOLD}htpasswd:${NC}     $HTPASSWD_FILE"
echo ""
echo -e "  ${YELLOW}Tip:${NC} Set up HTTPS with Let's Encrypt:"
echo -e "       sudo apt install certbot python3-certbot-${WEB_SERVER}"
echo -e "       sudo certbot --${WEB_SERVER}"
echo ""
