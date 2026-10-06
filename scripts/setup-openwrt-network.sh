#!/bin/sh
# ==============================================================================
# Universal OpenWrt Network & 802.11s Mesh Provisioning Script
# https://github.com/hungngit2/home-network
# ==============================================================================
# Features:
#   1. Dumb AP Firewall & Single DHCP Authority (MikroTik 10.0.0.254):
#      - Wipes routing zones to eliminate management lockout (SSH/LuCI input ACCEPT)
#      - Disables local dnsmasq / odhcpd
#   2. Multi-VLAN Network Auto-Detection (DSA & swconfig):
#      - VLAN 1 (LAN): DHCP client on br-lan / br-lan.1
#      - VLAN 10 (IoT): br-iot / br-lan.10
#      - VLAN 12 (Guest): br-guest / br-lan.12
#   3. 802.11s Mesh Wi-Fi Backhaul Only:
#      - Configures 5GHz 802.11s SAE encrypted mesh backhaul (${MESH_ID})
#      - Leaves client Wi-Fi AP SSIDs untouched
#   4. Hardware Link-State Failover Daemon (for swconfig chips like MT7620/MT7628):
#      - Prevents L2 broadcast storm by dynamically detaching mesh backhaul when wired
#      - Instantly engages wireless mesh failover when Ethernet is unplugged
# ==============================================================================

set -eu

BOARD_MODEL=$(cat /tmp/sysinfo/model 2>/dev/null || cat /proc/cpuinfo | grep 'machine' | cut -d: -f2 | xargs || echo "Generic OpenWrt AP")
echo "=== Universal Network & 802.11s Mesh Setup for: ${BOARD_MODEL} ==="

# --- Prompt for Mesh Wi-Fi Name & Password ---
MESH_ID="${MESH_ID:-}"
MESH_KEY="${MESH_KEY:-}"
CHANNEL_5G="${CHANNEL_5G:-36}"
COUNTRY="${COUNTRY:-US}"
ADD_VLANS="${ADD_VLANS:-}"

if [ "${NON_INTERACTIVE:-false}" != "true" ]; then
    while [ -z "${MESH_ID}" ]; do
        printf "Enter Mesh Wi-Fi Name (Mesh ID): "
        read MESH_ID < /dev/tty 2>/dev/null || read MESH_ID || true
    done

    while [ -z "${MESH_KEY}" ]; do
        printf "Enter Mesh Wi-Fi Password (SAE Key): "
        read MESH_KEY < /dev/tty 2>/dev/null || read MESH_KEY || true
    done

    if [ -z "${ADD_VLANS}" ]; then
        printf "Configure additional VLANs (VLAN 10 IoT & VLAN 12 Guest)? [y/N]: "
        read user_add_vlans < /dev/tty 2>/dev/null || read user_add_vlans || true
        case "${user_add_vlans}" in
            [yY]|[yY][eE][sS]) ADD_VLANS="true" ;;
            *) ADD_VLANS="false" ;;
        esac
    fi
fi

ADD_VLANS="${ADD_VLANS:-false}"

if [ -z "${MESH_ID}" ] || [ -z "${MESH_KEY}" ]; then
    echo "Error: MESH_ID and MESH_KEY must be provided (inputted or set via environment variable)." >&2
    exit 1
fi

echo ">> Using Mesh ID:   [${MESH_ID}]"
echo ">> Using 5GHz Ch:   [${CHANNEL_5G}]"
echo ">> Additional VLANs:[${ADD_VLANS}]"

# ==============================================================================
# Step 1: Configure Dumb AP Firewall (Prevents Management Lockout)
# ==============================================================================
if [ -f /etc/config/firewall ]; then
    echo ">> Configuring Dumb AP Firewall (Input/Output ACCEPT)..."
    while uci -q delete firewall.@rule[0]; do :; done
    while uci -q delete firewall.@forwarding[0]; do :; done
    while uci -q delete firewall.@zone[0]; do :; done
    uci set firewall.@defaults[0].input='ACCEPT'
    uci set firewall.@defaults[0].output='ACCEPT'
    uci set firewall.@defaults[0].forward='REJECT'
    uci set firewall.@defaults[0].synflood_protect='1'
    uci set firewall.@defaults[0].flow_offloading='1'
    uci commit firewall
fi

