#!/bin/sh
# ==============================================================================
# Universal OpenWrt Network & 802.11s Mesh Provisioning Script
# https://github.com/hungngit2/home-network
# ==============================================================================
# Features:
#   1. DSA & swconfig Hardware Switch Auto-Detection:
#      - VLAN 1 (LAN): DHCP client on br-lan / br-lan.1
#      - VLAN 10 (IoT): br-iot / br-lan.10
#      - VLAN 12 (Guest): br-guest / br-lan.12
#   2. User-Inputted 802.11s Mesh Wi-Fi Backhaul:
#      - Interactive prompt for Mesh Name (Mesh ID) & Mesh Password
#      - 5GHz SAE encrypted mesh failover bridge (${MESH_ID}:t across VLAN 1, 10, 12)
# ==============================================================================

set -eu

BOARD_MODEL=$(cat /tmp/sysinfo/model 2>/dev/null || cat /proc/cpuinfo | grep 'machine' | cut -d: -f2 | xargs || echo "Generic OpenWrt AP")
echo "=== Universal Network & Mesh Setup for: ${BOARD_MODEL} ==="

# --- Prompt for Mesh Wi-Fi Name & Password ---
MESH_ID="${MESH_ID:-lotus-mesh}"
MESH_KEY="${MESH_KEY:-Lotus@Mesh}"
CHANNEL_5G="${CHANNEL_5G:-36}"

if [ "${NON_INTERACTIVE:-false}" != "true" ]; then
    printf "Enter Mesh Wi-Fi Name (Mesh ID) [%s]: " "${MESH_ID}"
    read user_mesh_id < /dev/tty 2>/dev/null || user_mesh_id=""
    if [ -n "${user_mesh_id}" ]; then
        MESH_ID="${user_mesh_id}"
    fi

    printf "Enter Mesh Wi-Fi Password (SAE Key) [%s]: " "${MESH_KEY}"
    read user_mesh_key < /dev/tty 2>/dev/null || user_mesh_key=""
    if [ -n "${user_mesh_key}" ]; then
        MESH_KEY="${user_mesh_key}"
    fi
fi

echo ">> Using Mesh ID:   [${MESH_ID}]"
echo ">> Using 5GHz Ch:   [${CHANNEL_5G}]"

# Clean up existing network interfaces, devices, and switch sections
while uci -q delete network.@switch_vlan[0]; do :; done
while uci -q delete network.@switch[0]; do :; done
while uci -q delete network.@bridge-vlan[0]; do :; done
while uci -q delete network.@device[0]; do :; done
uci -q delete network.wan || true
uci -q delete network.wan6 || true
uci -q delete network.lan || true
uci -q delete network.iot || true
uci -q delete network.guest || true
# Loopback & Globals
uci set network.loopback=interface
uci set network.loopback.device='lo'
uci set network.loopback.proto='static'
uci set network.loopback.ipaddr='127.0.0.1'
uci set network.loopback.netmask='255.0.0.0'

uci -q set network.globals=globals || true
uci -q set network.globals.packet_steering='1' || true

# --- Detect Switch Architecture ---
HAS_SWCONFIG=false
if command -v swconfig >/dev/null 2>&1 && swconfig list 2>/dev/null | grep -q 'switch'; then
    HAS_SWCONFIG=true
fi

DSA_LAN_PORTS=$(ls -d /sys/class/net/lan* 2>/dev/null | xargs -n1 basename 2>/dev/null || true)
DSA_WAN_PORT=$(ls -d /sys/class/net/wan* 2>/dev/null | xargs -n1 basename 2>/dev/null || true)

