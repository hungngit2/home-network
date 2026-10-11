# Home — Wi-Fi Access Points (OpenWrt fleet)

> **Security notice (2026-10-11).** Until this date, `scripts/setup-openwrt-ap.sh` shipped the live SSIDs, all three Wi-Fi passwords and the mesh key as built-in defaults. This repo is public, so those values remain in its git history from commit `32062a1` onward. Rotate the LAN, IoT and Guest passwords and the mesh key; the mesh key must match on all four APs. The script now has no secret defaults and refuses to run without them.

State below was verified live on 2026-10-11.

## Fleet overview

| AP | Hostname | Model (SoC / Wi-Fi) | Address (MikroTik DHCP reservation) | OpenWrt |
|---|---|---|---|---|
| AP-0 | `redmi-rm2100-f0` | Redmi AC2100: MT7621, MT7603 2.4 GHz + MT7615 5 GHz, 128 MB | `10.0.0.200` | 25.12.5 |
| AP-1 | `jcg-q20-f1` | JCG Q20: MT7621, MT7915 dual-band Wi-Fi 6 | `10.0.0.201` | 25.12.5 |
| AP-2 | `jcg-q20-f2` | JCG Q20 | `10.0.0.202` | 25.12.5 |
| AP-3 | `jcg-q20-f3` | JCG Q20 | `10.0.0.203` | 25.12.5 |

All four run kernel 6.12.94 (`ramips/mt7621`).

All four are **dumb APs**. Gigabit Ethernet is the primary backhaul, and a 5 GHz 802.11s mesh is the standby path. None of them routes or serves DHCP: `dnsmasq` and `odhcpd` are stopped and disabled, so [the MikroTik](MIKROTIK-HEXS.md) is the only DHCP authority. Each AP's management interface is a DHCP client on VLAN 1. Its fixed address comes from the router's reservation, not from a static IP on the AP.

## VLANs, mesh and STP

Each AP's `br-lan` is a VLAN-aware DSA bridge carrying the router's three segments: VLAN 1 (LAN), 10 (IoT) and 12 (Guest). They are untagged/tagged on the `wan` uplink and tagged over the mesh interface:

```
config bridge-vlan
    vlan 1   (LAN)   ports: LAN ports untagged, wan untagged, mesh tagged
config bridge-vlan
    vlan 10  (IoT)   ports: IoT LAN port untagged (where present), wan tagged, mesh tagged
config bridge-vlan
    vlan 12  (Guest)                                       wan tagged, mesh tagged
```

Which physical port lands on IoT differs per unit:
- AP-0 (Redmi) puts `lan1`+`lan3` on IoT and `lan2` on LAN.
- AP-1 puts `lan2` on IoT.
- AP-2 has no IoT port.
- AP-3's layout differs from AP-1's.

Run `uci show network | grep -A3 bridge-vlan` before plugging IoT gear into an AP.

The mesh and STP work together like this:
- **Mesh**: SAE-encrypted 802.11s on 5 GHz channel 36, with `mesh_fwding=1`. Every AP peers with the other three. Peer links run at only about 6–13 Mbit/s, so the mesh is an outage fallback, not a second uplink.
- **STP**: every AP's `br-lan` runs STP (`stp=1`). While the wired trunk is up, STP blocks the mesh ports to prevent a loop, and it unblocks them when an AP loses its cable. This was verified when AP-1's `wan` flapped.
- **No failover daemon**: the link-state failover daemon in `setup-openwrt-network.sh` is only for swconfig hardware, so it isn't installed on these DSA units.
- **STP root**: the router has been root since 2026-10-11 (`br-lan` priority 0x1000). Before that, AP-3 was root for the whole LAN, because netifd's default bridge priority (0x7fff) beats RouterOS's (0x8000), and its weekly reboot re-elected the root every Monday.

## Wireless

