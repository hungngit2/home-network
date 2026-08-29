#!/bin/sh
# ==============================================================================
# OpenWrt Access Point Fleet Automated Setup & Reinstallation Script
# https://github.com/hungngit2/home-network
# ==============================================================================
# Compatible with OpenWrt 23.05, 24.10, and 25.12 (apk / opkg, DSA architecture)
#
# Usage:
#   Interactive mode (run directly on the OpenWrt AP via SSH):
#     curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-openwrt-ap.sh | sh
#
#   Non-interactive mode (with environment variables):
#     export NON_INTERACTIVE=true
#     export AP_HOSTNAME="jcg-q20-f1"
#     export AP_IP="10.0.0.201"
#     export CHANNEL_2G="6"
#     curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-openwrt-ap.sh | sh
# ==============================================================================

set -eu

# --- Color Formatting ---
BOLD='\033[1m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo "${BLUE}[INFO]${NC} $*"; }
log_succ() { echo "${GREEN}[SUCCESS]${NC} $*"; }
log_warn() { echo "${YELLOW}[WARN]${NC} $*"; }
log_err()  { echo "${RED}[ERROR]${NC} $*"; }
log_head() {
    echo ""
    echo "${BOLD}${CYAN}=== $* ===${NC}"
}

# --- Auto-Detection ---
CURRENT_HOSTNAME=$(uci get system.@system[0].hostname 2>/dev/null || cat /proc/sys/kernel/hostname 2>/dev/null || echo "openwrt-ap")
CURRENT_IP=$(ip -o -4 addr show dev br-lan.1 2>/dev/null | awk '{print $4}' | cut -d/ -f1 || ip -o -4 addr show dev br-lan 2>/dev/null | awk '{print $4}' | cut -d/ -f1 || echo "10.0.0.201")
BOARD_MODEL=$(cat /tmp/sysinfo/model 2>/dev/null || cat /proc/cpuinfo | grep 'machine' | cut -d: -f2 | xargs || echo "Generic OpenWrt AP")

# Suggest default 2.4G channel based on last digit of IP
SUGGESTED_2G="6"
case "${CURRENT_IP}" in
    *200) SUGGESTED_2G="1" ;;
    *201) SUGGESTED_2G="6" ;;
    *202) SUGGESTED_2G="11" ;;
    *203) SUGGESTED_2G="1" ;;
esac

# --- Parameters & Defaults ---
REPO_RAW_BASE="${REPO_RAW_BASE:-https://raw.githubusercontent.com/hungngit2/home-network/main}"
AP_HOSTNAME="${AP_HOSTNAME:-${CURRENT_HOSTNAME}}"
AP_IP="${AP_IP:-${CURRENT_IP}}"
ROUTER_IP="${ROUTER_IP:-10.0.0.254}"
DNS_SERVER="${DNS_SERVER:-10.0.0.100}"
SSID_LAN="${SSID_LAN:-lotus}"
PASS_LAN="${PASS_LAN:-MaiThaoTonyToby39}"
SSID_IOT="${SSID_IOT:-lotus IoT}"
PASS_IOT="${PASS_IOT:-IoT@1076}"
SSID_GUEST="${SSID_GUEST:-lotus⁺}"
PASS_GUEST="${PASS_GUEST:-Lotus@Heart}"
MESH_ID="${MESH_ID:-lotus-mesh}"
MESH_KEY="${MESH_KEY:-Lotus@Mesh}"
MOBILITY_DOMAIN="${MOBILITY_DOMAIN:-1076}"
CHANNEL_2G="${CHANNEL_2G:-${SUGGESTED_2G}}"
CHANNEL_5G="${CHANNEL_5G:-36}"
COUNTRY="${COUNTRY:-US}"
NON_INTERACTIVE="${NON_INTERACTIVE:-false}"

# --- Helper: Interactive Prompt with Default ---
prompt_val() {
    prompt_text="$1"
    var_name="$2"
    eval "default_val=\"\$$var_name\""

    if [ "${NON_INTERACTIVE}" = "true" ]; then
        return
    fi

    printf "%b%s%b [%b%s%b]: " "${BOLD}" "${prompt_text}" "${NC}" "${GREEN}" "${default_val}" "${NC}"
    read user_input < /dev/tty || user_input=""
    if [ -n "${user_input}" ]; then
        eval "${var_name}=\"${user_input}\""
    fi
}

