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
WIFI_IF="wlp0s12f0"
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

log_head "1/11 Journal size cap"
mkdir -p /etc/systemd/journald.conf.d
printf '[Journal]\nSystemMaxUse=256M\nSystemMaxFileSize=32M\n' > /etc/systemd/journald.conf.d/10-size.conf
systemctl restart systemd-journald
journalctl --vacuum-size=256M >/dev/null 2>&1 || true
log_succ "$(journalctl --disk-usage)"

log_head "2/11 Docker daemon (drop dead mirrors, live-restore)"
if python3 -c 'import json,sys; d=json.load(open("/etc/docker/daemon.json")); sys.exit(0 if "registry-mirrors" in d or d.get("live-restore") is not True else 1)'; then
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
fi
log_succ "Docker: live-restore=$(docker info --format '{{.LiveRestoreEnabled}}') mirrors=$(docker info --format '{{.RegistryConfig.Mirrors}}')"
for net in $(docker network ls --filter type=custom --format '{{.Name}}'); do
    if [[ "$(docker network inspect -f '{{len .Containers}}' "${net}")" == "0" ]]; then
        docker network rm "${net}" >/dev/null && log_info "Removed unused Docker network ${net}"
    fi
done

log_head "3/11 Home Assistant recorder"
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

log_head "4/11 IPv6 routing: static VLAN 10 address, wired default route preferred"
NETPLAN_VLAN=/etc/netplan/90-vlan10.yaml
VLAN_MAC="$(awk '/macaddress:/ {gsub(/"/, "", $2); print $2}' "${NETPLAN_VLAN}")"
if [[ -z "${VLAN_MAC}" ]]; then
    log_err "No macaddress in ${NETPLAN_VLAN}; the router's DHCP reservation depends on it. Aborting."
    exit 1
fi
NETPLAN_NEW="$(mktemp)"
# Manual IPv6 stops NM's ipv6.token warnings and the RA default route that pulled IPv6 egress onto VLAN 10
cat > "${NETPLAN_NEW}" <<EOF
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
netplan_changed=false
if ! cmp -s "${NETPLAN_NEW}" "${NETPLAN_VLAN}"; then
    backup "${NETPLAN_VLAN}"
    install -m 600 "${NETPLAN_NEW}" "${NETPLAN_VLAN}"
    netplan_changed=true
fi
rm -f "${NETPLAN_NEW}"
if [[ -e /etc/netplan/10-${WIRED_IF}.yaml ]]; then
    mv "/etc/netplan/10-${WIRED_IF}.yaml" "/root/10-${WIRED_IF}.yaml.removed-${STAMP}"
    log_info "Removed dead netplan profile for ${WIRED_IF} (ifupdown owns it); copy kept in /root"
    netplan_changed=true
fi
if [[ "${netplan_changed}" == "true" ]]; then
    netplan generate
    nmcli connection reload
    nmcli connection up "${VLAN_CON}" >/dev/null
else
    log_info "VLAN 10 profile already current"
fi
echo "net.ipv6.conf.${WIRED_IF}.ra_defrtr_metric = 100" > /etc/sysctl.d/60-ipv6-ra-metric.conf
sysctl -q -p /etc/sysctl.d/60-ipv6-ra-metric.conf
log_info "Wired IPv6 default switches to metric 100 on the next router advertisement (≤10 min)"
ip -br addr show "${VLAN_IF}"
ip -6 route show default

log_head "5/11 Wi-Fi: 5 GHz only, unused NetworkManager profiles"
WIFI_CON="$(nmcli -t -f NAME,TYPE connection show | awk -F: '$2=="802-11-wireless" {print $1}' | head -n 1 || true)"
if [[ -n "${WIFI_CON}" && "$(nmcli -g 802-11-wireless.band,802-11-wireless.powersave connection show "${WIFI_CON}" | xargs)" != "a disable" ]]; then
    nmcli connection modify "${WIFI_CON}" 802-11-wireless.band a 802-11-wireless.powersave 2
    nmcli connection up "${WIFI_CON}" >/dev/null && log_succ "Wi-Fi pinned to 5 GHz, power save off (stops 2.4/5 GHz ping-pong on the same AP)"
