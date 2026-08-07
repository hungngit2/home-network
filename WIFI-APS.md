# Home — Wi-Fi Access Points (OpenWrt fleet)

Originally read from 4 extracted OpenWrt `sysupgrade`-style config backups (each an `/etc` tree; the extracted folders and their source `.tar.gz` archives have since been superseded by this document and removed from the repo). **Live-verified via SSH on 2026-08-07** using the root credential from the router-admin `wifi/config.json` (see [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#web-root--mntappsrvwww)) — with your explicit go-ahead first, since this touches live Wi-Fi for the whole house. Where the static backups and live state disagreed, live state won; those spots are called out below.

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

All client SSIDs share: 802.11r fast roaming (`ieee80211r`, shared `mobility_domain`) + 802.11k/v (neighbor reports + BSS transition) so clients can roam between APs without a full re-auth, `multicast_to_unicast_all` (helps mDNS/casting reliability over Wi-Fi), and are steered by **usteer** (band-steering + load-balancing between the 4 APs, communicating with each other over the `lan` network interface — i.e. over the mesh/wired backhaul, not a separate channel).

**Channels are hand-staggered** across the 3 JCG units to minimize co-channel interference (5GHz is fixed at 36 on all of them, which only makes sense if they're spaced out enough physically, or DFS/ACS was intentionally disabled in favor of a manually-planned channel plan):

| AP | 2.4GHz channel | 5GHz channel |
|---|---|---|
| `jcg-q20-f1` | 6 | 36 |
| `jcg-q20-f2` | 11 | 36 |
| `jcg-q20-f3` | 1 | 36 |
| `redmi-rm2100-f0` | 1 | 36 |

All 4 APs run **only `usteer`** for steering. `redmi-rm2100-f0`'s backup had leftover `dawn`/`dawn-opkg` config (an older/alternative steering daemon, never actually installed as a package — no binary, no init script, nothing running); **removed live on 2026-08-07** (deleted the orphaned `/etc/config/dawn` and `/etc/config/dawn-opkg` files — nothing to stop, since nothing was ever running).

`redmi-rm2100-f0` also had leftover `mdns_repeater` config (would have relayed mDNS between LAN and IoT VLANs at L2) — same story: config present, no package/binary/init-script installed, nothing running. **Removed live on 2026-08-07** (deleted `/etc/config/mdns_repeater`). mDNS-crossing between LAN and IoT happens centrally on Chainedbox instead now, via **`avahi-daemon`** (confirmed live: `avahi-daemon 0.8`, `enable-reflector=yes`, restricted to `end0`/`end0.10` — see [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#dns--mdns)).

## DNS / DHCP

- **`dnsmasq` disabled** on all 4 (via `/etc/uci-defaults/10_disable_services`) — no local DHCP or caching resolver competing with the router.
- **Unbound** (validating recursive resolver, port 53, `localservice=1`, all 4 configs byte-identical/all-defaults) — confirmed purpose: [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#dns--mdns) shows AdGuard Home's `upstream_dns` list includes all 4 APs (`10.0.0.200`–`.203`) alongside Chainedbox's own Unbound and public fallbacks — so each AP's local resolver is a redundant upstream DNS path for the whole LAN, not a leftover.
- **`odhcpd`** (IPv6 DHCP/RA) present but `maindhcp=0` — passive/relay role only, consistent with MikroTik being DHCPv4/v6 authority.
- `redmi-rm2100-f0` has `udpxy-opkg` / `unbound-opkg` config files sitting alongside the live `udpxy` / `unbound` configs, with different settings (e.g. `udpxy-opkg` binds port `4022` vs. the active config's `8089`, and is `disabled=1`). The `-opkg` naming plus a `profile.d/apk-cheatsheet.sh` login script on every unit strongly suggests this fleet recently migrated from OpenWrt's old `opkg` package manager to the newer `apk` one — these are leftover pre-migration configs, not a second active instance. Safe to remove once confirmed unused, same as the stale Docker-Compose files flagged in [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#mnt-appsrv--full-contents).

## IPTV support services

`udpxy` (multicast→HTTP relay, port `8089`) still runs on all 4 APs — untouched, out of scope for this cleanup, and mirrors the same tool on [Chainedbox](ARMBIAN-SERVER.md#iptv-stack).

`rtp2httpd` was a different story: the backup for `jcg-q20-f1` showed it configured, and the user recalled having moved that functionality to Chainedbox — but a live check on 2026-08-07 found `rtp2httpd` **still actually installed, enabled, and running** (2 live processes) on `jcg-q20-f1`, unlike `dawn`/`mdns_repeater` above which really were just orphaned config with nothing behind them. **Removed live**: stopped and disabled the service, purged the `rtp2httpd`, `luci-app-rtp2httpd`, and `luci-i18n-rtp2httpd-zh-cn` packages via `apk del`, then removed the leftover `/etc/config/rtp2httpd` (package removal doesn't clean up UCI config files on OpenWrt). Verified `hostapd`/`wpa_supplicant`/`usteerd` still healthy afterward — no Wi-Fi impact. That functionality now lives solely on Chainedbox (see [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#iptv-stack)).

## Management & security posture

- **SSH (`dropbear`)**: enabled, port 22, **both password auth and root password auth on**. Root does have a password hash set (not blank — the passwordless-root warning banner script wouldn't fire), but this is still root-over-password on every AP, reachable from anywhere those SSH ports are exposed to (LAN-only per the Mikrotik firewall's WAN-drop rules, so not internet-facing).
- **LuCI / `uhttpd`**: HTTP+HTTPS web UI on all interfaces (`0.0.0.0:80`/`:443`), self-signed cert generated locally (`commonname=OpenWrt` default — not customized).
- **RADIUS** (802.1X/enterprise Wi-Fi auth): configured but `disabled=1` on every unit, with template/placeholder credentials (`clients` file just has the default `0.0.0.0/0 radius` shared secret, `users` file is an empty PEAP wildcard template) — not a live secret, just an unused feature left in its default state.
- **Nightly reboot**, staggered by a couple minutes across the fleet (`jcg-q20-f1` at 04:02, the other three at 04:00) — same "reboot on a schedule" pattern seen on [Chainedbox](ARMBIAN-SERVER.md#scheduled-tasks), suggesting a house-wide habit of nightly reboots for stability rather than anything specific to one device's constraints.
- **`attendedsysupgrade`** client is configured (points at `https://sysupgrade.openwrt.org`) — the mechanism for pulling a browser/LuCI-initiated firmware rebuild-and-flash, not an auto-updater.

## Version check

Confirmed live on 2026-08-07 (`/etc/openwrt_release`, ramips/mt7621 target, mipsel_24kc) — the static backups don't include this (a `sysupgrade` backup only captures `/etc`, and this build keeps its version string elsewhere):

| AP | Installed | Latest stable |
|---|---|---|
| `redmi-rm2100-f0` | 25.12.5 | 25.12.5 | ✅ current |
| `jcg-q20-f1` | 25.12.5 | 25.12.5 | ✅ current |
| `jcg-q20-f2` | 25.12.5 | 25.12.5 | ✅ current |
| `jcg-q20-f3` | **25.12.4** | 25.12.5 | ⚠️ one service release behind |

All 4 are on the **25.12 "Dave's Guitar"** series — this is also where the `opkg`→`apk` package-manager switch (evidence for which was flagged earlier from the `-opkg`-suffixed leftover config files) actually happened, confirming that theory. (Source: [OpenWrt 25.12.5 service release announcement](https://forum.openwrt.org/t/openwrt-25-12-5-service-release/251479))

## Notes

- **Credentials found, not reproduced**: the 4 Wi-Fi PSKs (`home`, `home IoT`, `home⁺`) and the mesh key are in plaintext in these config backups — flagged here rather than copied into this doc, same treatment as the router-admin passwords found in [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md#web-root--mntappsrvwww)'s `wifi/config.json`. (`redmi-rm2100-f0`'s `dawn` shared encryption key is now moot — that config was removed live, see above.)
- **Cleanup performed live on 2026-08-07**: `dawn`, `mdns_repeater` (both `redmi-rm2100-f0`, orphaned config only) and `rtp2httpd` (`jcg-q20-f1`, an actually-running service) removed as requested. `udpxy` was left untouched on all 4 (not in scope). Verified core Wi-Fi services (`hostapd`/`wpa_supplicant`/`usteerd`) healthy on `jcg-q20-f1` after its change.
- This doc mixes a static backup read with live verification/changes as of 2026-08-07 — any config not called out as "removed live" above still reflects the original backup snapshot and could have drifted since.