if [ "${HAS_SWCONFIG}" = "true" ]; then
    echo ">> Detected Architecture: [swconfig Hardware Switch]"
    
    SW=$(uci add network switch)
    uci set network.${SW}.name='switch0'
    uci set network.${SW}.reset='1'
    uci set network.${SW}.enable_vlan='1'

    case "${BOARD_MODEL}" in
        *"Xiaomi"*"R3"*|*"Mi Router 3"*|*"MT7620"*)
            # Xiaomi Mi Router 3 (Port 0=WAN, 1=LAN1, 4=LAN2, 6=CPU all on VLAN 1)
            V1=$(uci add network switch_vlan)
            uci set network.${V1}.device='switch0'
            uci set network.${V1}.vlan='1'
            uci set network.${V1}.ports='0 1 4 6t'

            V10=$(uci add network switch_vlan)
            uci set network.${V10}.device='switch0'
            uci set network.${V10}.vlan='10'
            uci set network.${V10}.ports='0t 6t'

            V12=$(uci add network switch_vlan)
            uci set network.${V12}.device='switch0'
            uci set network.${V12}.vlan='12'
            uci set network.${V12}.ports='0t 6t'
            ;;
        *)
            # Standard generic swconfig layout (all ports on VLAN 1)
            V1=$(uci add network switch_vlan)
            uci set network.${V1}.device='switch0'
            uci set network.${V1}.vlan='1'
            uci set network.${V1}.ports='0 1 2 3 4 5t 6t' 2>/dev/null || uci set network.${V1}.ports='0 1 2 3 4 6t'

            V10=$(uci add network switch_vlan)
            uci set network.${V10}.device='switch0'
            uci set network.${V10}.vlan='10'
            uci set network.${V10}.ports='0t 5t 6t' 2>/dev/null || uci set network.${V10}.ports='0t 6t'

            V12=$(uci add network switch_vlan)
            uci set network.${V12}.device='switch0'
            uci set network.${V12}.vlan='12'
            uci set network.${V12}.ports='0t 5t 6t' 2>/dev/null || uci set network.${V12}.ports='0t 6t'
            ;;
    esac

    # Bridges for swconfig (eth0.1, eth0.10, eth0.12 + Mesh VLANs)
    DEV_LAN=$(uci add network device)
    uci set network.${DEV_LAN}.name='br-lan'
    uci set network.${DEV_LAN}.type='bridge'
    uci set network.${DEV_LAN}.stp='1'
    uci set network.${DEV_LAN}.igmp_snooping='1'
    uci add_list network.${DEV_LAN}.ports='eth0.1'
    uci add_list network.${DEV_LAN}.ports="${MESH_ID}.1"

    DEV_IOT=$(uci add network device)
    uci set network.${DEV_IOT}.name='br-iot'
    uci set network.${DEV_IOT}.type='bridge'
    uci set network.${DEV_IOT}.stp='1'
    uci set network.${DEV_IOT}.igmp_snooping='1'
    uci add_list network.${DEV_IOT}.ports='eth0.10'
    uci add_list network.${DEV_IOT}.ports="${MESH_ID}.10"

    DEV_GUEST=$(uci add network device)
    uci set network.${DEV_GUEST}.name='br-guest'
    uci set network.${DEV_GUEST}.type='bridge'
    uci set network.${DEV_GUEST}.stp='1'
    uci set network.${DEV_GUEST}.igmp_snooping='1'
    uci add_list network.${DEV_GUEST}.ports='eth0.12'
    uci add_list network.${DEV_GUEST}.ports="${MESH_ID}.12"

    uci set network.lan=interface
    uci set network.lan.device='br-lan'
    uci set network.lan.proto='dhcp'

    uci set network.iot=interface
    uci set network.iot.device='br-iot'
    uci set network.iot.proto='none'

    uci set network.guest=interface
    uci set network.guest.device='br-guest'
    uci set network.guest.proto='none'

