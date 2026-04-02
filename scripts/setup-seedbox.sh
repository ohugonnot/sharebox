#!/bin/bash
# ShareBox Seedbox Setup
# Installe et lie toute l'infrastructure seedbox depuis sharebox.
# Usage : bash setup-seedbox.sh [--deploy-rtconfig]
#
# Variables surchargeables :
#   SEEDBOX_IFACE    interface reseau NIC (defaut: enp59s0f0)
#   SEEDBOX_USER     utilisateur rtorrent principal (defaut: ropixv2)
#   DEPLOY_RTCONFIG  generer .rtorrent.rc depuis le template (opt-in)

set -e
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SHAREBOX_DIR="$(dirname "$SCRIPT_DIR")"
SEEDBOX_DIR="${SHAREBOX_DIR}/scripts/seedbox"
CONFIGS_DIR="${SHAREBOX_DIR}/scripts/configs"
LOG_DIR="${SHAREBOX_DIR}/data/logs"
STATE_DIR="${SHAREBOX_DIR}/data"

SEEDBOX_IFACE="${SEEDBOX_IFACE:-enp59s0f0}"
SEEDBOX_USER="${SEEDBOX_USER:-ropixv2}"
RTORRENT_SOCK="/var/run/rtorrent/${SEEDBOX_USER}.sock"

if [ "$EUID" -ne 0 ]; then
    echo "Run as root."
    exit 1
fi

echo "=== ShareBox Seedbox Setup ==="
echo "  Project:  $SHAREBOX_DIR"
echo "  User:     $SEEDBOX_USER"
echo "  Iface:    $SEEDBOX_IFACE"
echo ""

# 1. Creer data/logs + fichiers de log
echo "[1/9] Initialisation des logs..."
mkdir -p "$LOG_DIR"
for f in kick-peers.log stop-public.log dead-trackers.log seedbox-monitor.log rtorrent-${SEEDBOX_USER}.log; do
    touch "${LOG_DIR}/${f}"
done
echo "  logs dans $LOG_DIR"

# 2. Symlinks /usr/local/bin -> scripts/seedbox/
echo "[2/9] Creation des symlinks /usr/local/bin..."
for script in kick-slow-peers stop-public-torrents check-dead-trackers rtorrent-pause-all rtorrent-gradual-resume seedbox-monitor; do
    ln -sf "${SEEDBOX_DIR}/${script}" "/usr/local/bin/${script}"
    chmod +x "${SEEDBOX_DIR}/${script}"
    echo "  /usr/local/bin/${script} -> ${SEEDBOX_DIR}/${script}"
done
chmod +x "${SEEDBOX_DIR}/rtorrent_scgi.py"

# 3. Scripts admin (seedbox-adduser / seedbox-deluser)
echo "[3/9] Installation des scripts admin..."
cp "$SCRIPT_DIR/seedbox-adduser" /usr/local/bin/seedbox-adduser
cp "$SCRIPT_DIR/seedbox-deluser" /usr/local/bin/seedbox-deluser 2>/dev/null || true
chmod +x /usr/local/bin/seedbox-adduser
[ -f /usr/local/bin/seedbox-deluser ] && chmod +x /usr/local/bin/seedbox-deluser

# 4. Sudoers + tmpfiles
echo "[4/9] Sudoers et tmpfiles..."
if [ -f "$SCRIPT_DIR/sudoers-sharebox" ]; then
    cp "$SCRIPT_DIR/sudoers-sharebox" /etc/sudoers.d/sharebox
    chmod 440 /etc/sudoers.d/sharebox
    visudo -c -q
fi
cp "$SCRIPT_DIR/rtorrent.tmpfiles" /etc/tmpfiles.d/rtorrent.conf
mkdir -p /var/run/rtorrent
groupadd -f seedbox
chown root:seedbox /var/run/rtorrent
chmod 775 /var/run/rtorrent

# 5. Configs systeme (sysctl, udev, irq-affinity)
echo "[5/9] Deploiement des configs systeme..."
cp "${CONFIGS_DIR}/99-seedbox.conf" /etc/sysctl.d/99-seedbox.conf
sysctl -p /etc/sysctl.d/99-seedbox.conf -q

cp "${CONFIGS_DIR}/60-readahead.rules" /etc/udev/rules.d/60-readahead.rules
udevadm control --reload-rules

sed "s|\${SEEDBOX_IFACE:-enp59s0f0}|${SEEDBOX_IFACE}|g" \
    "${CONFIGS_DIR}/irq-affinity" > /etc/network/if-up.d/irq-affinity