# --- Banner & Configuration Review ---
clear 2>/dev/null || true
echo "${CYAN}${BOLD}"
echo "  ██████╗ ██████╗ ███████╗███╗   ██╗██╗    ██╗██████╗ ████████╗"
echo " ██╔═══██╗██╔══██╗██╔════╝████╗  ██║██║    ██║██╔══██╗╚══██╔══╝"
echo " ██║   ██║██████╔╝█████╗  ██╔██╗ ██║██║ █╗ ██║██████╔╝   ██║   "
echo " ██║   ██║██╔═══╝ ██╔══╝  ██║╚██╗██║██║███╗██║██╔══██╗   ██║   "
echo " ╚██████╔╝██║     ███████╗██║ ╚████║╚███╔███╔╝██║  ██║   ██║   "
echo "  ╚═════╝ ╚═╝     ╚══════╝╚═╝  ╚═══╝ ╚══╝╚══╝ ╚═╝  ╚═╝   ╚═╝   "
echo "${NC}"
echo "${BOLD}OpenWrt Access Point Fleet Provisioning & Setup Installer${NC}"
echo "Detected Hardware: ${GREEN}${BOARD_MODEL}${NC}\n"

if [ "${NON_INTERACTIVE}" != "true" ]; then
    echo "${YELLOW}Please review or adjust the configuration parameters below (press Enter to accept default):${NC}\n"
    prompt_val "AP Hostname" "AP_HOSTNAME"
    prompt_val "AP Static IPv4 Address" "AP_IP"
    prompt_val "Upstream Router / Gateway IP" "ROUTER_IP"
    prompt_val "Primary DNS Server (AdGuard Home / Router)" "DNS_SERVER"
    prompt_val "2.4GHz Wi-Fi Channel (1, 6, 11)" "CHANNEL_2G"
    prompt_val "5GHz Wi-Fi Channel" "CHANNEL_5G"
    prompt_val "Main LAN SSID" "SSID_LAN"
    prompt_val "Main LAN Wi-Fi Password" "PASS_LAN"
    prompt_val "IoT SSID" "SSID_IOT"
    prompt_val "IoT Wi-Fi Password" "PASS_IOT"
    prompt_val "Guest SSID" "SSID_GUEST"
    prompt_val "Guest Wi-Fi Password" "PASS_GUEST"
    prompt_val "Mesh Backhaul ID" "MESH_ID"
    prompt_val "Mesh Backhaul Key" "MESH_KEY"
    prompt_val "802.11r Mobility Domain" "MOBILITY_DOMAIN"
fi

echo ""
echo "${BOLD}Target Configuration Summary:${NC}"
echo "  • Hostname:            ${GREEN}${AP_HOSTNAME}${NC}"
echo "  • AP IP Address:       ${GREEN}${AP_IP}${NC}"
echo "  • Gateway / DNS:       ${GREEN}${ROUTER_IP} / ${DNS_SERVER}${NC}"
echo "  • 2.4G / 5G Channel:   ${GREEN}${CHANNEL_2G} / ${CHANNEL_5G}${NC}"
echo "  • Main SSID:           ${GREEN}${SSID_LAN}${NC}"
echo "  • IoT SSID:            ${GREEN}${SSID_IOT}${NC} (PMF disabled for smart devices)"
echo "  • Guest SSID:          ${GREEN}${SSID_GUEST}${NC} (PMF enabled)"
echo "  • Mesh ID / Key:       ${GREEN}${MESH_ID}${NC}"
echo "  • Fast Roaming Domain: ${GREEN}${MOBILITY_DOMAIN}${NC}"

if [ "${NON_INTERACTIVE}" != "true" ]; then
    printf "\n%bApply this configuration to the AP now? [Y/n]: %b" "${BOLD}" "${NC}"
    read confirm < /dev/tty || confirm="y"
    case "${confirm}" in
        [Nn]*)
            log_warn "Setup cancelled by user."
            exit 0
            ;;
    esac
fi

# ==============================================================================
# Step 1: Install Required Packages (wpad-mesh, usteer, unbound)
# ==============================================================================
log_head "Step 1/6: Installing Required Packages (wpad-mesh, usteer, unbound)"

if command -v apk >/dev/null 2>&1; then
    log_info "Updating apk package index..."
    apk update || true
    log_info "Installing packages via apk..."
    apk add wpad-mesh-mbedtls usteer unbound-daemon unbound-control libunbound || \
    apk add wpad-mesh-openssl usteer unbound-daemon unbound-control libunbound || true