fi
for con in cellular "Wired connection 1"; do
    if nmcli -t -f NAME connection show | grep -x "${con}" >/dev/null; then
        nmcli connection delete "${con}" >/dev/null && log_info "Deleted unused NM profile: ${con}"
    fi
done

log_head "6/11 SSH: passwords only from LAN and VPN"
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

log_head "7/11 Host firewall and forwarding: admin ports closed to IoT, no routing between LAN/IoT/Wi-Fi"
# With forwarding on and FORWARD policy ACCEPT, an IoT device could route through this box into the LAN
sed -i -E 's/^(net\.ipv6\.conf\.(all|default)\.forwarding) = 1/\1 = 0/' /etc/sysctl.d/99-server.conf
sysctl -q -w net.ipv6.conf.all.forwarding=0 net.ipv6.conf.default.forwarding=0
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
	chain forward {
		type filter hook forward priority filter - 10; policy accept;
		iifname { "${WIRED_IF}", "${VLAN_IF}", "${WIFI_IF}" } oifname { "${WIRED_IF}", "${VLAN_IF}", "${WIFI_IF}" } counter drop
	}
}
EOF
# Own unit instead of nftables.service: its ExecStop flushes the whole ruleset, Docker's tables included
cat > /etc/systemd/system/nft-hostguard.service <<'EOF'
[Unit]
Description=Close admin ports to the IoT VLAN and stop routing between LAN, IoT and Wi-Fi
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

log_head "8/11 DNS: resolve through the local AdGuard Home"
printf '[main]\ndns=none\n' > /etc/NetworkManager/conf.d/90-dns-none.conf
systemctl reload NetworkManager
if [[ ! -L /etc/resolv.conf ]] && ! grep -q '^nameserver 127.0.0.1' /etc/resolv.conf; then
    backup /etc/resolv.conf
    printf 'nameserver 127.0.0.1\nnameserver ::1\nnameserver 10.0.0.254\noptions timeout:2\n' > /etc/resolv.conf
fi
log_succ "resolv.conf first server $(awk '/^nameserver/ {print $2; exit}' /etc/resolv.conf); test: $(getent hosts deb.debian.org | awk '{print $1; exit}')"

log_head "9/11 Services inherited from the Chainedbox"
UNBOUND_DIR=/etc/unbound/unbound.conf.d
if [[ -e "${UNBOUND_DIR}/custom.conf" ]]; then
    mv "${UNBOUND_DIR}/custom.conf" "/root/unbound-custom.conf.removed-${STAMP}"
fi
backup "${UNBOUND_DIR}/custom-port.conf"
cat > "${UNBOUND_DIR}/custom-port.conf" <<'EOF'
server:
    port: 5335
    interface: 127.0.0.1
    interface: ::1
    access-control: 127.0.0.0/8 allow
    access-control: ::1 allow

    msg-cache-size: 50m
    rrset-cache-size: 100m
    cache-max-ttl: 86400
    serve-expired: yes
    serve-expired-ttl: 86400
    prefetch: yes
    num-threads: 2
    so-rcvbuf: 1m

    hide-identity: yes
    hide-version: yes
    harden-glue: yes
    harden-dnssec-stripped: yes
    use-caps-for-id: yes

    # Refuse private addresses in public answers (DNS rebinding)
    private-address: 192.168.0.0/16
    private-address: 169.254.0.0/16
    private-address: 172.16.0.0/12
    private-address: 10.0.0.0/8
    private-address: fd00::/8
    private-address: fe80::/10
