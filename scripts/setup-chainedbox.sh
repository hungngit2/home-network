#!/usr/bin/env bash
# ==============================================================================
# Chainedbox (Armbian RK3328) Full System Bootstrap & Installation Script
# https://github.com/hungngit2/home-network
# ==============================================================================
# Usage:
#   Interactive mode (prompts for any custom value with intelligent defaults):
#     curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-chainedbox.sh | sudo bash
#
#   Non-interactive mode (fully automated with environment variables):
#     export NON_INTERACTIVE=true
#     export STATIC_IPV4="10.0.0.100"
#     export STATIC_IPV6_ULA="fd39:10::100/64"
#     export IPV6_TOKEN="::100"
#     export IFACE_NAME="end0"
#     export VLAN10_ID="10"
#     export DDNS_DOMAIN="lotus.ddns.net"
#     export APPSRV_DIR="/mnt/appsrv"
#     export NASDATA_DIR="/mnt/nasdata"
#     export MYTV_AUTH_USER="mytv"
#     export MYTV_AUTH_PASS="MyTV@1076"
#     export SMB_NETBIOS_NAME="chainedbox"
#     export SMB_WORKGROUP="WORKGROUP"
#     curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-chainedbox.sh | sudo bash
# ==============================================================================

set -euo pipefail

# --- Color Formatting ---
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
log_head() {
    echo -e "\n${BOLD}${CYAN}=== $* ===${NC}"
}

# --- Helper: Generate RFC4122 UUID Dynamically ---
generate_uuid() {
    if [[ -r /proc/sys/kernel/random/uuid ]]; then
        cat /proc/sys/kernel/random/uuid
    elif command -v uuidgen >/dev/null 2>&1; then
        uuidgen
    else
        od -x -N 16 /dev/urandom | head -1 | awk '{printf "%s%s-%s-%s-%s-%s%s%s", $2, $3, $4, $5, $6, $7, $8, $9}'
    fi
}

# --- Root Check ---
if [[ "${EUID}" -ne 0 ]]; then
    log_err "This installation script must be run as root (or with sudo)."
    exit 1
fi

# --- Auto-Detect System Defaults ---
DETECTED_IFACE=$(ip -o -4 route show to default 2>/dev/null | awk '{print $5}' | head -n1 || echo "")
if [[ -z "${DETECTED_IFACE}" ]]; then
    DETECTED_IFACE=$(ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | grep -E '^(end|eth|en)' | head -n1 || echo "end0")
fi

DETECTED_IPV4=$(ip -o -4 addr show dev "${DETECTED_IFACE}" 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -n1 || echo "10.0.0.100")
if [[ -z "${DETECTED_IPV4}" || "${DETECTED_IPV4}" == "127."* ]]; then
    DETECTED_IPV4="10.0.0.100"
fi

# --- Configurable Parameters (Overridable via Env Vars or Interactive Prompt) ---
REPO_RAW_BASE="${REPO_RAW_BASE:-https://raw.githubusercontent.com/hungngit2/home-network/main}"
IFACE_NAME="${IFACE_NAME:-${DETECTED_IFACE}}"
STATIC_IPV4="${STATIC_IPV4:-${DETECTED_IPV4}}"
STATIC_IPV6_ULA="${STATIC_IPV6_ULA:-fd39:10::100/64}"
IPV6_TOKEN="${IPV6_TOKEN:-::100}"
VLAN10_ID="${VLAN10_ID:-10}"
DDNS_DOMAIN="${DDNS_DOMAIN:-lotus.ddns.net}"
APPSRV_DIR="${APPSRV_DIR:-/mnt/appsrv}"
NASDATA_DIR="${NASDATA_DIR:-/mnt/nasdata}"
MYTV_AUTH_USER="${MYTV_AUTH_USER:-mytv}"
MYTV_AUTH_PASS="${MYTV_AUTH_PASS:-MyTV@1076}"
RTP2HTTPD_PORT="${RTP2HTTPD_PORT:-5140}"
UNBOUND_PORT="${UNBOUND_PORT:-5335}"
SMB_NETBIOS_NAME="${SMB_NETBIOS_NAME:-chainedbox}"
SMB_WORKGROUP="${SMB_WORKGROUP:-WORKGROUP}"
NON_INTERACTIVE="${NON_INTERACTIVE:-false}"