chmod +x /etc/network/if-up.d/irq-affinity
# Appliquer immediatement si l'interface est up
if ip link show "$SEEDBOX_IFACE" &>/dev/null; then
    bash /etc/network/if-up.d/irq-affinity || true
fi
echo "  sysctl, udev, irq-affinity deployes"

# 6. Migrer trackers-state.json si besoin
echo "[6/9] Migration de l'etat des trackers..."
OLD_STATE="/var/lib/ropixv2-trackers.json"
NEW_STATE="${STATE_DIR}/trackers-state.json"
if [ -f "$OLD_STATE" ] && [ ! -f "$NEW_STATE" ]; then
    cp "$OLD_STATE" "$NEW_STATE"
    echo "  migre depuis $OLD_STATE"
elif [ -f "$NEW_STATE" ]; then
    echo "  deja en place : $NEW_STATE"
else
    echo "  aucun etat existant (premier deploiement)"
fi

# 7. Cron seedbox (atomique : creer avant supprimer l'ancien)
echo "[7/9] Deploiement du cron seedbox..."
cp "${SHAREBOX_DIR}/cron/seedbox.cron" /etc/cron.d/seedbox
chmod 644 /etc/cron.d/seedbox
if [ -f /etc/cron.d/ropixv2-torrent ]; then
    rm /etc/cron.d/ropixv2-torrent
    echo "  /etc/cron.d/ropixv2-torrent supprime"
fi
echo "  /etc/cron.d/seedbox installe"

# Cron sharebox (web app)
sed "s|/var/www/sharebox|${SHAREBOX_DIR}|g" "${SHAREBOX_DIR}/cron/sharebox.cron" > /etc/cron.d/sharebox
chmod 644 /etc/cron.d/sharebox

# 8. seedbox-monitor.service
echo "[8/9] Deploiement de seedbox-monitor.service..."
sed \
    -e "s|{{LOG_DIR}}|${LOG_DIR}|g" \
    -e "s|{{IFACE}}|${SEEDBOX_IFACE}|g" \
    -e "s|{{RTORRENT_SOCK}}|${RTORRENT_SOCK}|g" \
    -e "s|{{SEEDBOX_DIR}}|${SEEDBOX_DIR}|g" \
    "${CONFIGS_DIR}/seedbox-monitor.service" > /etc/systemd/system/seedbox-monitor.service
systemctl daemon-reload
systemctl enable seedbox-monitor
systemctl restart seedbox-monitor
echo "  seedbox-monitor.service deploye et demarre"

# 9. Optionnel : generer .rtorrent.rc depuis le template
if [ "${DEPLOY_RTCONFIG:-0}" = "1" ]; then
    echo "[9/9] Generation de .rtorrent.rc depuis le template..."
    USER_DIR="/home/storage/users/${SEEDBOX_USER}"
    PEER_PORT=$(grep -oP 'network.port_range.set = \K[0-9]+' "${USER_DIR}/.rtorrent.rc" 2>/dev/null || echo 65066)
    sed \
        -e "s|{{USERNAME}}|${SEEDBOX_USER}|g" \
        -e "s|{{USER_DIR}}|${USER_DIR}|g" \
        -e "s|{{PEER_PORT}}|${PEER_PORT}|g" \
        -e "s|{{RTORRENT_SOCK}}|${RTORRENT_SOCK}|g" \
        -e "s|{{LOG_DIR}}|${LOG_DIR}|g" \
        "${CONFIGS_DIR}/rtorrent.rc.template" > "${USER_DIR}/.rtorrent.rc"
    chown "${SEEDBOX_USER}:${SEEDBOX_USER}" "${USER_DIR}/.rtorrent.rc"
    echo "  .rtorrent.rc genere pour ${SEEDBOX_USER}"
else
    echo "[9/9] .rtorrent.rc inchange (passer DEPLOY_RTCONFIG=1 pour regenerer)"
fi

# Permissions data/ — exclure les logs rtorrent (owned par le user seedbox)
find "${SHAREBOX_DIR}/data" -not -name "rtorrent-*.log" -exec chown www-data:www-data {} + 2>/dev/null || true

echo ""
echo "=== Done ==="
echo "  Logs       : $LOG_DIR"
echo "  Scripts    : $SEEDBOX_DIR"
echo "  Cron       : /etc/cron.d/seedbox"
echo "  Monitor    : systemctl status seedbox-monitor"
echo ""
echo "  Verification :"
echo "    ls -la /usr/local/bin/kick-slow-peers"
echo "    tail -f ${LOG_DIR}/kick-peers.log"
echo ""
echo "  Sur un nouveau serveur, surcharger :"
echo "    SEEDBOX_IFACE=eth0 bash setup-seedbox.sh"