EOF
unbound-checkconf >/dev/null
systemctl restart unbound
sleep 1
log_succ "Unbound: one config file, no 1-hour TTL floor; test: $(dig +short +time=3 @127.0.0.1 -p 5335 deb.debian.org A | tail -1)"

# Package upgrades replace a symlinked /etc/samba/smb.conf with a plain file, so /etc is the real one
SMB_CONF=/etc/samba/smb.conf
SMB_NAME="$(hostname | tr '[:lower:]' '[:upper:]')"
smb_changed=false
if [[ -L "${SMB_CONF}" ]]; then
    cp --remove-destination "$(readlink -f "${SMB_CONF}")" "${SMB_CONF}"
    smb_changed=true
fi
if grep -qE '^[[:space:]]*netbios name = chainedbox[[:space:]]*$' "${SMB_CONF}"; then
    backup "${SMB_CONF}"
    sed -i -E "s/^([[:space:]]*)netbios name = chainedbox[[:space:]]*$/\1netbios name = ${SMB_NAME}\n\1netbios aliases = chainedbox/" "${SMB_CONF}"
    smb_changed=true
fi
# nmbd otherwise registers the names on the Docker bridges and Wi-Fi and answers with those addresses
if ! grep -qE '^[[:space:]]*interfaces = ' "${SMB_CONF}"; then
    sed -i -E "s/^([[:space:]]*)(netbios name = .*)$/\1\2\n\1interfaces = lo ${WIRED_IF}/" "${SMB_CONF}"
    smb_changed=true
fi
if [[ -f /appsrv/samba/smb.conf && ! -L /appsrv/samba/smb.conf ]]; then
    mv /appsrv/samba/smb.conf "/root/smb.conf.appsrv-stale-${STAMP}"
fi
ln -sfn "${SMB_CONF}" /appsrv/samba/smb.conf
if [[ "${smb_changed}" == "true" ]]; then
    testparm -s "${SMB_CONF}" >/dev/null 2>&1
    systemctl restart smbd nmbd
fi
log_succ "Samba: NetBIOS ${SMB_NAME} (alias chainedbox) on lo + ${WIRED_IF}; config ${SMB_CONF}"

ARIA2_CONF=/appsrv/aria2/aria2.conf
if grep -q '9\.rarbg\.com' "${ARIA2_CONF}"; then
    backup "${ARIA2_CONF}"
    sed -i -E 's#,?udp://9\.rarbg\.com:2810(/announce)?##' "${ARIA2_CONF}"
    systemctl restart aria2
    log_info "Aria2: removed the dead rarbg tracker"
fi