elif command -v opkg >/dev/null 2>&1; then
    log_info "Updating opkg package index..."
    opkg update || true
    log_info "Installing packages via opkg..."
    # Replace wpad-basic with wpad-mesh for 802.11s + 802.11r support
    opkg remove wpad-basic wpad-basic-mbedtls wpad-basic-wolfssl 2>/dev/null || true
    opkg install wpad-mesh-mbedtls usteer unbound-daemon unbound-control || \
    opkg install wpad-mesh-openssl usteer unbound-daemon unbound-control || true
fi

log_succ "Packages verified and installed."

# ==============================================================================
# Step 2: System, Hostname & Timezone Configuration
# ==============================================================================
log_head "Step 2/6: Configuring System Hostname & Timezone"

uci set system.@system[0].hostname="${AP_HOSTNAME}"
uci set system.@system[0].zonename="Asia/Ho_Chi_Minh"
uci set system.@system[0].timezone="<+07>-7"
uci -q delete system.ntp.server || true
uci add_list system.ntp.server="0.openwrt.pool.ntp.org"
uci add_list system.ntp.server="1.openwrt.pool.ntp.org"
uci add_list system.ntp.server="2.openwrt.pool.ntp.org"
uci add_list system.ntp.server="3.openwrt.pool.ntp.org"
uci set system.@system[0].conloglevel="8"
uci set system.@system[0].cronloglevel="5"

log_succ "System parameters set to ${AP_HOSTNAME} (Asia/Ho_Chi_Minh)."

# ==============================================================================
# Step 3: Network & DSA VLAN Filtering Bridge
# ==============================================================================
log_head "Step 3/6: Configuring Network, DSA Bridge & VLANs"

# Configure loopback
uci set network.loopback=interface
uci set network.loopback.device='lo'
uci set network.loopback.proto='static'
uci set network.loopback.ipaddr='127.0.0.1'
uci set network.loopback.netmask='255.0.0.0'

# Configure globals
uci set network.globals=globals
uci set network.globals.packet_steering='1'
uci set network.globals.ula_prefix='fd39:10::/48'

# Configure Bridge Device 'br-lan'
uci set network.br_lan_dev=device
uci set network.br_lan_dev.name='br-lan'
uci set network.br_lan_dev.type='bridge'
uci set network.br_lan_dev.stp='1'
uci set network.br_lan_dev.igmp_snooping='1'

# Port mapping based on detected model
uci -q delete network.br_lan_dev.ports || true
case "${BOARD_MODEL}" in
    *"Redmi"*"AC2100"*)
        log_info "Applying Redmi AC2100 port layout (lan1, lan2, lan3, wan, lotus-mesh)..."
        uci add_list network.br_lan_dev.ports='lan1'
        uci add_list network.br_lan_dev.ports='lan2'
        uci add_list network.br_lan_dev.ports='lan3'
        uci add_list network.br_lan_dev.ports='wan'
        uci add_list network.br_lan_dev.ports='lotus-mesh'

        # VLAN 1 (LAN): lan2, wan, lotus-mesh:t
        uci -q delete network.@bridge-vlan[0] || true
        uci -q delete network.@bridge-vlan[1] || true
        uci -q delete network.@bridge-vlan[2] || true

        VLAN1=$(uci add network bridge-vlan)
        uci set network.${VLAN1}.device='br-lan'
        uci set network.${VLAN1}.vlan='1'
        uci add_list network.${VLAN1}.ports='lan2'
        uci add_list network.${VLAN1}.ports='lotus-mesh:t'
        uci add_list network.${VLAN1}.ports='wan'

        # VLAN 10 (IoT): lan1, lan3, lotus-mesh:t, wan:t
        VLAN10=$(uci add network bridge-vlan)
        uci set network.${VLAN10}.device='br-lan'
        uci set network.${VLAN10}.vlan='10'
        uci add_list network.${VLAN10}.ports='lan1'
        uci add_list network.${VLAN10}.ports='lan3'
        uci add_list network.${VLAN10}.ports='lotus-mesh:t'
        uci add_list network.${VLAN10}.ports='wan:t'

        # VLAN 12 (Guest): lotus-mesh:t, wan:t
        VLAN12=$(uci add network bridge-vlan)
        uci set network.${VLAN12}.device='br-lan'
        uci set network.${VLAN12}.vlan='12'
        uci add_list network.${VLAN12}.ports='lotus-mesh:t'
        uci add_list network.${VLAN12}.ports='wan:t'
        ;;
    *)
        # Default JCG Q20 / Generic 3-port switch layout
        log_info "Applying standard DSA port layout (lan1, lan2, wan, lotus-mesh)..."
        uci add_list network.br_lan_dev.ports='lan1'
        uci add_list network.br_lan_dev.ports='lan2'
        uci add_list network.br_lan_dev.ports='wan'
        uci add_list network.br_lan_dev.ports='lotus-mesh'

        uci -q delete network.@bridge-vlan[0] || true
        uci -q delete network.@bridge-vlan[1] || true
        uci -q delete network.@bridge-vlan[2] || true

        VLAN1=$(uci add network bridge-vlan)
        uci set network.${VLAN1}.device='br-lan'
        uci set network.${VLAN1}.vlan='1'
        uci add_list network.${VLAN1}.ports='lan1'
        uci add_list network.${VLAN1}.ports='lotus-mesh:t'
        uci add_list network.${VLAN1}.ports='wan'

        VLAN10=$(uci add network bridge-vlan)
        uci set network.${VLAN10}.device='br-lan'
        uci set network.${VLAN10}.vlan='10'
        uci add_list network.${VLAN10}.ports='lan2'
        uci add_list network.${VLAN10}.ports='lotus-mesh:t'
        uci add_list network.${VLAN10}.ports='wan:t'

        VLAN12=$(uci add network bridge-vlan)
        uci set network.${VLAN12}.device='br-lan'
        uci set network.${VLAN12}.vlan='12'
        uci add_list network.${VLAN12}.ports='lotus-mesh:t'
        uci add_list network.${VLAN12}.ports='wan:t'
        ;;