# --- Helper: Interactive Prompt with Default ---
prompt_val() {
    local prompt_text="$1"
    local var_name="$2"
    local default_val="${!var_name}"

    if [[ "${NON_INTERACTIVE}" == "true" ]]; then
        return
    fi

    echo -ne "${BOLD}${prompt_text}${NC} [${GREEN}${default_val}${NC}]: "
    read -r user_input < /dev/tty || true
    if [[ -n "${user_input}" ]]; then
        eval "${var_name}=\"${user_input}\""
    fi
}

# --- Banner & Configuration Review ---
clear 2>/dev/null || true
echo -e "${CYAN}${BOLD}"
echo "  ██████╗██╗  ██╗ █████╗ ██╗███╗   ██╗███████╗██████╗  ██████╗ ██╗  ██╗"
echo " ██╔════╝██║  ██║██╔══██╗██║████╗  ██║██╔════╝██╔══██╗██╔═══██╗╚██╗██╔╝"
echo " ██║     ███████║███████║██║██╔██╗ ██║█████╗  ██║  ██║██║   ██║ ╚███╔╝ "
echo " ██║     ██╔══██║██╔══██║██║██║╚██╗██║██╔══╝  ██║  ██║██║   ██║ ██╔██╗ "
echo " ╚██████╗██║  ██║██║  ██║██║██║ ╚████║███████╗██████╔╝╚██████╔╝██╔╝ ██╗"
echo "  ╚═════╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝╚═╝  ╚═══╝╚══════╝╚═════╝  ╚═════╝ ╚═╝  ╚═╝"
echo -e "${NC}"
echo -e "${BOLD}Armbian RK3328 Home Server Automated Bootstrap Installer${NC}\n"

if [[ "${NON_INTERACTIVE}" != "true" ]]; then
    echo -e "${YELLOW}Please review or adjust any configuration settings below (press Enter to accept default):${NC}\n"
    prompt_val "Primary Ethernet Interface" "IFACE_NAME"
    prompt_val "Primary Server IPv4 Address" "STATIC_IPV4"
    prompt_val "Static IPv6 ULA Address/Prefix" "STATIC_IPV6_ULA"
    prompt_val "IPv6 Host Token" "IPV6_TOKEN"
    prompt_val "IoT VLAN ID" "VLAN10_ID"
    prompt_val "DDNS / Host Domain" "DDNS_DOMAIN"
    prompt_val "App Data Mount Path" "APPSRV_DIR"
    prompt_val "Bulk NAS Storage Mount Path" "NASDATA_DIR"
    prompt_val "IPTV Web Auth User" "MYTV_AUTH_USER"
    prompt_val "IPTV Web Auth Password" "MYTV_AUTH_PASS"
    prompt_val "IPTV Proxy Port" "RTP2HTTPD_PORT"
    prompt_val "Unbound Port" "UNBOUND_PORT"
    prompt_val "Samba NetBIOS Name" "SMB_NETBIOS_NAME"
    prompt_val "Samba Workgroup" "SMB_WORKGROUP"
fi

echo -e "\n${BOLD}Configuration Summary:${NC}"
echo -e "  • Network Interface:   ${GREEN}${IFACE_NAME}${NC}"
echo -e "  • IPv4 Address:        ${GREEN}${STATIC_IPV4}${NC}"
echo -e "  • IPv6 ULA Address:    ${GREEN}${STATIC_IPV6_ULA}${NC}"
echo -e "  • IPv6 Token:          ${GREEN}${IPV6_TOKEN}${NC}"
echo -e "  • IoT VLAN ID:         ${GREEN}${VLAN10_ID}${NC}"
echo -e "  • DDNS Domain:         ${GREEN}${DDNS_DOMAIN}${NC}"
echo -e "  • App Directory:       ${GREEN}${APPSRV_DIR}${NC}"
echo -e "  • NAS Directory:       ${GREEN}${NASDATA_DIR}${NC}"
echo -e "  • IPTV Auth User/Pass: ${GREEN}${MYTV_AUTH_USER} / ****${NC}"
echo -e "  • IPTV Port:           ${GREEN}${RTP2HTTPD_PORT}${NC}"
echo -e "  • Samba Name:          ${GREEN}${SMB_NETBIOS_NAME}${NC}"
echo -e "  • GitHub Raw Base:     ${CYAN}${REPO_RAW_BASE}${NC}"

if [[ "${NON_INTERACTIVE}" != "true" ]]; then
    echo -ne "\n${BOLD}Proceed with full installation? [Y/n]: ${NC}"
    read -r confirm < /dev/tty || true
    if [[ "${confirm}" =~ ^[Nn] ]]; then
        log_warn "Installation cancelled by user."
        exit 0
    fi