cat > /etc/logrotate.d/nginx-appsrv <<'EOF'
/appsrv/nginx/log/*.log {
	weekly
	missingok
	rotate 8
	compress
	delaycompress
	notifempty
	create 0640 www-data root
	sharedscripts
	postrotate
		invoke-rc.d nginx rotate >/dev/null 2>&1
	endscript
}
EOF
logrotate -d /etc/logrotate.d/nginx-appsrv >/dev/null 2>&1 && log_info "nginx logs on /appsrv now rotate weekly"

log_head "10/11 Packages and leftovers"
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
# task-ssh-server is what pulled openssh-server in; keep sshd when the task goes
apt-mark manual openssh-server >/dev/null
UNUSED_PKGS=""
for pkg in os-prober tasksel tasksel-data task-english task-ssh-server laptop-detect installation-report reportbug \
           libqmi-utils libmbim-utils mesa-opencl-icd systemd-timesyncd; do
    if dpkg -l "${pkg}" 2>/dev/null | grep -qE "^(ii|rc)"; then
        UNUSED_PKGS="${UNUSED_PKGS} ${pkg}"
    fi
done
if [[ -n "${UNUSED_PKGS}" ]]; then
    apt-get purge -y -q ${UNUSED_PKGS} >/dev/null
    log_info "Purged:${UNUSED_PKGS}"
fi
apt-get autoremove --purge -y -q >/dev/null
rm -rf /var/log/installer
log_succ "Kernels left: $(dpkg -l 'linux-image-[0-9]*' | awk '/^ii/ {print $2}' | xargs)"

log_head "11/11 Boot, flash wear and disk health"
# The resume UUID pointed at the sda3 swap partition removed on 2026-09-20; each boot waited ~30 s for it
if grep -q '^RESUME=UUID=' /etc/initramfs-tools/conf.d/resume 2>/dev/null; then
    backup /etc/initramfs-tools/conf.d/resume
    echo "RESUME=none" > /etc/initramfs-tools/conf.d/resume
    update-initramfs -u -k all >/dev/null 2>&1
    log_succ "initramfs rebuilt without the stale resume device"
fi

JF_ENC=/appsrv/jellyfin/config/encoding.xml
JF_TMP=/tmp/jellyfin-transcodes
if ! grep -q "<TranscodingTempPath>${JF_TMP}</TranscodingTempPath>" "${JF_ENC}"; then
    if pgrep -x ffmpeg >/dev/null; then
        log_warn "Jellyfin is transcoding right now; transcode path left on flash, re-run later"
    else
        backup "${JF_ENC}"
        systemctl stop jellyfin
        if grep -q '<TranscodingTempPath>' "${JF_ENC}"; then
            sed -i -E "s#<TranscodingTempPath>[^<]*</TranscodingTempPath>#<TranscodingTempPath>${JF_TMP}</TranscodingTempPath>#" "${JF_ENC}"
        else
            sed -i "s#</EncodingOptions>#  <TranscodingTempPath>${JF_TMP}</TranscodingTempPath>\n</EncodingOptions>#" "${JF_ENC}"
        fi
        printf 'd %s 0750 jellyfin jellyfin -\n' "${JF_TMP}" > /etc/tmpfiles.d/jellyfin-transcodes.conf
        systemd-tmpfiles --create /etc/tmpfiles.d/jellyfin-transcodes.conf
        rm -rf /appsrv/jellyfin/cache/transcodes
        systemctl start jellyfin
        log_succ "Jellyfin transcodes now go to tmpfs (${JF_TMP})"
    fi
fi

if ! grep -qE '^SaveInterval 30' /etc/vnstat.conf; then
    backup /etc/vnstat.conf
    sed -i -E 's/^;?SaveInterval .*/SaveInterval 30/' /etc/vnstat.conf
    systemctl restart vnstat
    log_info "vnstat saves every 30 min instead of 5"
fi

if ! findmnt -no OPTIONS / | grep -q noatime; then
    backup /etc/fstab
    sed -i -E 's#^(UUID=[^ ]+[[:space:]]+/[[:space:]]+ext4[[:space:]]+)errors=remount-ro#\1noatime,errors=remount-ro#' /etc/fstab
    mount -o remount,noatime /
    log_info "Root filesystem remounted noatime"
fi

if [[ ! -e /etc/modprobe.d/disable-bluetooth.conf ]]; then
    printf 'blacklist btusb\nblacklist bluetooth\n' > /etc/modprobe.d/disable-bluetooth.conf
    modprobe -r btusb btintel btrtl btbcm btmtk bluetooth 2>/dev/null || true
    log_info "Bluetooth disabled (no BlueZ on the host, nothing uses it)"
fi

if ! grep -q -- '-s (S/' /etc/smartd.conf; then
    backup /etc/smartd.conf
    sed -i -E 's#^DEVICESCAN -d removable -n standby -m root#DEVICESCAN -d removable -n standby -s (S/../.././03|L/../../7/04) -m root#' /etc/smartd.conf
    systemctl restart smartmontools
    log_info "smartd: short self-test daily 03:00, long test Sundays 04:00"
fi
log_succ "Root mount: $(findmnt -no OPTIONS /)"

log_head "Done"
log_info "Backups: *.bak-${STAMP} next to each edited file"