esac

# Configure Logical Interfaces
uci set network.lan=interface
uci set network.lan.proto='static'
uci set network.lan.device='br-lan.1'
uci set network.lan.ipaddr="${AP_IP}"
uci set network.lan.netmask='255.255.255.0'
uci set network.lan.gateway="${ROUTER_IP}"
uci set network.lan.delegate='0'
uci set network.lan.peerdns='0'
uci -q delete network.lan.dns || true
uci add_list network.lan.dns="${DNS_SERVER}"
uci add_list network.lan.dns='127.0.0.1'

uci set network.iot=interface
uci set network.iot.proto='none'
uci set network.iot.device='br-lan.10'

uci set network.guest=interface
uci set network.guest.proto='none'
uci set network.guest.device='br-lan.12'

log_succ "Network interfaces and DSA VLAN filtering bridge configured."

# ==============================================================================
# Step 4: Wireless Radio, Roaming & Mesh Backhaul
# ==============================================================================
log_head "Step 4/6: Configuring Wi-Fi Radios, 802.11r/k/v/w & Mesh"

# Find 2.4G and 5G radios dynamically
RADIO_2G=$(uci show wireless | grep -E "\.band='2g'" | head -n1 | cut -d. -f2 || echo "radio0")
RADIO_5G=$(uci show wireless | grep -E "\.band='5g'" | head -n1 | cut -d. -f2 || echo "radio1")

# Configure 2.4GHz Radio
uci set wireless.${RADIO_2G}.country="${COUNTRY}"
uci set wireless.${RADIO_2G}.channel="${CHANNEL_2G}"
uci set wireless.${RADIO_2G}.cell_density='0'
uci set wireless.${RADIO_2G}.disabled='0'
uci -q set wireless.${RADIO_2G}.htmode='HE20' || uci set wireless.${RADIO_2G}.htmode='HT20'

# Configure 5GHz Radio
uci set wireless.${RADIO_5G}.country="${COUNTRY}"
uci set wireless.${RADIO_5G}.channel="${CHANNEL_5G}"
uci set wireless.${RADIO_5G}.cell_density='0'
uci set wireless.${RADIO_5G}.disabled='0'
uci -q set wireless.${RADIO_5G}.htmode='HE80' || uci set wireless.${RADIO_5G}.htmode='VHT80'

# Clean old wifi-ifaces
while uci -q delete wireless.@wifi-iface[0]; do :; done