fi

# ==============================================================================
# Step 1: Storage Mounts & Directory Hierarchy
# ==============================================================================
log_head "Step 1/8: Preparing Storage & Directory Hierarchy"

# Verify or create mount directories
mkdir -p "${APPSRV_DIR}" "${NASDATA_DIR}"

if ! mountpoint -q "${APPSRV_DIR}"; then
    log_warn "${APPSRV_DIR} is not currently a mounted partition. Using filesystem path."
fi
if ! mountpoint -q "${NASDATA_DIR}"; then
    log_warn "${NASDATA_DIR} is not currently a mounted partition. Using filesystem path."
fi

# Create directory hierarchy on appsrv
mkdir -p \
    "${APPSRV_DIR}/adguard-home" \
    "${APPSRV_DIR}/aria2/.aria2" \
    "${APPSRV_DIR}/docker" \
    "${APPSRV_DIR}/jellyfin/config" \
    "${APPSRV_DIR}/jellyfin/log" \
    "${APPSRV_DIR}/jellyfin/cache" \
    "${APPSRV_DIR}/jellyfin/tmp" \
    "${APPSRV_DIR}/jellyfin/web" \
    "${APPSRV_DIR}/nginx/log" \
    "${APPSRV_DIR}/samba" \
    "${APPSRV_DIR}/www" \
    "${APPSRV_DIR}/ytb-owntone/pipes" \
    "${APPSRV_DIR}/ytb-owntone/cache" \
    "${APPSRV_DIR}/ytb-owntone/data"

# Create bulk storage directories on nasdata
mkdir -p \
    "${NASDATA_DIR}/apps" \
    "${NASDATA_DIR}/docs" \
    "${NASDATA_DIR}/downloads" \
    "${NASDATA_DIR}/media" \
    "${NASDATA_DIR}/share/www/certbot"

# Ensure symlink /opt/docker -> appsrv/docker
if [[ ! -L /opt/docker ]]; then
    mkdir -p /opt
    rm -rf /opt/docker
    ln -s "${APPSRV_DIR}/docker" /opt/docker
fi

# Set appropriate directory ownerships
chown -R www-data:www-data "${APPSRV_DIR}/www" "${APPSRV_DIR}/ytb-owntone" 2>/dev/null || true
chmod -R 775 "${APPSRV_DIR}/www" "${APPSRV_DIR}/ytb-owntone" 2>/dev/null || true

log_succ "Storage hierarchy and symlinks prepared successfully."

# ==============================================================================
# Step 2: System Packages, Kernel Sysctl & OS Tuning
# ==============================================================================
log_head "Step 2/8: Installing Base Packages & Applying Kernel Sysctl"

export DEBIAN_FRONTEND=noninteractive
log_info "Updating apt package index..."
apt-get update -qq

log_info "Installing core service dependencies..."
apt-get install -y --no-install-recommends \
    unbound \
    unbound-anchor \
    aria2 \
    nginx \
    php \
    php-fpm \
    php-gd \
    php-curl \
    php-mbstring \
    samba \
    certbot \
    python3-certbot-nginx \
    mosh \
    git \
    curl \
    wget \
    jq \
    tar \
    unzip \
    net-tools \
    avahi-daemon \
    avahi-utils \
    docker.io \
    ca-certificates

# Apply custom sysctl tuning
log_info "Deploying custom kernel sysctl (BBR, ZRAM swappiness=100, conntrack)..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/system/sysctl.conf" -o /etc/sysctl.d/99-chainedbox.conf
sysctl --system >/dev/null 2>&1 || true

# Nightly reboot crontab (idempotent)
log_info "Setting nightly 03:00 maintenance reboot crontab..."
(crontab -l 2>/dev/null | grep -v "/sbin/reboot" || true; echo "0 3 * * * /sbin/reboot") | crontab -

# Docker daemon configuration
log_info "Configuring Docker daemon data-root and log rotation..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/system/daemon.json" -o /etc/docker/daemon.json
sed -i "s|\"data-root\": \"/mnt/appsrv/docker\"|\"data-root\": \"${APPSRV_DIR}/docker\"|g" /etc/docker/daemon.json
systemctl restart docker 2>/dev/null || true

log_succ "Base packages and OS kernel tuning configured."

