#!/bin/bash
# Load a VPN provider's WireGuard client .conf into wg-out1 or wg-out2 on the hEX S, then verify it.
# Usage:   scripts/update-wg-out.sh <wg-out1|wg-out2> <provider.conf> [--dry-run]
# Example: scripts/update-wg-out.sh wg-out1 ~/Downloads/sg_wg.conf
# Env:     ROUTER (default admin@10.0.0.254), SSH_KEY (default ~/.ssh/id_hungnguyen)
# Keys go to the router over SSH only; the .conf is never copied anywhere. Do not commit .conf files.
set -euo pipefail

ROUTER="${ROUTER:-admin@10.0.0.254}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_hungnguyen}"

die() { echo "ERROR: $*" >&2; exit 1; }

[[ $# -ge 2 ]] || die "usage: $0 <wg-out1|wg-out2> <provider.conf> [--dry-run]"
IFACE="$1"; CONF="$2"; DRY="${3:-}"
[[ "$IFACE" =~ ^wg-out([12])$ ]] || die "interface must be wg-out1 or wg-out2"
N="${BASH_REMATCH[1]}"
[[ -r "$CONF" ]] || die "cannot read $CONF"

conf_get() {
    awk -F'=' -v k="$1" '
        { sub(/\r$/, "") }
        { key = $1; gsub(/[ \t]/, "", key) }
        tolower(key) == tolower(k) { v = substr($0, index($0, "=") + 1); gsub(/^[ \t]+|[ \t]+$/, "", v); print v; exit }
    ' "$CONF"
}

PRIV="$(conf_get PrivateKey)"
ADDR="$(conf_get Address | tr ',' '\n' | grep -m1 -E '^[[:space:]]*[0-9]+\.' | tr -d ' ' || true)"
PUB="$(conf_get PublicKey)"
PSK="$(conf_get PresharedKey)"
ENDPOINT="$(conf_get Endpoint)"
KEEPALIVE="$(conf_get PersistentKeepalive)"; KEEPALIVE="${KEEPALIVE:-25}"

KEY_RE='^[A-Za-z0-9+/]{43}=$'
[[ "$PRIV" =~ $KEY_RE ]] || die "PrivateKey missing or malformed"
[[ "$PUB" =~ $KEY_RE ]] || die "PublicKey missing or malformed"
[[ -z "$PSK" || "$PSK" =~ $KEY_RE ]] || die "PresharedKey malformed"
[[ -n "$ADDR" ]] || die "no IPv4 Address in [Interface]"
[[ "$ADDR" == */* ]] || ADDR="$ADDR/32"
[[ "$ADDR" =~ ^[0-9.]+/[0-9]+$ ]] || die "Address not IPv4: $ADDR"
[[ "$KEEPALIVE" =~ ^[0-9]+$ ]] || die "PersistentKeepalive not a number"

if [[ "$ENDPOINT" =~ ^\[(.+)\]:([0-9]+)$ ]] || [[ "$ENDPOINT" =~ ^([^:]+):([0-9]+)$ ]]; then
    EP_HOST="${BASH_REMATCH[1]}"; EP_PORT="${BASH_REMATCH[2]}"
else
    die "Endpoint must be host:port, got '$ENDPOINT'"
fi
[[ "$EP_HOST" =~ ^[A-Za-z0-9.:-]+$ ]] || die "Endpoint host has unexpected characters"

PSK_ARG="preshared-key=\"${PSK}\""
[[ -n "$PSK" ]] || PSK_ARG='preshared-key=""'

RSC="/interface wireguard set [find name=${IFACE}] private-key=\"${PRIV}\"
:if ([:len [/ip address find interface=${IFACE}]] = 0) do={ /ip address add interface=${IFACE} address=${ADDR} comment=\"VPN Out ${N}\" } else={ /ip address set [find interface=${IFACE}] address=${ADDR} }
:if ([:len [/interface wireguard peers find interface=${IFACE}]] = 0) do={ /interface wireguard peers add interface=${IFACE} name=peer-vpn-out${N} public-key=\"${PUB}\" }
/interface wireguard peers set [find interface=${IFACE}] public-key=\"${PUB}\" ${PSK_ARG} endpoint-address=${EP_HOST} endpoint-port=${EP_PORT} allowed-address=0.0.0.0/0 persistent-keepalive=${KEEPALIVE}s"

echo "Tunnel   : ${IFACE}  <- ${CONF}"
echo "Address  : ${ADDR}"
echo "Endpoint : ${EP_HOST}:${EP_PORT}  keepalive ${KEEPALIVE}s  psk $([[ -n "$PSK" ]] && echo yes || echo no)"

if [[ "$DRY" == "--dry-run" ]]; then
    echo "--- RouterOS commands (keys masked) ---"
    echo "$RSC" | sed -E 's/(private-key|public-key|preshared-key)="[^"]+"/\1="<…>"/g'
    exit 0
fi

ros() { ssh -i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=8 "$ROUTER" "$1"; }

ros ":put [/interface wireguard get [find name=${IFACE}] name]" >/dev/null || die "${IFACE} not found on ${ROUTER}"
ros "$RSC"
echo "Applied. Waiting for handshake..."

hs=""
for _ in $(seq 1 15); do
    hs="$(ros ":put [/interface wireguard peers get [find interface=${IFACE}] last-handshake]" | tr -d '\r')"
    [[ -n "$hs" ]] && break
    sleep 2
done
[[ -n "$hs" ]] || die "no handshake after 30 s: check keys, endpoint and that the provider config is still valid"
echo "Handshake: ${hs} ago"

CHECK_IP="$(ros ":put [/ip route get [find comment=\"Recursive Route VPN Out ${N}\"] dst-address]" | tr -d '\r' | cut -d/ -f1)"
if [[ -n "$CHECK_IP" ]]; then
    ping_out="$(ros "/ping ${CHECK_IP} count=3 interface=${IFACE}" | grep -E "sent=" | tail -1)"
    echo "Ping ${CHECK_IP} via ${IFACE}: ${ping_out}"
    # A re-run with unchanged keys still shows the old handshake, so traffic is the real proof
    [[ "$ping_out" =~ received=[1-9] ]] || die "no reply from ${CHECK_IP} through ${IFACE}"
fi
ros "/ip route print terse where routing-table=to-vpn-out" | grep -oE "^ *[0-9]+ +[A-Za-z]* +comment=[^=]+ |immediate-gw=[^ ]+" | paste - - || true