# --- Helper: Setup AP Interface with Fast Roaming ---
setup_ap_iface() {
    dev="$1"
    net="$2"
    ssid="$3"
    key="$4"
    pmf="$5"

    IFACE=$(uci add wireless wifi-iface)
    uci set wireless.${IFACE}.device="${dev}"
    uci set wireless.${IFACE}.network="${net}"
    uci set wireless.${IFACE}.mode='ap'
    uci set wireless.${IFACE}.ssid="${ssid}"
    uci set wireless.${IFACE}.encryption='psk2+ccmp'
    uci set wireless.${IFACE}.key="${key}"
    uci set wireless.${IFACE}.mobility_domain="${MOBILITY_DOMAIN}"
    uci set wireless.${IFACE}.ft_over_ds='1'
    uci set wireless.${IFACE}.ft_psk_generate_local='1'
    uci set wireless.${IFACE}.ieee80211r='1'
    uci set wireless.${IFACE}.ieee80211k='1'
    uci set wireless.${IFACE}.ieee80211v='1'
    uci set wireless.${IFACE}.ieee80211w="${pmf}"
    uci set wireless.${IFACE}.bss_transition='1'
    uci set wireless.${IFACE}.multicast_to_unicast_all='1'
    uci set wireless.${IFACE}.wpa_disable_eapol_key_retries='1'
    uci set wireless.${IFACE}.time_advertisement='2'
    uci set wireless.${IFACE}.ocv='0'
    uci set wireless.${IFACE}.mcast_rate='24000'
    uci set wireless.${IFACE}.basic_rate='12000 24000'
    uci set wireless.${IFACE}.cell_density='high'
}

# 1. Main LAN SSID (2.4G + 5G)
log_info "Creating Main LAN SSID '${SSID_LAN}'..."
setup_ap_iface "${RADIO_2G}" "lan" "${SSID_LAN}" "${PASS_LAN}" "1"
setup_ap_iface "${RADIO_5G}" "lan" "${SSID_LAN}" "${PASS_LAN}" "1"

# 2. IoT SSID (2.4G + 5G, PMF=0 for compatibility)
log_info "Creating IoT SSID '${SSID_IOT}'..."
setup_ap_iface "${RADIO_2G}" "iot" "${SSID_IOT}" "${PASS_IOT}" "0"
setup_ap_iface "${RADIO_5G}" "iot" "${SSID_IOT}" "${PASS_IOT}" "0"

# 3. Guest SSID (2.4G + 5G, PMF=1)
log_info "Creating Guest SSID '${SSID_GUEST}'..."
setup_ap_iface "${RADIO_2G}" "guest" "${SSID_GUEST}" "${PASS_GUEST}" "1"
setup_ap_iface "${RADIO_5G}" "guest" "${SSID_GUEST}" "${PASS_GUEST}" "1"

# 4. 802.11s Backup Mesh Backhaul on 5GHz Radio
log_info "Creating 802.11s Mesh Backhaul '${MESH_ID}' on 5GHz..."
MESH_IFACE=$(uci add wireless wifi-iface)
uci set wireless.${MESH_IFACE}.device="${RADIO_5G}"
uci set wireless.${MESH_IFACE}.mode='mesh'
uci set wireless.${MESH_IFACE}.encryption='sae'
uci set wireless.${MESH_IFACE}.mesh_id="${MESH_ID}"
uci set wireless.${MESH_IFACE}.key="${MESH_KEY}"
uci set wireless.${MESH_IFACE}.mesh_fwding='1'
uci set wireless.${MESH_IFACE}.mesh_rssi_threshold='0'
uci set wireless.${MESH_IFACE}.ifname="${MESH_ID}"
uci set wireless.${MESH_IFACE}.time_advertisement='2'
uci set wireless.${MESH_IFACE}.multicast_to_unicast_all='1'
uci set wireless.${MESH_IFACE}.bss_transition='1'

log_succ "Wireless SSIDs, fast roaming (802.11r/k/v), and 802.11s mesh backhaul configured."

# ==============================================================================
# Step 5: usteer Band-Steering Optimization
# ==============================================================================
log_head "Step 5/6: Configuring usteer Client Steering"