elif [ -n "${DSA_LAN_PORTS}" ] || [ -n "${DSA_WAN_PORT}" ]; then
    echo ">> Detected Architecture: [DSA (Distributed Switch Architecture)]"

    uci set network.br_lan_dev=device
    uci set network.br_lan_dev.name='br-lan'
    uci set network.br_lan_dev.type='bridge'
    uci set network.br_lan_dev.stp='1'
    uci set network.br_lan_dev.igmp_snooping='1'
    uci set network.br_lan_dev.vlan_filtering='1'

    for p in ${DSA_LAN_PORTS} ${DSA_WAN_PORT}; do
        uci add_list network.br_lan_dev.ports="${p}"
    done
    uci add_list network.br_lan_dev.ports="${MESH_ID}"

    case "${BOARD_MODEL}" in
        *"Redmi"*"AC2100"*)
            VLAN1=$(uci add network bridge-vlan)
            uci set network.${VLAN1}.device='br-lan'
            uci set network.${VLAN1}.vlan='1'
            uci add_list network.${VLAN1}.ports='lan2'
            uci add_list network.${VLAN1}.ports="${MESH_ID}:t"
            uci add_list network.${VLAN1}.ports='wan'

            VLAN10=$(uci add network bridge-vlan)
            uci set network.${VLAN10}.device='br-lan'
            uci set network.${VLAN10}.vlan='10'
            uci add_list network.${VLAN10}.ports='lan1'
            uci add_list network.${VLAN10}.ports='lan3'
            uci add_list network.${VLAN10}.ports="${MESH_ID}:t"
            uci add_list network.${VLAN10}.ports='wan:t'

            VLAN12=$(uci add network bridge-vlan)
            uci set network.${VLAN12}.device='br-lan'
            uci set network.${VLAN12}.vlan='12'
            uci add_list network.${VLAN12}.ports="${MESH_ID}:t"
            uci add_list network.${VLAN12}.ports='wan:t'
            ;;
        *)
            # JCG Q20 / Standard DSA AP layout (all physical ports on VLAN 1)
            VLAN1=$(uci add network bridge-vlan)
            uci set network.${VLAN1}.device='br-lan'
            uci set network.${VLAN1}.vlan='1'
            uci add_list network.${VLAN1}.ports='lan1'
            [ -d /sys/class/net/lan2 ] && uci add_list network.${VLAN1}.ports='lan2'
            uci add_list network.${VLAN1}.ports="${MESH_ID}:t"
            [ -n "${DSA_WAN_PORT}" ] && uci add_list network.${VLAN1}.ports="${DSA_WAN_PORT}"

            VLAN10=$(uci add network bridge-vlan)
            uci set network.${VLAN10}.device='br-lan'
            uci set network.${VLAN10}.vlan='10'
            uci add_list network.${VLAN10}.ports="${MESH_ID}:t"
            [ -n "${DSA_WAN_PORT}" ] && uci add_list network.${VLAN10}.ports="${DSA_WAN_PORT}:t"

            VLAN12=$(uci add network bridge-vlan)
            uci set network.${VLAN12}.device='br-lan'
            uci set network.${VLAN12}.vlan='12'
            uci add_list network.${VLAN12}.ports="${MESH_ID}:t"
            [ -n "${DSA_WAN_PORT}" ] && uci add_list network.${VLAN12}.ports="${DSA_WAN_PORT}:t"
            ;;
    esac

    uci set network.lan=interface
    uci set network.lan.device='br-lan.1'
    uci set network.lan.proto='dhcp'

    uci set network.iot=interface
    uci set network.iot.proto='none'
    uci set network.iot.device='br-lan.10'

    uci set network.guest=interface
    uci set network.guest.proto='none'
    uci set network.guest.device='br-lan.12'

else
    echo ">> Detected Architecture: [Generic Linux Bridge / Single NIC]"
    PRIMARY_ETH=$(ip -o link show | awk -F': ' '{print $2}' | grep -v -E 'lo|br-|wlan|radio|phy' | head -n1 || echo "eth0")

    DEV_LAN=$(uci add network device)
    uci set network.${DEV_LAN}.name='br-lan'
    uci set network.${DEV_LAN}.type='bridge'
    uci set network.${DEV_LAN}.stp='1'
    uci add_list network.${DEV_LAN}.ports="${PRIMARY_ETH}.1" 2>/dev/null || uci add_list network.${DEV_LAN}.ports="${PRIMARY_ETH}"

    DEV_IOT=$(uci add network device)
    uci set network.${DEV_IOT}.name='br-iot'
    uci set network.${DEV_IOT}.type='bridge'
    uci set network.${DEV_IOT}.stp='1'
    uci add_list network.${DEV_IOT}.ports="${PRIMARY_ETH}.10" 2>/dev/null || true

    DEV_GUEST=$(uci add network device)
    uci set network.${DEV_GUEST}.name='br-guest'
    uci set network.${DEV_GUEST}.type='bridge'
    uci set network.${DEV_GUEST}.stp='1'
    uci add_list network.${DEV_GUEST}.ports="${PRIMARY_ETH}.12" 2>/dev/null || true

    uci set network.lan=interface
    uci set network.lan.device='br-lan'
    uci set network.lan.proto='dhcp'

    uci set network.iot=interface
    uci set network.iot.device='br-iot'
    uci set network.iot.proto='none'

    uci set network.guest=interface
    uci set network.guest.device='br-guest'
    uci set network.guest.proto='none'
fi

uci commit network

# Silence local DHCP server on AP (MikroTik is sole DHCP authority)
if [ -f /etc/config/dhcp ]; then
    uci -q set dhcp.lan.ignore='1' || true
    uci -q set dhcp.lan.ra='disabled' || true
    uci -q set dhcp.lan.dhcpv6='disabled' || true
    uci -q set dhcp.odhcpd.maindhcp='0' || true
    uci commit dhcp 2>/dev/null || true
fi

# ==============================================================================
# Configure 802.11s Wireless Mesh Interface
# ==============================================================================
echo "=== Configuring 802.11s Wireless Mesh (${MESH_ID}) ==="

