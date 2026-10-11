#!/bin/bash
# Apply the flash-wear, network, security and cleanup tuning documented in
# DEBIAN-SERVER.md to the live Wyse 5070. Idempotent; backs up every file it edits.
# Usage: ssh -i ~/.ssh/id_hungnguyen root@10.0.0.100 'bash -s' < scripts/tune-wyse5070.sh
# Brief blips: Home Assistant restarts, VLAN 10 and Wi-Fi reconnect (wired enp1s0 is untouched).
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

STAMP="$(date +%Y%m%d)"
WIRED_IF="enp1s0"
VLAN_IF="enp1s0.10"
VLAN_CON="vlan10"
VLAN_V6="fd39:10:0:1::100/64"
IOT_V4="10.0.1.0/24"
IOT_V6="fd39:10:0:1::/64"
PASSWORD_OK_FROM="10.0.0.0/24,10.0.100.0/24,fd39:10::/64,fd39:10:100::/64"
HA_CONFIG="/opt/docker/homeassistant/config/configuration.yaml"

backup() {
    [[ -e "$1" && ! -e "$1.bak-${STAMP}" ]] && cp -a "$1" "$1.bak-${STAMP}"
    return 0
}

if [[ $EUID -ne 0 || ! -d "/sys/class/net/${WIRED_IF}" || ! -d "/sys/class/net/${VLAN_IF}" ]]; then
    log_err "Run as root on the Wyse 5070 (needs ${WIRED_IF} and ${VLAN_IF})."
    exit 1
fi

log_head "1/8 Journal size cap"
mkdir -p /etc/systemd/journald.conf.d
printf '[Journal]\nSystemMaxUse=256M\nSystemMaxFileSize=32M\n' > /etc/systemd/journald.conf.d/10-size.conf
systemctl restart systemd-journald
journalctl --vacuum-size=256M >/dev/null 2>&1 || true
log_succ "$(journalctl --disk-usage)"

log_head "2/8 Docker daemon (drop dead mirrors, live-restore)"
backup /etc/docker/daemon.json
python3 - <<'PY'
import json
p = "/etc/docker/daemon.json"
d = json.load(open(p))
d.pop("registry-mirrors", None)
d["live-restore"] = True
with open(p, "w") as f:
    json.dump(d, f, indent=2)
    f.write("\n")
PY
# reload turns on live-restore but keeps removed mirrors; the restart after it no longer stops containers
systemctl reload docker
sleep 2
systemctl restart docker
sleep 3
log_succ "Docker: live-restore=$(docker info --format '{{.LiveRestoreEnabled}}') mirrors=$(docker info --format '{{.RegistryConfig.Mirrors}}')"
for net in $(docker network ls --filter type=custom --format '{{.Name}}'); do
    if [[ "$(docker network inspect -f '{{len .Containers}}' "${net}")" == "0" ]]; then
        docker network rm "${net}" >/dev/null && log_info "Removed unused Docker network ${net}"
    fi
done

log_head "3/8 Home Assistant recorder"
if grep -q '^recorder:' "${HA_CONFIG}"; then
    log_info "recorder: block already present, leaving it alone"
else
    backup "${HA_CONFIG}"
    cat >> "${HA_CONFIG}" <<'EOF'

# Batch DB writes (default 5s) to cut flash wear; drop raw MQTT hex debug sensors from history
recorder:
  commit_interval: 30
  exclude:
    entity_globs:
      - sensor.*_last_raw_mqtt_hex
EOF
    if docker exec homeassistant python3 -m homeassistant --script check_config --config /config >/tmp/ha-check.log 2>&1; then
        docker restart homeassistant >/dev/null
        log_succ "Recorder tuned, Home Assistant restarted"
    else
        cp -a "${HA_CONFIG}.bak-${STAMP}" "${HA_CONFIG}"
        log_err "check_config failed (see /tmp/ha-check.log); configuration.yaml restored"
    fi
fi

log_head "4/8 IPv6 routing: static VLAN 10 address, wired default route preferred"
NETPLAN_VLAN=/etc/netplan/90-vlan10.yaml
VLAN_MAC="$(awk '/macaddress:/ {gsub(/"/, "", $2); print $2}' "${NETPLAN_VLAN}")"
if [[ -z "${VLAN_MAC}" ]]; then
    log_err "No macaddress in ${NETPLAN_VLAN}; the router's DHCP reservation depends on it. Aborting."
    exit 1
fi
backup "${NETPLAN_VLAN}"
# Manual IPv6 stops NM's ipv6.token warnings and the RA default route that pulled IPv6 egress onto VLAN 10
cat > "${NETPLAN_VLAN}" <<EOF
network:
  version: 2
  vlans:
    ${VLAN_IF}:
      renderer: NetworkManager
      id: 10
      link: "${WIRED_IF}"
      macaddress: "${VLAN_MAC}"
      dhcp4: true
      dhcp4-overrides:
        route-metric: 400
      dhcp6: false
      addresses:
        - "${VLAN_V6}"
      routes:
        - to: 10.0.1.0/24
          scope: link
          table: 101
        - to: default
          via: 10.0.1.254
          table: 101
      routing-policy:
        - from: 10.0.1.100
          to: 172.16.0.0/12
          table: 254
          priority: 98
        - from: 10.0.1.100
          table: 101
          priority: 101
      networkmanager:
        name: "${VLAN_CON}"
        passthrough:
          ethernet._: ""
          vlan.flags: "1"
          ipv6.method: "manual"
          proxy._: ""