# ==============================================================================
# Step 3: Network & Dual-Homed VLAN Configuration
# ==============================================================================
log_head "Step 3/8: Configuring Netplan, Static IPv6 ULA & IoT VLAN ${VLAN10_ID}"

mkdir -p /etc/netplan /etc/NetworkManager/system-connections

# Deploy Netplan configs with dynamically substituted parameters
cat << EOF > /etc/netplan/00-default-use-network-manager.yaml
network:
  version: 2
  renderer: NetworkManager
EOF

cat << EOF > /etc/netplan/10-${IFACE_NAME}.yaml
network:
  version: 2
  renderer: NetworkManager
  ethernets:
    ${IFACE_NAME}:
      dhcp4: true
      dhcp6: true
      ipv6-address-generation: eui64
      addresses:
        - ${STATIC_IPV6_ULA}
      networkmanager:
        passthrough:
          ipv6.token: "${IPV6_TOKEN}"
          ipv6.addr-gen-mode: "eui64"
EOF

cat << EOF > /etc/netplan/90-vlan${VLAN10_ID}.yaml
network:
  version: 2
  vlans:
    ${IFACE_NAME}.${VLAN10_ID}:
      renderer: NetworkManager
      dhcp4: true
      dhcp6: true
      id: ${VLAN10_ID}
      link: "${IFACE_NAME}"
      networkmanager:
        name: "vlan${VLAN10_ID}"
        passthrough:
          ethernet._: ""
          vlan.flags: "1"
          ipv6.addr-gen-mode: "default"
          ipv6.ip6-privacy: "-1"
          proxy._: ""
EOF

WIRED_UUID=$(generate_uuid)
cat << EOF > "/etc/NetworkManager/system-connections/Wired connection 1.nmconnection"
[connection]
id=Wired connection 1
uuid=${WIRED_UUID}
type=ethernet
autoconnect-priority=-999
interface-name=${IFACE_NAME}

[ethernet]

[ipv4]
method=auto

[ipv6]
addr-gen-mode=eui64
method=auto
token=${IPV6_TOKEN}

[proxy]
EOF