# ==============================================================================
# Step 2: Configure Network, Switch & VLAN Bridges
# ==============================================================================
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
            # Xiaomi Mi Router 3 (Port 0=WAN, 1=LAN1, 4=LAN2, 6=CPU GMAC)
            V1=$(uci add network switch_vlan)
            uci set network.${V1}.device='switch0'
            uci set network.${V1}.vlan='1'
            uci set network.${V1}.ports='0 1 4 6t'

            if [ "${ADD_VLANS}" = "true" ]; then
                V10=$(uci add network switch_vlan)
                uci set network.${V10}.device='switch0'
                uci set network.${V10}.vlan='10'
                uci set network.${V10}.ports='0t 1t 4t 6t'

                V12=$(uci add network switch_vlan)
                uci set network.${V12}.device='switch0'
                uci set network.${V12}.vlan='12'
                uci set network.${V12}.ports='0t 1t 4t 6t'
            fi
            ;;
        *)
            # Standard generic swconfig layout (all ports on VLAN 1)
            V1=$(uci add network switch_vlan)
            uci set network.${V1}.device='switch0'
            uci set network.${V1}.vlan='1'
            uci set network.${V1}.ports='0 1 2 3 4 5t 6t' 2>/dev/null || uci set network.${V1}.ports='0 1 2 3 4 6t'

            if [ "${ADD_VLANS}" = "true" ]; then
                V10=$(uci add network switch_vlan)
                uci set network.${V10}.device='switch0'
                uci set network.${V10}.vlan='10'
                uci set network.${V10}.ports='0t 1t 2t 3t 4t 5t 6t' 2>/dev/null || uci set network.${V10}.ports='0t 1t 2t 3t 4t 6t'

                V12=$(uci add network switch_vlan)
                uci set network.${V12}.device='switch0'
                uci set network.${V12}.vlan='12'
                uci set network.${V12}.ports='0t 1t 2t 3t 4t 5t 6t' 2>/dev/null || uci set network.${V12}.ports='0t 1t 2t 3t 4t 6t'
            fi
            ;;
    esac

    # Bridges for swconfig (eth0.1, and conditionally eth0.10, eth0.12)
    # STP is set to 0 on swconfig bridges because mesh-failover-daemon manages
    # uplink mutual-exclusion, avoiding 16s STP learning delay during failover.
    DEV_LAN=$(uci add network device)
    uci set network.${DEV_LAN}.name='br-lan'
    uci set network.${DEV_LAN}.type='bridge'
    uci set network.${DEV_LAN}.stp='0'
    uci set network.${DEV_LAN}.igmp_snooping='1'
    uci add_list network.${DEV_LAN}.ports='eth0.1'

    uci set network.lan=interface
    uci set network.lan.device='br-lan'
    uci set network.lan.proto='dhcp'
    uci set network.lan.peerdns='0'
    uci add_list network.lan.dns='127.0.0.1'

    if [ "${ADD_VLANS}" = "true" ]; then
        DEV_IOT=$(uci add network device)
        uci set network.${DEV_IOT}.name='br-iot'
        uci set network.${DEV_IOT}.type='bridge'
        uci set network.${DEV_IOT}.stp='0'
        uci set network.${DEV_IOT}.igmp_snooping='1'
        uci add_list network.${DEV_IOT}.ports='eth0.10'

        DEV_GUEST=$(uci add network device)
        uci set network.${DEV_GUEST}.name='br-guest'
        uci set network.${DEV_GUEST}.type='bridge'
        uci set network.${DEV_GUEST}.stp='0'
        uci set network.${DEV_GUEST}.igmp_snooping='1'
        uci add_list network.${DEV_GUEST}.ports='eth0.12'

        uci set network.iot=interface
        uci set network.iot.device='br-iot'
        uci set network.iot.proto='none'

        uci set network.guest=interface
        uci set network.guest.device='br-guest'
        uci set network.guest.proto='none'
    fi

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

            if [ "${ADD_VLANS}" = "true" ]; then
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
            fi
            ;;
        *)
            # Standard DSA AP layout (all physical ports on VLAN 1)
            VLAN1=$(uci add network bridge-vlan)
            uci set network.${VLAN1}.device='br-lan'
            uci set network.${VLAN1}.vlan='1'
            for p in ${DSA_LAN_PORTS}; do
                uci add_list network.${VLAN1}.ports="${p}"
            done
            uci add_list network.${VLAN1}.ports="${MESH_ID}:t"
            [ -n "${DSA_WAN_PORT}" ] && uci add_list network.${VLAN1}.ports="${DSA_WAN_PORT}"

            if [ "${ADD_VLANS}" = "true" ]; then
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
            fi
            ;;
    esac

    uci set network.lan=interface
    uci set network.lan.device='br-lan.1'
    uci set network.lan.proto='dhcp'
    uci set network.lan.peerdns='0'
    uci add_list network.lan.dns='127.0.0.1'

    if [ "${ADD_VLANS}" = "true" ]; then
        uci set network.iot=interface
        uci set network.iot.proto='none'
        uci set network.iot.device='br-lan.10'

        uci set network.guest=interface
        uci set network.guest.proto='none'
        uci set network.guest.device='br-lan.12'
    fi