| SSID (placeholder) | VLAN | Bands | Security | Roaming |
|---|---|---|---|---|
| `home` | LAN | 2.4 + 5 GHz | WPA2-PSK (CCMP), PMF optional (`ieee80211w=1`) | 802.11r (FT over the air), k, v |
| `home IoT` | IoT | **2.4 GHz only** | WPA2-PSK, PMF off | **802.11r off**, k and v on |
| `home⁺` | Guest | 2.4 + 5 GHz | WPA2-PSK, PMF optional, **client isolation** (`isolate=1`) | 802.11r, k, v |
| `home-mesh` | backhaul | 5 GHz | SAE | — |

Why the IoT SSID differs:
- **802.11r off**: the Xiaomi Mi Box 3 (Android TV 9, `10.0.1.3` on IoT) and older IoT Wi-Fi chips fail to join a network that advertises FT-PSK. The setup script, the template and the wifi-config-tool all keep 802.11r off for IoT.
- **No 5 GHz IoT BSS**: it never came up next to the mesh on any AP (link down, channel 0), and the IoT gear is 2.4 GHz anyway. It was disabled on 2026-10-11.

All client SSIDs share these settings:
- `mobility_domain=1076` and `ft_psk_generate_local=1`;
- `ft_over_ds=0` (FT over the air, the OpenWrt default since 23.05; was 1 until 2026-10-11);
- `wpa_disable_eapol_key_retries=1` on LAN and Guest;
- `multicast_to_unicast_all=1`, `mcast_rate=24000`, `basic_rate='12000 24000'`, `cell_density=high`.

### Radios

| AP | 2.4 GHz | 5 GHz |
|---|---|---|
| AP-0 | ch 1, HT20, 20 dBm | ch 36, VHT80 |
| AP-1 | ch 6, HE20 | ch 36, HE80 |
| AP-2 | ch 11, HE20 | ch 36, HE80 |
| AP-3 | ch 1, HE20 | ch 36, HE80 |

- **AP-0 changes (2026-10-11)**: its 2.4 GHz power was cut from 30 dBm, so it no longer out-shouts the JCGs. Its 5 GHz width went from VHT160 to VHT80, because 160 MHz on channel 36 spans the DFS channels 52–64 and a radar hit would move it off the mesh channel.
- **Shared channel 36**: every 5 GHz radio uses it because the mesh needs a common channel, so ch 36 sits at about 80–90 % utilization.
- **Shared 2.4 GHz channel 1**: AP-0 and AP-3 both use it.

### usteer

usteer runs on every AP over the `lan` network:
- It steers only the LAN and Guest SSIDs (`ssid_list`); IoT is not steered.
- Band steering is on (min SNR −65, every 120 s); load balancing and load kick are off.
- Roam thresholds: scan at −70, trigger at −74, kick below −82 dBm; up to 8 neighbor reports.

**Restart gotcha**: on these MT76 radios, `wifi reload` can leave the 5 GHz AP interfaces down next to the mesh (`operstate` down, channel 0). usteer then stops tracking the 5 GHz BSSes and drops them from 802.11k neighbor reports. Use `wifi down; wifi up` instead, then `service usteer restart`. `tune-openwrt-aps.sh` does this and retries once.

## DNS / DHCP

