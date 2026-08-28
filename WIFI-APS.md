# Home — Wi-Fi Access Points (OpenWrt fleet)

## Fleet overview

| Hostname | Model | Mikrotik DHCP lease | Role |
|---|---|---|---|
| `redmi-rm2100-f0` | Redmi AC2100 (`RM2100`) | `10.0.0.200` (comment `AP-0`) | Mesh AP |
| `jcg-q20-f1` | JCG Q20 | `10.0.0.201` (comment `AP-1`) | Mesh AP |
| `jcg-q20-f2` | JCG Q20 | `10.0.0.202` (comment `AP-2`) | Mesh AP |
| `jcg-q20-f3` | JCG Q20 | `10.0.0.203` (comment `AP-3`) | Mesh AP |

All 4 are **dumb APs connected via Gigabit wired Ethernet as the primary backhaul, with a self-configuring 802.11s wireless mesh (`home-mesh`) active as an automatic backup/failover** rather than independent routers — none of them run DHCP (`dnsmasq` is explicitly disabled by `uci-defaults`, `odhcpd maindhcp=0`), so [the MikroTik](MIKROTIK-HEXS.md) remains the single DHCP/DNS authority for the whole LAN. Their job is purely: bridge wired ports + broadcast the 4 SSIDs + provide redundant backhaul to the router (primary wired Ethernet trunk, standby 802.11s wireless mesh).

## How they plug into the router's VLAN scheme

Each AP's `br-lan` is VLAN-aware and carries the same 3 VLANs the MikroTik defines (see [MIKROTIK-HEXS.md](MIKROTIK-HEXS.md#network-topology)) — VLAN 1 = LAN, VLAN 10 = IoT, VLAN 12 = Guest — bridged across the primary wired uplink (`wan` port, operating in switch/trunk mode to the switch/router) and tagged over the standby wireless mesh link (`home-mesh:t`):

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

4 SSIDs broadcast identically (same keys, same feature set) on every AP, on both 2.4GHz (`radio0`, HT20) and 5GHz (`radio1`, VHT80/VHT160):

| SSID | Network / VLAN | Notes |
|---|---|---|
| `home` | LAN | Main SSID — password is set in the config (redacted here; same treatment as other credentials in this repo) |
| `home IoT` | IoT | Separate PSK, `ieee80211w=0` (no management-frame protection — for broad compatibility with older IoT smart devices) |
| `home⁺` | Guest | Separate PSK, PMF **required** (`ieee80211w=1`) |
| `home-mesh` | — (backup mesh backhaul, not a client SSID) | 802.11s mesh on 5GHz, SAE-encrypted with its own key, `mesh_fwding=1` (serves as automatic wireless failover if ethernet drops) |

All client SSIDs share: 802.11r fast roaming (`ieee80211r`, shared `mobility_domain=1076`) + 802.11k/v (neighbor reports + BSS transition) so clients can roam between APs without a full re-auth, `multicast_to_unicast_all` (helps mDNS/casting reliability over Wi-Fi), and are steered by **usteer** (band-steering + load-balancing between the 4 APs, communicating with each other over the `lan` network interface on port `16720`). Steering runs on `usteer` only — no other steering daemon is active on any AP.

**Channels are hand-staggered** across the units to minimize co-channel interference (5GHz is fixed at channel 36 across the fleet to maintain the standby 802.11s backup mesh backhaul alongside client access):

| AP | Model | OS Version | 2.4GHz channel | 5GHz channel |
|---|---|---|---|---|
| `redmi-rm2100-f0` | Redmi AC2100 | OpenWrt 25.12.5 | 1 | 36 |
| `jcg-q20-f1` | JCG Q20 | OpenWrt 25.12.5 | 6 | 36 |
| `jcg-q20-f2` | JCG Q20 | OpenWrt 25.12.5 | 11 | 36 |
| `jcg-q20-f3` | JCG Q20 | OpenWrt 25.12.4 | 1 | 36 |

## DNS / DHCP

- **`dnsmasq` disabled** on all 4 (via `/etc/uci-defaults/10_disable_services`) — no local DHCP or caching resolver competing with the router.
- **Unbound** (validating recursive resolver, port 53, `localservice=0`, running on all 4 APs) — [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#dns--mdns) shows AdGuard Home's `upstream_dns` list includes all 4 APs (`10.0.0.200`–`.203`) alongside Chainedbox's own Unbound and public fallbacks — so each AP's local resolver is a redundant upstream DNS path for the whole LAN.
- **`odhcpd`** (IPv6 DHCP/RA) present but `maindhcp=0` — passive/relay role only, consistent with MikroTik being DHCPv4/v6 authority.