else
    echo ">> Detected Architecture: [Generic Linux Bridge / Single NIC]"
    PRIMARY_ETH=$(ip -o link show | awk -F': ' '{print $2}' | grep -v -E 'lo|br-|wlan|radio|phy' | head -n1 || echo "eth0")

    DEV_LAN=$(uci add network device)
    uci set network.${DEV_LAN}.name='br-lan'
    uci set network.${DEV_LAN}.type='bridge'
    uci set network.${DEV_LAN}.stp='1'
    uci add_list network.${DEV_LAN}.ports="${PRIMARY_ETH}.1" 2>/dev/null || uci add_list network.${DEV_LAN}.ports="${PRIMARY_ETH}"

    uci set network.lan=interface
    uci set network.lan.device='br-lan'
    uci set network.lan.proto='dhcp'
    uci set network.lan.peerdns='0'
    uci add_list network.lan.dns='127.0.0.1'

    if [ "${ADD_VLANS}" = "true" ]; then
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

        uci set network.iot=interface
        uci set network.iot.device='br-iot'
        uci set network.iot.proto='none'

        uci set network.guest=interface
        uci set network.guest.device='br-guest'
        uci set network.guest.proto='none'
    fi
fi

uci commit network

# Silence local DHCP server on AP (MikroTik is sole DHCP authority)
if [ -f /etc/config/dhcp ]; then
    uci -q set dhcp.lan.ignore='1' || true
    uci -q set dhcp.lan.ra='disabled' || true
    uci -q set dhcp.lan.dhcpv6='disabled' || true
    uci -q set dhcp.odhcpd.maindhcp='0' || true
    uci commit dhcp 2>/dev/null || true
    /etc/init.d/dnsmasq disable 2>/dev/null || true
    /etc/init.d/odhcpd disable 2>/dev/null || true
fi

# ==============================================================================
# Step 3: Configure 802.11s Wireless Mesh Backhaul Only
# ==============================================================================
echo "=== Configuring 802.11s Wireless Mesh Backhaul (${MESH_ID}) ==="

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

# Fallback
if [ -z "${RADIO_5G}" ]; then
    if uci -q get wireless.radio0.band | grep -q '5g'; then
        RADIO_5G="radio0"
    else
        RADIO_5G="radio1"
    fi
fi

# Ensure 5GHz radio is enabled on channel 36
uci set wireless.${RADIO_5G}.country="${COUNTRY}"
uci set wireless.${RADIO_5G}.channel="${CHANNEL_5G}"
uci set wireless.${RADIO_5G}.cell_density='0'
uci set wireless.${RADIO_5G}.disabled='0'
if uci -q get wireless.${RADIO_5G}.htmode | grep -q 'HE'; then
    uci set wireless.${RADIO_5G}.htmode='HE80'
else
    uci set wireless.${RADIO_5G}.htmode='VHT80'
fi

# Remove existing mesh ifaces only (leaves all client Wi-Fi APs untouched)
for iface in $(uci show wireless 2>/dev/null | grep "\.mode='mesh'" | cut -d. -f2 | cut -d= -f1); do
    uci -q delete wireless.${iface} || true
done

# Provision 802.11s Mesh Interface on 5GHz
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
# Step 4: Hardware Link-State Failover Daemon (for swconfig devices)
# ==============================================================================
# swconfig switch chips (MT7620/MT7628) filter BPDU frames before the CPU,
# preventing software STP from reliably blocking the wireless mesh when wired.
# This daemon monitors physical switch ports and toggles the mesh backhaul:
#   - Wired Ethernet plugged in: detaches mesh backhaul (eliminates L2 loop)
#   - Wired Ethernet unplugged: attaches mesh backhaul (instant failover)
# ==============================================================================
if [ "${HAS_SWCONFIG}" = "true" ]; then
    echo ">> Installing hardware link-state failover daemon for swconfig..."
    # Kill any stale or duplicate instances before starting fresh
    killall -9 mesh-failover-daemon 2>/dev/null || true

    # Determine physical uplink WAN port for swconfig
    case "${BOARD_MODEL}" in
        *"Xiaomi"*"R3"*|*"Mi Router 3"*|*"MT7620"*)
            SW_WAN_PORT="0"
            ;;
        *)
            SW_WAN_PORT="0"
            ;;
    esac

    cat <<EOF > /usr/sbin/mesh-failover-daemon