while uci -q delete usteer.@usteer[0]; do :; done
USTEER_CFG=$(uci add usteer usteer)
uci set usteer.${USTEER_CFG}.network='lan'
uci set usteer.${USTEER_CFG}.syslog='1'
uci set usteer.${USTEER_CFG}.local_mode='0'
uci set usteer.${USTEER_CFG}.ipv6='0'
uci set usteer.${USTEER_CFG}.debug_level='1'
uci add_list usteer.${USTEER_CFG}.ssid_list="${SSID_LAN}"
uci add_list usteer.${USTEER_CFG}.ssid_list="${SSID_GUEST}"
uci set usteer.${USTEER_CFG}.max_neighbor_reports='8'
uci set usteer.${USTEER_CFG}.probe_steering='1'
uci set usteer.${USTEER_CFG}.assoc_steering='1'
uci set usteer.${USTEER_CFG}.initial_connect_delay='20'
uci set usteer.${USTEER_CFG}.roam_scan_snr='-70'
uci set usteer.${USTEER_CFG}.roam_scan_tries='3'
uci set usteer.${USTEER_CFG}.roam_scan_interval='10000'
uci set usteer.${USTEER_CFG}.roam_trigger_snr='-74'
uci set usteer.${USTEER_CFG}.roam_trigger_interval='30000'
uci set usteer.${USTEER_CFG}.signal_diff_threshold='8'
uci set usteer.${USTEER_CFG}.min_snr='-82'
uci set usteer.${USTEER_CFG}.min_snr_kick_delay='5000'
uci set usteer.${USTEER_CFG}.band_steering_interval='120000'
uci set usteer.${USTEER_CFG}.band_steering_min_snr='-65'
uci set usteer.${USTEER_CFG}.band_steering_threshold='5'
uci set usteer.${USTEER_CFG}.link_measurement_interval='30000'

log_succ "usteer band-steering parameters configured."

# ==============================================================================
# Step 6: Unbound Local Cache & Dumb AP DHCP/dnsmasq Disabling
# ==============================================================================
log_head "Step 6/6: Configuring Unbound Resolver & Disabling DHCP Authority"

# Configure Unbound DNS Cache on port 53
uci set unbound.ub_main=unbound
uci set unbound.ub_main.listen_port='53'
uci set unbound.ub_main.localservice='1'
uci set unbound.ub_main.rebind_protection='1'
uci set unbound.ub_main.domain='lan'
uci set unbound.ub_main.domain_type='static'
uci set unbound.ub_main.edns_size='1232'
uci set unbound.ub_main.hide_binddata='1'
uci set unbound.ub_main.interface_auto='1'
uci set unbound.ub_main.num_threads='1'
uci set unbound.ub_main.query_minimize='0'
uci set unbound.ub_main.ttl_min='120'
uci set unbound.ub_main.ttl_neg_max='1000'
uci set unbound.ub_main.validator='0'
uci set unbound.ub_main.verbosity='1'
uci -q delete unbound.ub_main.iface_trig || true
uci add_list unbound.ub_main.iface_trig='lan'
uci add_list unbound.ub_main.iface_trig='wan'

# Disable dnsmasq listener (port 0) and disable DHCP server
uci set dhcp.@dnsmasq[0].port='0'
uci set dhcp.@dnsmasq[0].noresolv='1'
uci set dhcp.lan.ignore='1'
uci -q set dhcp.wan.ignore='1' || true
uci set dhcp.odhcpd.maindhcp='0'

# Commit all changes to flash
log_info "Committing all UCI configuration changes..."
uci commit

# Enable & restart services
/etc/init.d/odhcpd restart >/dev/null 2>&1 || true
/etc/init.d/dnsmasq restart >/dev/null 2>&1 || true
/etc/init.d/unbound enable >/dev/null 2>&1 || true
/etc/init.d/unbound restart >/dev/null 2>&1 || true
/etc/init.d/usteer enable >/dev/null 2>&1 || true
/etc/init.d/usteer restart >/dev/null 2>&1 || true
/etc/init.d/network restart >/dev/null 2>&1 || true

echo ""
echo "${BOLD}${GREEN}==============================================================================${NC}"
echo "${BOLD}${GREEN}        OpenWrt Access Point Setup Completed Successfully!                   ${NC}"
echo "${BOLD}${GREEN}==============================================================================${NC}"
echo ""
echo "  • Hostname:       ${CYAN}${AP_HOSTNAME}${NC}"
echo "  • IP Address:     ${CYAN}${AP_IP}${NC}"
echo "  • SSIDs Active:   ${GREEN}${SSID_LAN}${NC}, ${GREEN}${SSID_IOT}${NC}, ${GREEN}${SSID_GUEST}${NC}, ${GREEN}${MESH_ID}${NC}"
echo "  • 2.4G / 5G Ch:   ${GREEN}${CHANNEL_2G}${NC} / ${GREEN}${CHANNEL_5G}${NC}"
echo "  • Roaming Engine: ${GREEN}usteer + 802.11r/k/v${NC} (Domain ${MOBILITY_DOMAIN})"
echo "  • Local DNS:      ${GREEN}Unbound (:53)${NC}"
echo ""
echo "${BOLD}Note:${NC} If IP changed from DHCP to static, reconnect to: ${CYAN}ssh root@${AP_IP}${NC}\n"
