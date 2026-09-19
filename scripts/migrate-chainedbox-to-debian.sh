#!/usr/bin/env bash
# ==============================================================================
# Migration Script: Chainedbox (10.0.0.100) -> Debian Wyse 5070 (10.0.0.99)
# https://github.com/hungngit2/home-network
# ==============================================================================
# Safely migrates all service data and configurations from /mnt/appsrv on
# Chainedbox to /appsrv on the new Debian server.
# ==============================================================================

set -euo pipefail

BOLD="\033[1m"
GREEN="\033[0;32m"
BLUE="\033[0;34m"
YELLOW="\033[1;33m"
RED="\033[0;31m"
CYAN="\033[0;36m"
NC="\033[0m"

log_info() { echo -e "${BLUE}[INFO]${NC} $*"; }
log_succ() { echo -e "${GREEN}[SUCCESS]${NC} $*"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_err()  { echo -e "${RED}[ERROR]${NC} $*"; }
log_head() { echo -e "\n${BOLD}${CYAN}=== $* ===${NC}"; }

SRC_HOST="${SRC_HOST:-10.0.0.100}"
DST_HOST="${DST_HOST:-10.0.0.99}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_hungnguyen}"
SSH_SRC="ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no root@${SRC_HOST}"
SSH_DST="ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no root@${DST_HOST}"

log_head "Step 1: Validating Connectivity & Prerequisites"

if ! ${SSH_SRC} "echo ok" >/dev/null 2>&1; then
    log_err "Cannot connect to source host ${SRC_HOST} as root via SSH."
    exit 1
fi
log_succ "Connected to source: ${SRC_HOST}"

if ! ${SSH_DST} "echo ok" >/dev/null 2>&1; then
    # Try hungnguyen with sudo
    if ssh -i "${SSH_KEY}" -o StrictHostKeyChecking=no "hungnguyen@${DST_HOST}" "sudo -n true" >/dev/null 2>&1; then
        SSH_DST="ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no hungnguyen@${DST_HOST} sudo"
        log_succ "Connected to target: ${DST_HOST} (via sudo)"
    else
        log_err "Cannot connect to target host ${DST_HOST} as root (or hungnguyen with sudo)."
        exit 1
    fi
else
    log_succ "Connected to target: ${DST_HOST} (as root)"
fi

log_head "Step 2: Preparing Target Base Directories on ${DST_HOST}"
${SSH_DST} mkdir -p \
    /appsrv/adguard-home \
    /appsrv/aria2/.aria2 \
    /appsrv/docker \
    /appsrv/jellyfin/config \
    /appsrv/jellyfin/log \
    /appsrv/jellyfin/cache \
    /appsrv/jellyfin/tmp \
    /appsrv/jellyfin/web \
    /appsrv/jellyfin/var-lib \
    /appsrv/nginx/log \
    /appsrv/samba \
    /appsrv/www \
    /appsrv/ytb-owntone/pipes \
    /appsrv/ytb-owntone/cache \
    /appsrv/ytb-owntone/data \
    /nasdata/apps \
    /nasdata/docs \
    /nasdata/downloads \
    /nasdata/media \
    /nasdata/share/www/certbot \
    /opt

# Compatibility symlinks
${SSH_DST} bash -c '
    [[ ! -L /mnt/appsrv && ! -d /mnt/appsrv ]] && ln -s /appsrv /mnt/appsrv || true
    [[ ! -L /mnt/nasdata && ! -d /mnt/nasdata ]] && ln -s /nasdata /mnt/nasdata || true
    [[ ! -L /opt/docker ]] && ln -s /appsrv/docker /opt/docker || true
'

# Ensure USB nasdata automount entry exists in /etc/fstab
log_info "Configuring /etc/fstab for USB disk automount (LABEL=nasdata)..."
${SSH_DST} bash -c '
    if ! grep -q "LABEL=nasdata" /etc/fstab; then
        echo "LABEL=nasdata /nasdata auto defaults,nofail 0 0" >> /etc/fstab
    fi
'

log_head "Step 3: Stopping Active Writing Services on Source (${SRC_HOST})"
log_info "Pausing Home Assistant, AdGuard Home, Jellyfin, and Aria2 on ${SRC_HOST} to ensure database consistency..."
${SSH_SRC} "systemctl stop AdGuardHome jellyfin aria2 2>/dev/null || true; docker stop homeassistant 2>/dev/null || true"

log_head "Step 4: Transferring Application State & Databases"

# Function to transfer directory via piped tar between hosts
transfer_dir() {
    local src_path="$1"
    local dst_path="$2"
    local desc="$3"
    log_info "Transferring ${desc} (${src_path} -> ${dst_path})..."
    ${SSH_SRC} "tar -czf - -C '$(dirname "${src_path}")' '$(basename "${src_path}")'" | \
        ${SSH_DST} "tar -xzf - -C '${dst_path}'"
}

# 1. AdGuard Home
transfer_dir "/mnt/appsrv/adguard-home/data" "/appsrv/adguard-home/" "AdGuard Home Query Log & Stats"
transfer_dir "/mnt/appsrv/adguard-home/AdGuardHome.yaml" "/appsrv/adguard-home/" "AdGuard Home Configuration"
${SSH_DST} sed -i "s|/mnt/appsrv/adguard-home/|/appsrv/adguard-home/|g" /appsrv/adguard-home/AdGuardHome.yaml || true

# 2. Home Assistant
${SSH_DST} mkdir -p /appsrv/docker/homeassistant
transfer_dir "/mnt/appsrv/docker/homeassistant/config" "/appsrv/docker/homeassistant/" "Home Assistant Configurations & History Database"

# 3. Jellyfin
transfer_dir "/mnt/appsrv/jellyfin/config" "/appsrv/jellyfin/" "Jellyfin System & User Configurations"
transfer_dir "/mnt/appsrv/jellyfin/var-lib" "/appsrv/jellyfin/" "Jellyfin Metadata & Library Databases"
${SSH_DST} sed -i "s|/mnt/appsrv/|/appsrv/|g" /appsrv/jellyfin/config/network.xml 2>/dev/null || true

# 4. Aria2
transfer_dir "/mnt/appsrv/aria2/.aria2" "/appsrv/aria2/" "Aria2 Session & DHT State"
transfer_dir "/mnt/appsrv/aria2/aria2.conf" "/appsrv/aria2/" "Aria2 Configuration"
${SSH_DST} sed -i "s|/mnt/appsrv/aria2|/appsrv/aria2|g" /appsrv/aria2/aria2.conf
${SSH_DST} sed -i "s|/mnt/nasdata/downloads|/nasdata/downloads|g" /appsrv/aria2/aria2.conf

# 5. Let's Encrypt SSL Certificates & Nginx
transfer_dir "/etc/letsencrypt" "/" "Let's Encrypt SSL Certificates & Renewal Keys"
transfer_dir "/mnt/appsrv/nginx/default" "/appsrv/nginx/" "Nginx Virtual Host Configuration"
${SSH_DST} sed -i "s|/mnt/appsrv/|/appsrv/|g" /appsrv/nginx/default
${SSH_DST} sed -i "s|/mnt/nasdata/|/nasdata/|g" /appsrv/nginx/default

# 6. Web Applications & Custom Tools
transfer_dir "/mnt/appsrv/www" "/appsrv/" "Web Applications (Wi-Fi Config Tool, YTB, AriaNg, JK BMS)"
transfer_dir "/mnt/appsrv/ytb-owntone/data" "/appsrv/ytb-owntone/" "YouTube OwnTone Queue & Play State"

# 7. Samba Configuration
transfer_dir "/mnt/appsrv/samba/smb.conf" "/appsrv/samba/" "Samba Configuration"
${SSH_DST} sed -i "s|/mnt/nasdata|/nasdata|g" /appsrv/samba/smb.conf

# 8. rtp2httpd IPTV Configuration & Multicast Routing
log_info "Verifying rtp2httpd configuration and multicast routing on ${DST_HOST}..."
${SSH_DST} bash -c '
    # Update external-m3u URL in rtp2httpd.conf to target host IP
    if [[ -f /etc/rtp2httpd.conf ]]; then
        sed -i "s|external-m3u = http://[0-9.]\+/iptv/|external-m3u = http://'${DST_HOST}'/iptv/|g" /etc/rtp2httpd.conf
    fi

    # Ensure multicast route (224.0.0.0/4) egresses via primary LAN interface (not VLAN10 / IoT)
    # Mikrotik downstream IGMP proxy runs on br-lan (VLAN 1), so IGMP joins must route via primary LAN interface
    PRI_IFACE=$(ip -4 route show default | head -n1 | awk "{print \$5}")
    if [[ -n "$PRI_IFACE" ]]; then
        ip route replace 224.0.0.0/4 dev "$PRI_IFACE" 2>/dev/null || true
    fi
'

log_head "Step 5: Setting File Permissions & Ownerships on ${DST_HOST}"
${SSH_DST} bash -c '
    chown -R www-data:www-data /appsrv/www /appsrv/ytb-owntone 2>/dev/null || true
    chmod -R 775 /appsrv/www /appsrv/ytb-owntone 2>/dev/null || true
    chown -R jellyfin:jellyfin /appsrv/jellyfin 2>/dev/null || true
    chown -R nobody:nogroup /nasdata 2>/dev/null || true
    chmod -R 775 /nasdata 2>/dev/null || true
'

log_head "Step 6: Starting Services on ${DST_HOST}"
${SSH_DST} bash -c '
    systemctl daemon-reload
    for s in unbound AdGuardHome nginx php*-fpm jellyfin aria2 smbd rtp2httpd; do
        systemctl restart "$s" 2>/dev/null || true
        systemctl enable "$s" 2>/dev/null || true
    done
    # Restart Home Assistant container
    docker restart homeassistant 2>/dev/null || true
'

log_succ "Application state and configurations migrated successfully!"
echo -e "\n${BOLD}${GREEN}Migration complete!${NC}"
echo -e "You can now safely detach the USB disk labelled 'nasdata' from ${SRC_HOST} and attach it to ${DST_HOST}."
echo -e "The USB drive will automatically mount to ${GREEN}/nasdata${NC} (and ${GREEN}/mnt/nasdata${NC}).\n"