- **`dnsmasq` and `odhcpd`** are stopped and disabled on all four APs (on 2026-10-11 they were idle on three of them).
- **Unbound** answers on port 53 with `localservice=1`, `validator=0`, `rebind_protection=1`, `dns64=0` and no root-zone download. AdGuard Home on the Wyse ([DEBIAN-SERVER.md](DEBIAN-SERVER.md#services)) queries all four APs in parallel with its local Unbound. The router also lists them as DNS upstreams.
- **AP-0 DNS64 fix (2026-10-11)**: AP-0 had DNS64 on, so through AdGuard's parallel queries it sometimes handed LAN clients synthetic `64:ff9b::` addresses for IPv4-only sites, which stalled them. It also had root-zone download on and had OOM-killed Unbound once on its 128 MB. Both are fixed.

## Management and security

- **Firewall**: an `untrusted` zone covers `br-lan.10` and `br-lan.12`, with input and forward set to DROP. SSH, LuCI and Unbound on the APs can't be reached from IoT or Guest clients. Bridged client traffic isn't touched, because `br_netfilter` isn't loaded. The stale `/etc/firewall.user` include and AP-0's old mDNS accept rule were removed.
- **SSH**: dropbear accepts keys (`~/.ssh/id_hungnguyen`) and passwords from the LAN.
- **LuCI**: plain HTTP on the LAN.
- **Weekly reboot** (JCGs only, Mondays): staggered 04:00 / 04:20 / 04:40 so the house never loses all Wi-Fi at once.
- **Logging**: cron log level 9 (cron used to log 3 lines/min). The syslog buffer is 64 KB, about an hour. hostapd's `handle_probe_req: send failed` lines (hundreds per hour) are harmless.

## Scripts and tools

| Script / tool | What it does |
|---|---|
| [`scripts/tune-openwrt-aps.sh`](scripts/tune-openwrt-aps.sh) | Applies the 2026-10-11 tuning described above to a live AP. Idempotent. Run it one AP at a time: `ssh root@10.0.0.20X 'sh -s' < scripts/tune-openwrt-aps.sh`. |
| [`scripts/setup-openwrt-ap.sh`](scripts/setup-openwrt-ap.sh) | Full reprovision: packages, DSA VLAN layout, radios, SSIDs, mesh, usteer, Unbound. |
| [`scripts/setup-openwrt-network.sh`](scripts/setup-openwrt-network.sh) | Network + mesh only, leaving client SSIDs untouched. Handles swconfig hardware too and installs the link-state failover daemon there. |
| [`wifi-config-tool/`](wifi-config-tool/) | Web UI on the Wyse (`/wifi-config-tool`). Per-AP SSID edits, bulk add/update/remove across a group, and **Add AP → "Copy Wi-Fi config from"** to clone every client SSID from an existing AP. The clone matches radios by band, enables radios and copies country; it leaves channels, the mesh and per-AP MAC/NAS-ID alone, and keeps `/etc/config/wireless.bak-clone`. The same copy runs from an AP's page for retries. |

`setup-openwrt-ap.sh` usage:

```bash
# Interactive (AP menu: redmi-rm2100-f0, jcg-q20-f1, jcg-q20-f2, jcg-q20-f3):
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-openwrt-ap.sh | sh

# Target one AP by name:
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-openwrt-ap.sh | sh -s -- jcg-q20-f1
```

Non-interactive. Passwords and the mesh key are required (8+ characters); SSIDs default to the placeholders above:

```bash
export NON_INTERACTIVE=true
export AP_NAME="jcg-q20-f1"
export SSID_LAN="home"        PASS_LAN="<lan-password>"
export SSID_IOT="home IoT"    PASS_IOT="<iot-password>"
export SSID_GUEST="home⁺"     PASS_GUEST="<guest-password>"
export MESH_ID="home-mesh"    MESH_KEY="<mesh-key>"
export MESH_IFNAME="mesh0"    # bridge port name of the mesh interface
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-openwrt-ap.sh | sh
```

The live APs still use their original mesh interface name. Re-running the script switches them to `MESH_IFNAME` consistently, in both the wireless and the bridge-port config.

## Configuration templates (`configs/openwrt-aps/`)

| File | Component | Description |
|---|---|---|
| [`network.template`](configs/openwrt-aps/network.template) | Network | DSA bridge `br-lan` with VLAN 1, 10, 12 filtering; mesh port `mesh0` |
| [`wireless.template`](configs/openwrt-aps/wireless.template) | Wireless | LAN + Guest on both bands, IoT 2.4 GHz only (no 802.11r), mesh |
| [`usteer.template`](configs/openwrt-aps/usteer.template) | Roaming | usteer band-steering and signal thresholds |
| [`unbound.template`](configs/openwrt-aps/unbound.template) | DNS | Local recursive Unbound on port 53 |
| [`dhcp.template`](configs/openwrt-aps/dhcp.template) | DHCP / dnsmasq | Dumb-AP settings: no listener, no DHCP |
| [`system.template`](configs/openwrt-aps/system.template) | System | Hostname, timezone `Asia/Ho_Chi_Minh`, NTP servers |