# Dynamically identify 5GHz radio device
RADIO_5G=""
for r in $(uci show wireless 2>/dev/null | grep '=wifi-device' | cut -d. -f2 | cut -d= -f1); do
    BAND=$(uci -q get wireless.${r}.band || echo "")
    HTMODE=$(uci -q get wireless.${r}.htmode || echo "")
    CH=$(uci -q get wireless.${r}.channel || echo "")
    if [ "${BAND}" = "5g" ] || [ "${HTMODE}" = "VHT80" ] || [ "${HTMODE}" = "HE80" ] || [ "${HTMODE}" = "VHT160" ] || [ "${HTMODE}" = "HE160" ] || [ "${CH}" = "36" ] || [ "${CH}" -gt 14 ] 2>/dev/null; then
        RADIO_5G="${r}"
        break
    fi
done

# Fallback: if radio1 is 5G, or radio0 is 5G
if [ -z "${RADIO_5G}" ]; then
    if uci -q get wireless.radio0.band | grep -q '5g'; then
        RADIO_5G="radio0"
    else
        RADIO_5G="radio1"
    fi
fi

# Ensure 5GHz radio is enabled on channel 36 with VHT80 default (or HE80 if Wi-Fi 6)
uci set wireless.${RADIO_5G}.country='US'
uci set wireless.${RADIO_5G}.channel="${CHANNEL_5G}"
uci set wireless.${RADIO_5G}.cell_density='0'
uci set wireless.${RADIO_5G}.disabled='0'
if uci -q get wireless.${RADIO_5G}.htmode | grep -q 'HE'; then
    uci set wireless.${RADIO_5G}.htmode='HE80'
else
    uci set wireless.${RADIO_5G}.htmode='VHT80'
fi

# Remove existing mesh ifaces
for iface in $(uci show wireless 2>/dev/null | grep "\.mode='mesh'" | cut -d. -f2 | cut -d= -f1); do
    uci -q delete wireless.${iface} || true
done

# Add 802.11s Mesh Interface
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

uci commit wireless

# ==============================================================================
# Hardware Link-State Failover Daemon (for swconfig devices)
# ==============================================================================
# swconfig switch chips (MT7620/MT7628) filter BPDU frames before the CPU,
# preventing software STP from reliably blocking the wireless mesh when wired.
# This daemon monitors physical switch ports and toggles the mesh backhaul:
#   - Wired Ethernet plugged in: disables mesh backhaul (eliminates L2 loop)
#   - Wired Ethernet unplugged: enables mesh backhaul (instant failover)
# ==============================================================================
if [ "${HAS_SWCONFIG}" = "true" ]; then
    echo ">> Installing hardware link-state failover daemon for swconfig..."
    cat <<EOF > /usr/sbin/mesh-failover-daemon
#!/bin/sh
STATE="init"

while true; do
    # Check if any physical switch port is link up (excluding CPU port 6)
    if swconfig dev switch0 show 2>/dev/null | grep -E 'Port [0-5]:' -A 15 | grep -q 'link:.*link:up'; then
        WIRED="up"
    else
        WIRED="down"
    fi

    if [ "\$WIRED" = "up" ] && [ "\$STATE" != "wired" ]; then
        ip link set dev ${MESH_ID}.1 down 2>/dev/null || true
        ip link set dev ${MESH_ID}.10 down 2>/dev/null || true
        ip link set dev ${MESH_ID}.12 down 2>/dev/null || true
        logger -t mesh-failover "Wired Ethernet UP: Disabled mesh backhaul to prevent L2 loop"
        STATE="wired"
    elif [ "\$WIRED" = "down" ] && [ "\$STATE" != "mesh" ]; then
        ip link set dev ${MESH_ID}.1 up 2>/dev/null || true
        ip link set dev ${MESH_ID}.10 up 2>/dev/null || true
        ip link set dev ${MESH_ID}.12 up 2>/dev/null || true
        logger -t mesh-failover "Wired Ethernet DOWN: Enabled mesh backhaul failover"
        STATE="mesh"
    fi

    sleep 1
done
EOF
    chmod +x /usr/sbin/mesh-failover-daemon

    cat <<'EOF' > /etc/init.d/mesh-failover
#!/bin/sh /etc/rc.common
START=99
STOP=10

USE_PROCD=1

start_service() {
    procd_open_instance
    procd_set_param command /usr/sbin/mesh-failover-daemon
    procd_set_param respawn
    procd_close_instance
}
EOF
    chmod +x /etc/init.d/mesh-failover
    /etc/init.d/mesh-failover enable
    /etc/init.d/mesh-failover restart >/dev/null 2>&1 || true
fi

echo "Applying network & mesh reload..."
wifi reload >/dev/null 2>&1 || true
(/etc/init.d/network restart >/dev/null 2>&1) &
echo "Done! Mesh '${MESH_ID}' and Network configured."