EOF
chmod 600 "${NETPLAN_VLAN}"
if [[ -e /etc/netplan/10-${WIRED_IF}.yaml ]]; then
    mv "/etc/netplan/10-${WIRED_IF}.yaml" "/root/10-${WIRED_IF}.yaml.removed-${STAMP}"
    log_info "Removed dead netplan profile for ${WIRED_IF} (ifupdown owns it); copy kept in /root"
fi
netplan generate
nmcli connection reload
nmcli connection up "${VLAN_CON}" >/dev/null
echo "net.ipv6.conf.${WIRED_IF}.ra_defrtr_metric = 100" > /etc/sysctl.d/60-ipv6-ra-metric.conf
sysctl -q -p /etc/sysctl.d/60-ipv6-ra-metric.conf
log_info "Wired IPv6 default switches to metric 100 on the next router advertisement (≤10 min)"
ip -br addr show "${VLAN_IF}"
ip -6 route show default

log_head "5/8 Wi-Fi: 5 GHz only, unused NetworkManager profiles"
WIFI_CON="$(nmcli -t -f NAME,TYPE connection show | awk -F: '$2=="802-11-wireless" {print $1}' | head -n 1 || true)"
if [[ -n "${WIFI_CON}" ]]; then
    nmcli connection modify "${WIFI_CON}" 802-11-wireless.band a 802-11-wireless.powersave 2
    nmcli connection up "${WIFI_CON}" >/dev/null && log_succ "Wi-Fi pinned to 5 GHz, power save off (stops 2.4/5 GHz ping-pong on the same AP)"
fi
for con in cellular "Wired connection 1"; do
    if nmcli -t -f NAME connection show | grep -x "${con}" >/dev/null; then
        nmcli connection delete "${con}" >/dev/null && log_info "Deleted unused NM profile: ${con}"
    fi
done

log_head "6/8 SSH: passwords only from LAN and VPN"
SSHD_DROPIN=/etc/ssh/sshd_config.d/10-password-lan-vpn-only.conf
cat > "${SSHD_DROPIN}" <<EOF
PasswordAuthentication no
Match Address ${PASSWORD_OK_FROM}
    PasswordAuthentication yes
EOF
lan_pw="$(sshd -T -C user=root,host=x,addr=10.0.0.5 2>/dev/null | awk '/^passwordauthentication/ {print $2}' || true)"
iot_pw="$(sshd -T -C user=root,host=x,addr=10.0.1.50 2>/dev/null | awk '/^passwordauthentication/ {print $2}' || true)"
if sshd -t && [[ "${lan_pw}" == "yes" && "${iot_pw}" == "no" ]]; then
    systemctl reload ssh
    log_succ "SSH password auth: LAN/VPN=yes, IoT/other=no (keys work everywhere)"
else
    rm -f "${SSHD_DROPIN}"
    log_err "sshd validation failed (lan=${lan_pw:-?} iot=${iot_pw:-?}); drop-in removed, SSH unchanged"
fi

log_head "7/8 Host firewall: block admin ports from the IoT VLAN"
cat > /etc/nftables-hostguard.nft <<EOF
#!/usr/sbin/nft -f
table inet hostguard
delete table inet hostguard

table inet hostguard {
	set iot4 {
		type ipv4_addr
		flags interval
		elements = { ${IOT_V4} }
	}
	set iot6 {
		type ipv6_addr
		flags interval
		elements = { ${IOT_V6} }
	}
	chain input {
		type filter hook input priority filter - 10; policy accept;
		ip saddr @iot4 tcp dport { 22, 139, 445, 3000, 6800, 8088 } counter reject with tcp reset
		ip6 saddr @iot6 tcp dport { 22, 139, 445, 3000, 6800, 8088 } counter reject with tcp reset
		ip saddr @iot4 udp dport { 137, 138 } counter drop
	}
}
EOF
# Own unit instead of nftables.service: its ExecStop flushes the whole ruleset, Docker's tables included
cat > /etc/systemd/system/nft-hostguard.service <<'EOF'
[Unit]
Description=Block admin ports (SSH, Samba, AdGuard UI, Aria2 RPC, code-server) from the IoT VLAN
Wants=network-pre.target
Before=network-pre.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /etc/nftables-hostguard.nft
ExecStop=/usr/sbin/nft delete table inet hostguard

[Install]
WantedBy=multi-user.target
EOF
nft -c -f /etc/nftables-hostguard.nft
systemctl daemon-reload
systemctl enable nft-hostguard.service >/dev/null 2>&1
systemctl restart nft-hostguard.service
log_succ "hostguard loaded; tables now: $(nft list tables | awk '{print $2"/"$3}' | xargs)"

log_head "8/8 Packages and leftovers"
if systemctl list-unit-files wol.service >/dev/null 2>&1 && [[ -e /etc/systemd/system/wol.service ]]; then
    systemctl disable wol.service >/dev/null 2>&1 || true
    rm -f /etc/systemd/system/wol.service
    systemctl daemon-reload
    log_info "Removed wol.service (ifupdown post-up already arms WoL)"
fi
log_info "Wake-on-LAN: $(ethtool "${WIRED_IF}" | awk '$1=="Wake-on:" {print $2}')"
export DEBIAN_FRONTEND=noninteractive
if dpkg -s i965-va-driver >/dev/null 2>&1; then
    apt-get purge -y -q i965-va-driver >/dev/null && log_info "Purged i965-va-driver (Jellyfin uses iHD)"
fi
if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
    apt-get install -y -q unattended-upgrades >/dev/null
fi
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades
log_succ "unattended-upgrades enabled (Debian security origins, no auto-reboot)"

log_head "Done"
log_info "Backups: *.bak-${STAMP} next to each edited file"