chmod 600 "/etc/NetworkManager/system-connections/Wired connection 1.nmconnection"
chmod 600 /etc/netplan/*.yaml

log_info "Applying Netplan network configuration..."
netplan apply 2>/dev/null || true

log_succ "Network dual-homed VLAN interfaces configured."

# ==============================================================================
# Step 4: DNS Stack (Unbound + AdGuard Home) & Avahi mDNS
# ==============================================================================
log_head "Step 4/8: Configuring Unbound (${UNBOUND_PORT}), AdGuard Home (53/3000) & Avahi"

# Disable systemd-resolved DNSStubListener if active to avoid port 53 collision
if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
    log_info "Freeing port 53 by disabling systemd-resolved DNSStubListener..."
    mkdir -p /etc/systemd/resolved.conf.d
    cat << 'EOF' > /etc/systemd/resolved.conf.d/adguard-port53.conf
[Resolve]
DNSStubListener=no
EOF
    systemctl restart systemd-resolved 2>/dev/null || true
fi

# Configure Unbound on custom port
log_info "Configuring Unbound DNS resolver on port ${UNBOUND_PORT}..."
mkdir -p /etc/unbound/unbound.conf.d
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/unbound/custom-port.conf" -o /etc/unbound/unbound.conf.d/custom-port.conf
sed -i "s/port: 5335/port: ${UNBOUND_PORT}/g" /etc/unbound/unbound.conf.d/custom-port.conf
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/unbound/remote-control.conf" -o /etc/unbound/unbound.conf.d/remote-control.conf
systemctl restart unbound
systemctl enable unbound

# Install & Configure AdGuard Home
log_info "Setting up AdGuard Home..."
if [[ ! -f /opt/AdGuardHome/AdGuardHome ]]; then
    log_info "Downloading official AdGuard Home binary for ARM64..."
    mkdir -p /opt/AdGuardHome
    curl -s -L "https://static.adtidy.org/adguardhome/release/AdGuardHome_linux_arm64.tar.gz" | tar -xz -C /tmp/
    mv /tmp/AdGuardHome/AdGuardHome /opt/AdGuardHome/AdGuardHome
    rm -rf /tmp/AdGuardHome
    /opt/AdGuardHome/AdGuardHome -s install >/dev/null 2>&1 || true
fi

# Download AdGuardHome.yaml template to appsrv and substitute variables
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/adguard-home/AdGuardHome.yaml" -o "${APPSRV_DIR}/adguard-home/AdGuardHome.yaml"
sed -i "s|/mnt/appsrv/adguard-home/|${APPSRV_DIR}/adguard-home/|g" "${APPSRV_DIR}/adguard-home/AdGuardHome.yaml"
sed -i "s|127.0.0.1:5335|127.0.0.1:${UNBOUND_PORT}|g" "${APPSRV_DIR}/adguard-home/AdGuardHome.yaml"
sed -i "s|REDACTED-domain|${DDNS_DOMAIN}|g" "${APPSRV_DIR}/adguard-home/AdGuardHome.yaml"

# Symlink AdGuardHome config
systemctl stop AdGuardHome 2>/dev/null || true
rm -f /opt/AdGuardHome/AdGuardHome.yaml
ln -sf "${APPSRV_DIR}/adguard-home/AdGuardHome.yaml" /opt/AdGuardHome/AdGuardHome.yaml
systemctl start AdGuardHome 2>/dev/null || true
systemctl enable AdGuardHome 2>/dev/null || true

# Avahi-daemon mDNS reflector
log_info "Configuring Avahi mDNS reflector bridging ${IFACE_NAME} and ${IFACE_NAME}.${VLAN10_ID}..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/avahi/avahi-daemon.conf" -o /etc/avahi/avahi-daemon.conf
sed -i "s/allow-interfaces=end0,end0.10/allow-interfaces=${IFACE_NAME},${IFACE_NAME}.${VLAN10_ID}/g" /etc/avahi/avahi-daemon.conf
systemctl restart avahi-daemon
systemctl enable avahi-daemon

log_succ "DNS stack and Avahi mDNS reflector configured."

# ==============================================================================
# Step 5: Web Server (Nginx + PHP-FPM) & Web Applications
# ==============================================================================
log_head "Step 5/8: Configuring Nginx, PHP-FPM & Web Apps"

# Detect PHP-FPM version
PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.3")
log_info "Configuring PHP-FPM ${PHP_VER} memory pool..."
mkdir -p "/etc/php/${PHP_VER}/fpm/pool.d" /run/php
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/php/www.conf" -o "/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
systemctl restart "php${PHP_VER}-fpm" 2>/dev/null || true
systemctl enable "php${PHP_VER}-fpm" 2>/dev/null || true

# Ensure generic socket link for Nginx FastCGI
ln -sf "/run/php/php${PHP_VER}-fpm.sock" /run/php/php-fpm.sock 2>/dev/null || true

# Deploy Nginx Default VHost to appsrv
log_info "Deploying Nginx reverse proxy configuration..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/nginx/default.conf" -o "${APPSRV_DIR}/nginx/default"
sed -i "s|root /mnt/appsrv/www;|root ${APPSRV_DIR}/www;|g" "${APPSRV_DIR}/nginx/default"
sed -i "s|/mnt/appsrv/nginx/log|${APPSRV_DIR}/nginx/log|g" "${APPSRV_DIR}/nginx/default"
sed -i "s|/mnt/nasdata/share/www/certbot/|${NASDATA_DIR}/share/www/certbot/|g" "${APPSRV_DIR}/nginx/default"
sed -i "s|http://localhost:5140/tv/|http://localhost:${RTP2HTTPD_PORT}/tv/|g" "${APPSRV_DIR}/nginx/default"
sed -i "s|REDACTED-domain|${DDNS_DOMAIN}|g" "${APPSRV_DIR}/nginx/default"

rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
ln -sf "${APPSRV_DIR}/nginx/default" /etc/nginx/sites-available/default
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Clone / Deploy Wi-Fi Fleet Config Tool
log_info "Deploying Wi-Fi Fleet Configuration Tool..."
mkdir -p "${APPSRV_DIR}/www/wifi-config-tool/assets" \
         "${APPSRV_DIR}/www/wifi-config-tool/configs" \
         "${APPSRV_DIR}/www/wifi-config-tool/pages" \
         "${APPSRV_DIR}/www/wifi-config-tool/src"

curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/index.php" -o "${APPSRV_DIR}/www/wifi-config-tool/index.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/assets/style.css" -o "${APPSRV_DIR}/www/wifi-config-tool/assets/style.css"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/configs/config.json.example" -o "${APPSRV_DIR}/www/wifi-config-tool/configs/config.json"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/configs/standards.json" -o "${APPSRV_DIR}/www/wifi-config-tool/configs/standards.json"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/pages/device.php" -o "${APPSRV_DIR}/www/wifi-config-tool/pages/device.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/pages/bulk.php" -o "${APPSRV_DIR}/www/wifi-config-tool/pages/bulk.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/src/auth.php" -o "${APPSRV_DIR}/www/wifi-config-tool/src/auth.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/src/bootstrap.php" -o "${APPSRV_DIR}/www/wifi-config-tool/src/bootstrap.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/src/OpenWrtClient.php" -o "${APPSRV_DIR}/www/wifi-config-tool/src/OpenWrtClient.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/src/DeviceManager.php" -o "${APPSRV_DIR}/www/wifi-config-tool/src/DeviceManager.php"
curl -fsSL "${REPO_RAW_BASE}/wifi-config-tool/src/Standards.php" -o "${APPSRV_DIR}/www/wifi-config-tool/src/Standards.php"

# Clone / Deploy YouTube OwnTone Dashboard
log_info "Deploying YouTube OwnTone Dashboard to ${APPSRV_DIR}/www/ytb..."
if [[ -d "${APPSRV_DIR}/www/ytb/.git" ]]; then
    git -C "${APPSRV_DIR}/www/ytb" pull 2>/dev/null || true
else
    rm -rf "${APPSRV_DIR}/www/ytb"
    git clone https://github.com/hungngit2/ytb-owntone-dashboard.git "${APPSRV_DIR}/www/ytb" 2>/dev/null || \
    git clone git@github.com:hungngit2/ytb-owntone-dashboard.git "${APPSRV_DIR}/www/ytb" || true
fi

# Clone / Deploy AriaNg Web UI
log_info "Deploying AriaNg Web UI to ${APPSRV_DIR}/www/aria2..."
mkdir -p "${APPSRV_DIR}/www/aria2"
if [[ -d "${APPSRV_DIR}/www/aria2/.git" ]]; then
    git -C "${APPSRV_DIR}/www/aria2" pull 2>/dev/null || true
elif [[ ! -f "${APPSRV_DIR}/www/aria2/index.html" ]]; then
    rm -rf "${APPSRV_DIR}/www/aria2"
    if ! git clone https://github.com/mayswind/AriaNg.git "${APPSRV_DIR}/www/aria2" 2>/dev/null && \
       ! git clone git@github.com:mayswind/AriaNg.git "${APPSRV_DIR}/www/aria2" 2>/dev/null; then
        log_info "Downloading latest prebuilt AriaNg release bundle..."
        mkdir -p "${APPSRV_DIR}/www/aria2"
        curl -s -L "https://github.com/mayswind/AriaNg/releases/download/1.3.14/AriaNg-1.3.14-AllInOne.zip" -o /tmp/ariang.zip
        unzip -o -q /tmp/ariang.zip -d "${APPSRV_DIR}/www/aria2"
        rm -f /tmp/ariang.zip
    fi
fi

# Deploy JK BMS Configuration Tool
log_info "Deploying JK BMS Configuration Tool to ${APPSRV_DIR}/www/jk..."
mkdir -p "${APPSRV_DIR}/www/jk"
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/jk/index.html" -o "${APPSRV_DIR}/www/jk/index.html"
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/jk/styles.css" -o "${APPSRV_DIR}/www/jk/styles.css"
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/jk/jk-bms-generator.js" -o "${APPSRV_DIR}/www/jk/jk-bms-generator.js"
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/jk/clipboard.min.js" -o "${APPSRV_DIR}/www/jk/clipboard.min.js"

chown -R www-data:www-data "${APPSRV_DIR}/www" "${APPSRV_DIR}/ytb-owntone" 2>/dev/null || true
chmod -R 775 "${APPSRV_DIR}/www" "${APPSRV_DIR}/ytb-owntone" 2>/dev/null || true

systemctl restart nginx
systemctl enable nginx

log_succ "Nginx, PHP-FPM, and Web Tools deployed."

# ==============================================================================
# Step 6: IPTV Multicast Relay (rtp2httpd) & Media Services
# ==============================================================================
log_head "Step 6/8: Configuring IPTV Streaming (rtp2httpd) & Media Services"

# 1. Install & Configure rtp2httpd via Official GitHub Installer
log_info "Installing rtp2httpd via official GitHub installer..."
if ! command -v rtp2httpd >/dev/null 2>&1; then
    curl -fsSL https://raw.githubusercontent.com/hungngit2/rtp2httpd/main/scripts/install-armbian.sh | sh
fi

# Deploy tuned rtp2httpd.conf & customize parameters
log_info "Applying tuned rtp2httpd configuration..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/rtp2httpd/rtp2httpd.conf" -o /etc/rtp2httpd.conf
sed -i "s|external-m3u = http://10.0.0.100/iptv/|external-m3u = http://${STATIC_IPV4}/iptv/|g" /etc/rtp2httpd.conf
sed -i "s|web-auth-user = mytv|web-auth-user = ${MYTV_AUTH_USER}|g" /etc/rtp2httpd.conf
sed -i "s|web-auth-password = <REDACTED>|web-auth-password = ${MYTV_AUTH_PASS}|g" /etc/rtp2httpd.conf
sed -i "s|\* 5140|\* ${RTP2HTTPD_PORT}|g" /etc/rtp2httpd.conf
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/rtp2httpd/rtp2httpd.service" -o /etc/systemd/system/rtp2httpd.service

systemctl daemon-reload
systemctl enable rtp2httpd
systemctl restart rtp2httpd 2>/dev/null || true

# 2. Install & Configure OwnTone Server via Official GitHub Installer
log_info "Installing OwnTone server via official GitHub installer..."
if ! command -v owntone >/dev/null 2>&1; then
    curl -fsSL https://raw.githubusercontent.com/hungngit2/owntone-server/master/install.sh | bash
fi

log_info "Configuring OwnTone server memory drop-in..."
mkdir -p /etc/systemd/system/owntone.service.d
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/owntone/owntone-memorymax-override.conf" -o /etc/systemd/system/owntone.service.d/50-MemoryMax.conf
systemctl daemon-reload
systemctl restart owntone 2>/dev/null || true
systemctl enable owntone 2>/dev/null || true

# 3. Install & Configure Jellyfin
log_info "Setting up Jellyfin Media Server..."
if ! command -v jellyfin >/dev/null 2>&1; then
    log_info "Installing Jellyfin via official Debian/Ubuntu installer..."
    curl -fsSL https://repo.jellyfin.org/install-debuntu.sh | bash 2>/dev/null || true
fi

log_info "Configuring Jellyfin environment flags and systemd unit..."
mkdir -p "${APPSRV_DIR}/jellyfin/var-lib" "${APPSRV_DIR}/jellyfin/web"
if [[ ! -f "${APPSRV_DIR}/jellyfin/web/index.html" && -d /usr/share/jellyfin/web ]]; then
    cp -r /usr/share/jellyfin/web/* "${APPSRV_DIR}/jellyfin/web/" 2>/dev/null || true
fi
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/jellyfin/jellyfin.env" -o "${APPSRV_DIR}/jellyfin/env"
sed -i "s|/mnt/appsrv/jellyfin|${APPSRV_DIR}/jellyfin|g" "${APPSRV_DIR}/jellyfin/env"
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/jellyfin/jellyfin.service" -o /etc/systemd/system/jellyfin.service
sed -i "s|/mnt/appsrv|${APPSRV_DIR}|g" /etc/systemd/system/jellyfin.service
chown -R jellyfin:jellyfin "${APPSRV_DIR}/jellyfin" 2>/dev/null || true
systemctl daemon-reload
systemctl restart jellyfin 2>/dev/null || true
systemctl enable jellyfin 2>/dev/null || true

log_succ "IPTV streamer and media service definitions configured."

# ==============================================================================
# Step 7: Storage Sharing (Samba) & Aria2 Downloader
# ==============================================================================
log_head "Step 7/8: Configuring Samba Shares & Aria2 Daemon"

# Samba
log_info "Configuring Samba file shares for ${NASDATA_DIR}..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/samba/smb.conf" -o "${APPSRV_DIR}/samba/smb.conf"
sed -i "s|netbios name = chainedbox|netbios name = ${SMB_NETBIOS_NAME}|g" "${APPSRV_DIR}/samba/smb.conf"
sed -i "s|workgroup = WORKGROUP|workgroup = ${SMB_WORKGROUP}|g" "${APPSRV_DIR}/samba/smb.conf"
sed -i "s|/mnt/nasdata|${NASDATA_DIR}|g" "${APPSRV_DIR}/samba/smb.conf"

rm -f /etc/samba/smb.conf
ln -sf "${APPSRV_DIR}/samba/smb.conf" /etc/samba/smb.conf
systemctl restart smbd nmbd
systemctl enable smbd nmbd

# Aria2
log_info "Configuring Aria2 daemon & post-download trigger..."
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/aria2/aria2.conf" -o "${APPSRV_DIR}/aria2/aria2.conf"
sed -i "s|dir=/mnt/nasdata/downloads|dir=${NASDATA_DIR}/downloads|g" "${APPSRV_DIR}/aria2/aria2.conf"
sed -i "s|/mnt/appsrv/aria2|${APPSRV_DIR}/aria2|g" "${APPSRV_DIR}/aria2/aria2.conf"

curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/aria2/aria2-post-download.sh" -o "${APPSRV_DIR}/aria2/aria2-post-download.sh"
chmod +x "${APPSRV_DIR}/aria2/aria2-post-download.sh"
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/aria2/aria2.service" -o /etc/systemd/system/aria2.service
sed -i "s|/mnt/appsrv|${APPSRV_DIR}|g" /etc/systemd/system/aria2.service
if ! mountpoint -q "${APPSRV_DIR}"; then
    sed -i '/ConditionPathIsMountPoint/d' /etc/systemd/system/aria2.service
fi

systemctl daemon-reload
systemctl enable aria2
systemctl restart aria2 2>/dev/null || true

log_succ "Samba and Aria2 services configured."

# ==============================================================================
# Step 8: Home Assistant (Docker) & System Verification
# ==============================================================================
log_head "Step 8/8: Setting up Home Assistant Container & Health Checks"

# Deploy Home Assistant configuration files
mkdir -p /opt/docker/homeassistant/config /opt/docker/homeassistant/media
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/homeassistant/configuration.yaml" -o /opt/docker/homeassistant/config/configuration.yaml
curl -fsSL "${REPO_RAW_BASE}/configs/chainedbox/homeassistant/automations.yaml" -o /opt/docker/homeassistant/config/automations.yaml

log_info "Deploying / starting Home Assistant container..."
if ! docker ps -a --format '{{.Names}}' | grep -q '^homeassistant$'; then
    docker run -d \
        --name homeassistant \
        --privileged \
        --restart unless-stopped \
        --net=host \
        -v /opt/docker/homeassistant/config:/config \
        -v /opt/docker/homeassistant/media:/media \
        -v /etc/localtime:/etc/localtime:ro \
        homeassistant/home-assistant:latest 2>/dev/null || true
else
    docker restart homeassistant 2>/dev/null || true
fi

# Print final status report
echo -e "\n${BOLD}${GREEN}==============================================================================${NC}"
echo -e "${BOLD}${GREEN}               Chainedbox System Bootstrap Completed!                        ${NC}"
echo -e "${BOLD}${GREEN}==============================================================================${NC}\n"

echo -e "${BOLD}Active Service Status:${NC}"
for s in nginx "php${PHP_VER}-fpm" unbound AdGuardHome avahi-daemon rtp2httpd smbd aria2 docker; do
    if systemctl is-active --quiet "$s" 2>/dev/null; then
        printf "  • %-20s [ ${GREEN}ACTIVE / RUNNING${NC} ]\n" "$s"
    else
        printf "  • %-20s [ ${YELLOW}INACTIVE / STOPPED${NC} ]\n" "$s"
    fi
done

echo -e "\n${BOLD}Quick Access Endpoints:${NC}"
echo -e "  • AdGuard Home Admin:  ${CYAN}http://${STATIC_IPV4}:3000${NC} (or https://${DDNS_DOMAIN})"
echo -e "  • Wi-Fi Config Tool:   ${CYAN}http://${STATIC_IPV4}/wifi-config-tool/${NC}"
echo -e "  • IPTV Stream Proxy:   ${CYAN}http://${STATIC_IPV4}:${RTP2HTTPD_PORT}/tv/${NC} (or http://${STATIC_IPV4}/tv/)"
echo -e "  • Home Assistant:      ${CYAN}http://${STATIC_IPV4}:8123${NC}"
echo -e "  • OwnTone Music:       ${CYAN}http://${STATIC_IPV4}:3689${NC}"
echo -e "  • Jellyfin Media:      ${CYAN}http://${STATIC_IPV4}:8096/jellyfin/${NC}"
echo -e "  • Samba Share Root:    ${CYAN}\\\\${STATIC_IPV4}\\downloads${NC} (or smb://${STATIC_IPV4})"

echo -e "\n${BOLD}Note:${NC} You can reboot the system anytime with: ${CYAN}reboot${NC}\n"

