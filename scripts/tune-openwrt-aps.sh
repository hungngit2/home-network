#!/bin/sh
# Hardening and cleanup for the dumb-AP fleet documented in WIFI-APS.md. Idempotent.
# Usage (one AP at a time so clients can roam to the others during the Wi-Fi restart):
#   for ip in 10.0.0.200 10.0.0.201 10.0.0.202 10.0.0.203; do
#       ssh -i ~/.ssh/id_hungnguyen root@$ip 'sh -s' < scripts/tune-openwrt-aps.sh; sleep 30
#   done
set -eu

HOST="$(cat /proc/sys/kernel/hostname)"
log() { echo "[${HOST}] $*"; }

ifaces_on() {
    uci show wireless | sed -n -E "s/^wireless\.([^.]+)\.network='($1)'$/\1/p"
}

radio_band() {
    band="$(uci -q get "wireless.$1.band" || true)"
    if [ -z "${band}" ]; then
        case "$(uci -q get "wireless.$1.hwmode" || true)" in
            11a) band=5g ;;
            *) [ "$(uci -q get "wireless.$1.channel" || echo 0)" -gt 14 ] 2>/dev/null && band=5g || band=2g ;;
        esac
    fi
    echo "${band}"
}

log "Firewall: drop traffic to the AP itself from the IoT and Guest bridges"
if ! uci -q get firewall.untrusted >/dev/null; then
    uci set firewall.untrusted=zone
    uci set firewall.untrusted.name='untrusted'
    for dev in br-lan.10 br-lan.12; do
        if [ -e "/sys/class/net/${dev}" ]; then
            uci add_list firewall.untrusted.device="${dev}"
        fi
    done
    uci set firewall.untrusted.input='DROP'
    uci set firewall.untrusted.output='ACCEPT'
    uci set firewall.untrusted.forward='DROP'
fi
while uci -q get firewall.@include[0] >/dev/null; do uci delete firewall.@include[0]; done
for rule in $(uci show firewall | sed -n -E "s/^firewall\.(@rule\[[0-9]+\])\.dest_port='5353'$/\1/p" | sort -r); do
    uci delete "firewall.${rule}"
done
uci commit firewall
service firewall reload >/dev/null 2>&1

log "Wireless: guest isolation, IoT on 2.4 GHz only, FT over the air"
for s in $(ifaces_on guest); do
    uci set "wireless.${s}.isolate=1"
done
for s in $(ifaces_on iot); do
    if [ "$(radio_band "$(uci get "wireless.${s}.device")")" = "5g" ]; then
        uci set "wireless.${s}.disabled=1"
    fi
done
for s in $(ifaces_on 'lan|iot|guest'); do
    uci set "wireless.${s}.ft_over_ds=0"
done
for r in $(uci show wireless | sed -n -E "s/^wireless\.([^.]+)=wifi-device$/\1/p"); do
    if [ "$(radio_band "${r}")" = "5g" ]; then
        case "$(uci -q get "wireless.${r}.htmode" || true)" in
            # 160 MHz on ch36 spans DFS 52-64; a radar hit would move the radio off the mesh channel
            VHT160|HE160) uci set "wireless.${r}.htmode=$(uci get "wireless.${r}.htmode" | sed 's/160/80/')" ;;
        esac
    elif [ "${HOST}" = "redmi-rm2100-f0" ]; then
        uci set "wireless.${r}.txpower=20"
    fi
done
uci commit wireless

log "Unbound: no DNS64, LAN-only, rebind protection, no root-zone download"
uci set unbound.ub_main.dns64='0'
uci set unbound.ub_main.validator='0'
uci set unbound.ub_main.localservice='1'
uci set unbound.ub_main.rebind_protection='1'
if uci -q get unbound.auth_icann >/dev/null; then
    uci set unbound.auth_icann.enabled='0'
fi
uci commit unbound
service unbound restart >/dev/null 2>&1

log "System: quiet cron logging, staggered weekly reboot, idle DHCP daemons off"
uci set system.@system[0].cronloglevel='9'
uci commit system
case "${HOST}" in
    jcg-q20-f1) reboot_min=0 ;;
    jcg-q20-f2) reboot_min=20 ;;
    jcg-q20-f3) reboot_min=40 ;;
    *) reboot_min="" ;;
esac
if [ -f /etc/crontabs/root ] && grep -q '/sbin/reboot' /etc/crontabs/root && [ -n "${reboot_min}" ]; then
    sed -i -E "s#^[0-9]+ 4 \* \* 1 /sbin/reboot#${reboot_min} 4 * * 1 /sbin/reboot#" /etc/crontabs/root
fi
service cron restart >/dev/null 2>&1
for svc in dnsmasq odhcpd; do
    if [ -x "/etc/init.d/${svc}" ]; then
        "/etc/init.d/${svc}" stop >/dev/null 2>&1 || true
        "/etc/init.d/${svc}" disable >/dev/null 2>&1 || true
    fi
done
if command -v apk >/dev/null 2>&1 && apk info -e tcpdump >/dev/null 2>&1; then
    apk del tcpdump libpcap1 >/dev/null 2>&1 || true
fi

ap_vifs_down() {
    for vif in /sys/class/net/phy*-ap*; do
        [ "$(cat "${vif}/operstate")" = "up" ] || echo "${vif##*/}"
    done
}

# `wifi reload` leaves the 5 GHz AP vifs down next to the mesh on these MT76 radios; a full down/up does not
log "Restarting Wi-Fi"
wifi down
sleep 3
wifi up
sleep 30
if [ -n "$(ap_vifs_down)" ]; then
    log "Retrying, still down: $(ap_vifs_down | xargs)"
    wifi down
    sleep 3
    wifi up
    sleep 30
fi
service usteer restart
sleep 8
log "AP vifs down: $(ap_vifs_down | xargs)"
log "usteer tracks BSSes on: $(ubus call usteer local_info 2>/dev/null | sed -n -E 's/.*"freq": ([0-9]+).*/\1/p' | sort | uniq -c | xargs)"