#!/bin/sh
STATE="init"
SW_WAN_PORT="${SW_WAN_PORT}"

while true; do
    # Check physical uplink port (Port 0 on Xiaomi R3)
    WIRED="down"
    if swconfig dev switch0 port \${SW_WAN_PORT} get link 2>/dev/null | grep -q 'link:up'; then
        WIRED="up"
    fi

    if [ "\$WIRED" = "up" ] && [ "\$STATE" != "wired" ]; then
        # Restore wired switch interfaces to bridges
        ip link set dev eth0.1 up 2>/dev/null || true
        brctl addif br-lan eth0.1 2>/dev/null || true
        brctl delif br-lan ${MESH_ID}.1 2>/dev/null || true
        ip link set dev ${MESH_ID}.1 down 2>/dev/null || true

        if [ -d /sys/class/net/br-iot ]; then
            ip link set dev eth0.10 up 2>/dev/null || true
            brctl addif br-iot eth0.10 2>/dev/null || true
            brctl delif br-iot ${MESH_ID}.10 2>/dev/null || true
            ip link set dev ${MESH_ID}.10 down 2>/dev/null || true
        fi

        if [ -d /sys/class/net/br-guest ]; then
            ip link set dev eth0.12 up 2>/dev/null || true
            brctl addif br-guest eth0.12 2>/dev/null || true
            brctl delif br-guest ${MESH_ID}.12 2>/dev/null || true
            ip link set dev ${MESH_ID}.12 down 2>/dev/null || true
        fi

        ip neigh flush dev br-lan 2>/dev/null || true
        killall -SIGUSR1 udhcpc 2>/dev/null || true
        logger -t mesh-failover "Wired Ethernet UP: Activated wired switch, detached mesh backhaul"
        STATE="wired"
    elif [ "\$WIRED" = "down" ] && [ "\$STATE" != "mesh" ]; then
        if ip link show ${MESH_ID} >/dev/null 2>&1; then
            # Ensure VLAN 1 sub-interface exists
            [ ! -d /sys/class/net/${MESH_ID}.1 ] && ip link add link ${MESH_ID} name ${MESH_ID}.1 type vlan id 1 2>/dev/null || true

            # Detach dead wired ports so bridge doesn't blackhole traffic to stale FDB ports
            brctl delif br-lan eth0.1 2>/dev/null || true
            ip link set dev eth0.1 down 2>/dev/null || true

            # Enable and attach mesh VLAN 1 interface
            ip link set dev ${MESH_ID}.1 up 2>/dev/null || true
            brctl addif br-lan ${MESH_ID}.1 2>/dev/null || true

            if [ -d /sys/class/net/br-iot ]; then
                [ ! -d /sys/class/net/${MESH_ID}.10 ] && ip link add link ${MESH_ID} name ${MESH_ID}.10 type vlan id 10 2>/dev/null || true
                brctl delif br-iot eth0.10 2>/dev/null || true
                ip link set dev eth0.10 down 2>/dev/null || true
                ip link set dev ${MESH_ID}.10 up 2>/dev/null || true
                brctl addif br-iot ${MESH_ID}.10 2>/dev/null || true
            fi

            if [ -d /sys/class/net/br-guest ]; then
                [ ! -d /sys/class/net/${MESH_ID}.12 ] && ip link add link ${MESH_ID} name ${MESH_ID}.12 type vlan id 12 2>/dev/null || true
                brctl delif br-guest eth0.12 2>/dev/null || true
                ip link set dev eth0.12 down 2>/dev/null || true
                ip link set dev ${MESH_ID}.12 up 2>/dev/null || true
                brctl addif br-guest ${MESH_ID}.12 2>/dev/null || true
            fi

            ip neigh flush dev br-lan 2>/dev/null || true
            killall -SIGUSR1 udhcpc 2>/dev/null || true
            logger -t mesh-failover "Wired Ethernet DOWN: Activated wireless mesh backhaul failover"
            STATE="mesh"
        fi
    fi

    # If AP does not yet have an IP address (e.g. booted with no cable plugged), wake udhcpc
    if [ "\$STATE" != "init" ] && ! ip -o -4 addr show dev br-lan 2>/dev/null | grep -q 'inet '; then
        killall -SIGUSR1 udhcpc 2>/dev/null || true
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
/etc/init.d/mesh-failover restart 2>/dev/null || true
/etc/init.d/firewall restart 2>/dev/null || true
wifi reload >/dev/null 2>&1 || true
(/etc/init.d/network restart >/dev/null 2>&1) &
echo "Done! Network & 802.11s Mesh configured successfully."
