# Home — Wi-Fi Access Points (OpenWrt fleet)

## Fleet overview

| Hostname | Model | Mikrotik DHCP lease | Role |
|---|---|---|---|
| `redmi-rm2100-f0` | Redmi AC2100 (`RM2100`) | `10.0.0.200` (comment `AP-0`) | Mesh AP |
| `jcg-q20-f1` | JCG Q20 | `10.0.0.201` (comment `AP-1`) | Mesh AP |
| `jcg-q20-f2` | JCG Q20 | `10.0.0.202` (comment `AP-2`) | Mesh AP |
| `jcg-q20-f3` | JCG Q20 | `10.0.0.203` (comment `AP-3`) | Mesh AP |

All 4 are **dumb APs wired into a self-configuring 802.11s mesh** (`home-mesh`) rather than independent routers — none of them run DHCP (`dnsmasq` is explicitly disabled by `uci-defaults`, `odhcpd maindhcp=0`), so [the MikroTik](MIKROTIK-HEXS.md) remains the single DHCP/DNS authority for the whole LAN. Their job is purely: bridge wired ports + broadcast the 4 SSIDs + backhaul to each other and to the router over mesh + wire.

## How they plug into the router's VLAN scheme

Each AP's `br-lan` is VLAN-aware and carries the same 3 VLANs the MikroTik defines (see [MIKROTIK-HEXS.md](MIKROTIK-HEXS.md#network-topology)) — VLAN 1 = LAN, VLAN 10 = IoT, VLAN 12 = Guest — tagged over both the wired uplink (`wan` port, despite the name — these run in AP-not-router mode so "wan" is just another switch port back to the MikroTik) and the wireless mesh link (`home-mesh:t`):

```
config bridge-vlan
    vlan 1   (LAN)   ports: lan1/lan2 untagged, home-mesh tagged, wan untagged
config bridge-vlan
    vlan 10  (IoT)   ports: lan2 untagged, home-mesh tagged, wan tagged
config bridge-vlan
    vlan 12  (Guest)          home-mesh tagged, wan tagged
```

Exact port-to-VLAN wiring differs slightly per hardware (JCG puts `lan2` on the IoT VLAN; the Redmi — which has a 3rd LAN port — puts `lan1`+`lan3` on IoT and `lan2` on the main LAN), but the VLAN IDs and their meaning are identical across the fleet and match the MikroTik side exactly, so a wired device plugged into any AP's LAN port lands on the correct segment automatically.

## Wireless

4 SSIDs broadcast identically (same keys, same feature set) on every AP, on both 2.4GHz (`radio0`, HE20/Wi-Fi 6) and 5GHz (`radio1`, HE80):

| SSID | Network / VLAN | Notes |
|---|---|---|
| `home` | LAN | Main SSID — password is set in the config (redacted here; same treatment as other credentials in this repo) |
| `home IoT` | IoT | Separate PSK, `ieee80211w=0` (no management-frame protection — presumably for older/cheap IoT devices that don't support PMF) |
| `home⁺` | Guest | Separate PSK, PMF **required** (`ieee80211w=1`) |
| `home-mesh` | — (mesh backhaul, not a client SSID) | 802.11s mesh on 5GHz, SAE-encrypted with its own key, `mesh_fwding=1` |

All client SSIDs share: 802.11r fast roaming (`ieee80211r`, shared `mobility_domain`) + 802.11k/v (neighbor reports + BSS transition) so clients can roam between APs without a full re-auth, `multicast_to_unicast_all` (helps mDNS/casting reliability over Wi-Fi), and are steered by **usteer** (band-steering + load-balancing between the 4 APs, communicating with each other over the `lan` network interface — i.e. over the mesh/wired backhaul, not a separate channel). Steering runs on `usteer` only — no other steering daemon is active on any AP.

**Channels are hand-staggered** across the 3 JCG units to minimize co-channel interference (5GHz is fixed at 36 on all of them, which only makes sense if they're spaced out enough physically, or DFS/ACS was intentionally disabled in favor of a manually-planned channel plan):

| AP | 2.4GHz channel | 5GHz channel |
|---|---|---|
| `jcg-q20-f1` | 6 | 36 |
| `jcg-q20-f2` | 11 | 36 |
| `jcg-q20-f3` | 1 | 36 |
| `redmi-rm2100-f0` | 1 | 36 |

## DNS / DHCP

- **`dnsmasq` disabled** on all 4 (via `/etc/uci-defaults/10_disable_services`) — no local DHCP or caching resolver competing with the router.
- **Unbound** (validating recursive resolver, port 53, `localservice=1`, all 4 configs byte-identical/all-defaults) — [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#dns--mdns) shows AdGuard Home's `upstream_dns` list includes all 4 APs (`10.0.0.200`–`.203`) alongside Chainedbox's own Unbound and public fallbacks — so each AP's local resolver is a redundant upstream DNS path for the whole LAN.
- **`odhcpd`** (IPv6 DHCP/RA) present but `maindhcp=0` — passive/relay role only, consistent with MikroTik being DHCPv4/v6 authority.
- `redmi-rm2100-f0` has an `unbound-opkg` config file sitting alongside the live `unbound` config, with different settings — a leftover from this fleet's `opkg`→`apk` package-manager migration, not a second active instance. Safe to remove once confirmed unused.

## IPTV support services

Neither `udpxy` nor `rtp2httpd` is installed on any of the 4 APs — IPTV multicast→HTTP relay lives solely on [Chainedbox](ARMBIAN-SERVER.md#iptv-stack).

## Management & security posture

- **SSH (`dropbear`)**: enabled, port 22, **both password auth and root password auth on**. Root does have a password hash set (not blank — the passwordless-root warning banner script wouldn't fire), but this is still root-over-password on every AP, reachable from anywhere those SSH ports are exposed to (LAN-only per the Mikrotik firewall's WAN-drop rules, so not internet-facing).
- **LuCI / `uhttpd`**: HTTP+HTTPS web UI on all interfaces (`0.0.0.0:80`/`:443`), self-signed cert generated locally (`commonname=OpenWrt` default — not customized).
- **RADIUS** (802.1X/enterprise Wi-Fi auth): configured but `disabled=1` on every unit, with template/placeholder credentials (`clients` file just has the default `0.0.0.0/0 radius` shared secret, `users` file is an empty PEAP wildcard template) — not a live secret, just an unused feature left in its default state.
- **Nightly reboot**, staggered by a couple minutes across the fleet (`jcg-q20-f1` at 04:02, the other three at 04:00) — same "reboot on a schedule" pattern seen on [Chainedbox](ARMBIAN-SERVER.md#scheduled-tasks), suggesting a house-wide habit of nightly reboots for stability rather than anything specific to one device's constraints.
- **`attendedsysupgrade`** client is configured (points at `https://sysupgrade.openwrt.org`) — the mechanism for pulling a browser/LuCI-initiated firmware rebuild-and-flash, not an auto-updater.

## Notes

- **Credentials found, not reproduced**: the 4 Wi-Fi PSKs (`home`, `home IoT`, `home⁺`) and the mesh key are in plaintext in these configs — flagged here rather than copied into this doc, same treatment as the router-admin passwords found in [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#web-root--mntappsrvwww)'s `wifi/config.json`.
