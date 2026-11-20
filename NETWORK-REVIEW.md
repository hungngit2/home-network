# Network Review — 2026-11-20

A read-only review of the whole network: the hEX S router, the four OpenWrt APs, the Wyse 5070 host and Home Assistant. Findings are ranked by impact. "Fixed" means the change was applied and verified on 2026-11-20. Everything else is open.

## What happened today

The Floor 1 breaker feeds the hEX S and the Wyse, and both rebooted together. The hEX S has no battery-backed clock, so it came up with a stale time 16 h behind. Its NTP server had `use-local-clock=yes`, so it served that wrong time to the Wyse and the APs while it was unsynced. The Wyse stepped to that time and then only slewed toward the right one. Home Assistant then stamped about 8 h of history and energy statistics with the previous day's date, so the Energy panel showed no data for "today". At the same time, the 750 GB NAS disk threw medium errors and its filesystem dropped to read-only.

## Fixed

| Area | Change |
|---|---|
| hEX S NTP | `/system ntp server set use-local-clock=no`, so it no longer serves time before it has synced |
| Wyse clock | `/etc/chrony/conf.d/10-always-step.conf` (`makestep 1 -1`); `chrony-wait.service` enabled; `docker.service.d/10-wait-ntp.conf` makes containers wait for the sync (180 s max). See `scripts/tune-wyse5070.sh` step 12 |
| HA dashboards | Replaced the "Solar Energy" and "Map" dashboards with one default dashboard (Home / Energy / Battery & Inverter / System) |
| HA safety | `switch.cb_floor_1` is disabled in the entity registry. Floor breakers and the network relay are status-only: switching one off from HA could cut the router and the Wyse with no remote way back |
| HA templates | `availability:` guards on the 8 energy kWh templates; new Battery Status and Battery Runtime sensors |
| HA access | HTTPS only at `https://ha.<domain>` through nginx. HA binds 127.0.0.1/::1, has trusted proxies, and bans an IP after 5 failed logins. Settings live in `.storage/http` because HA 2026.9 ignores an `http:` block in YAML |

## High

1. **The NAS disk (sdb, WD 750 GB on USB) is failing.** It has 44,904 reallocated sectors (normalised 152, threshold 140), 196 ATA errors and only 352 power-on hours. The ext4 journal aborted and the filesystem is mounted read-only. smartd only watches sda, and `-m root` mail goes nowhere. **Copy the data off now, replace the disk, then `fsck` it.** Add `/dev/sdb -d sat -a` to `smartd.conf` and a working alert path (ntfy or msmtp).
2. **No backups of anything.** `/appsrv` (HA, AdGuard, Jellyfin, www, KodBox, qdrant) sits on one flash module, media sits on the failing disk, and HA's automatic backups have never run. Add a nightly restic or borg job covering `/appsrv`, `/etc` and a MariaDB dump, sent off-box, and enable HA's daily backup.
3. **Aria2 RPC is a root shell for the LAN.** It runs as root with `rpc-listen-all=true`, `rpc-allow-origin-all=true` and no `rpc-secret`, so any LAN device, or any web page a LAN user opens (via CSRF), can write files as root. Set `rpc-secret`, run it as a dedicated user, and bind it to localhost.
4. **Every subdomain vhost is public, code-server included.** Only the apex/default vhost has the LAN allow list. code-server has seen 2,826 failed logins since Nov 15, and its user can sudo. Add the LAN+VPN allow block (as an `include` snippet) to `code.*` and `kodbox.*` at least. Keep `ha`/`jellyfin` public only on purpose, with TOTP 2FA on HA (not enabled yet).
5. **HA energy history for 2026-11-20 07:14–15:06 sits on the wrong date.** A repair script, `/root/ha-timefix.sh` on the Wyse, backs up the DB, moves those records to real time and lets HA rebuild the statistics. It has not been run.
6. **AP 802.11k and usteer ignore 5 GHz on all four APs.** At boot, usteer registers the 5 GHz BSSes before hostapd has an SSID, so its `ssid_list` filter drops them and never re-reads them, and `rrm_nr_list` stays empty. Restart usteer after the radios are up, e.g. `(sleep 120; service usteer restart) &` in `rc.local`. Make `tune-openwrt-aps.sh` check that `local_info` lists a 5 GHz frequency.
7. **AP-0 (Redmi) is OOM-killing Unbound**, four times today. Unbound is the victim; unreclaimable kernel slab peaked at about 48 MB. Set Unbound `resource=tiny`, disable the 5 GHz IoT BSS, and watch `Slab` in `/proc/meminfo`. If it keeps happening, remove AP-0 from AdGuard's upstreams.

## Medium

- **Qdrant** is published on `0.0.0.0:6333/6334` with no API key and is reachable from IoT. Docker DNAT bypasses nft-hostguard's `input` chain. Publish it on `127.0.0.1` and set `QDRANT__SERVICE__API_KEY`.
- **OmniRoute** (`admin` user service) listens on `0.0.0.0:20128` and is reachable from IoT. Bind it to localhost.
- **A dead `@reboot` cron entry** in `admin`'s crontab would start `kilo serve --hostname 0.0.0.0` if the extension came back. Remove it.
- **The 5 GHz IoT BSS is up on all APs**, though the docs say it's disabled. Disable it (`wifinet5` on AP-0, `wifinet6` on the JCGs) after moving its one client.
- **No WPA3 on the APs.** LAN and Guest use WPA2-PSK with PMF optional. Try `sae-mixed` on LAN and Guest (FT-SAE is supported), and keep IoT on psk2. hostapd also logs "key addition failed" during FT reassociations (14–61 times per AP per day).
- **AP management.** dropbear allows password and root-password logins, and uhttpd serves plain HTTP on all addresses. IoT and Guest are blocked at L2, but a routed path depends only on the router's rules. Turn password auth off and bind dropbear and uhttpd to the LAN address.
- **The APs also take time from the router over DHCP** (`system.ntp.use_dhcp`); both AP boots today stepped to a stale time first. The router fix above covers this.
- **The HA cleanup list is waiting for an explicit go:** dead OwnTone, Phicomm and Tuya-IR integrations, 4 stale Cast devices, the leftover `lumentree` component, and entity IDs `mosquito_repellent_*` / `sac_xe_*` to rename to chest_freezer / bike_charger.

## Low

- SSH on the Wyse has `X11Forwarding yes`. Set it to `no`.
- udev rule `99-nasdata.rules` line 2 writes USB power attributes on interfaces, not devices, which is harmless noise.
- Samba guest-writable shares are open to the whole LAN (a documented choice).
- Guest isolation on the APs is only within a BSS. Add `bridge_isolate=1` and a router filter on VLAN 12.
- AP RF: the JCGs run 2.4 GHz at 26 dBm against 20 dBm on AP-0; AP-0 and AP-3 share channel 1; `country=US` on a Vietnamese site. Use about 18–20 dBm on 2.4 GHz and `country=VN`.
- The `radius` init script is enabled on the APs with nothing behind it.
- A stale Let's Encrypt cert for an old DDNS name fails renewal twice a day. `certbot delete` it.
- HA: there's a single admin user and no household non-admin user yet.

## Router (hEX S)

The VLAN-filtering migration (`scripts/hexs-vlan-filtering.rsc`) is **fully applied**. IoT and Guest run on br-lan with VLAN filtering, the old br-iot/br-guest bridges and vlan-e* interfaces are gone, and the "vlan parent is a slave" warning no longer appears. The script is not idempotent: re-running it duplicates VLANs. Its rollback files were saved to RAM and lost at reboot.

**From the WAN only these are reachable:** TCP 80/443 to the Wyse and UDP 13231 for WireGuard. SSH, WinBox and WebFig are LAN+VPN only. Each road-warrior peer is limited to its own /32 and /128.

- **High: IPv4 has no default deny on non-WAN interfaces.** Input and forward drop only `in-interface-list=wan`, so br-iptv (the ISP's /21), sfp1 (the modem subnet) and vlan-sfp1.10 can open new connections into the LAN. br-iptv also gets most UDP to the router, including BFD. IPv6 already has these drops. Fix: accept IPTV multicast forward, then `drop connection-state=new connection-nat-state=!dstnat in-interface-list=!private` (forward) and `drop in-interface-list=!private` (input). Narrow the IPTV UDP accept to `dst-port=68` and disable BFD.
- **Medium: "Unblock Sites" fails open.** wg-out1 is disabled and wg-out2 has no handshake. With scope/target-scope 40, the VPN-out routes resolve through the main default, so that traffic leaves over plain PPPoE. Fix: set scope 20 / target-scope 20 on those routes, add a blackhole default to `to-vpn-out`, and fix wg-out2 on the server side.
- **Medium: WAN access depends on DNS at boot.** The WireGuard-in accept and the 80/443 dst-nat match the `wan-ip` list, which is filled by resolving the DDNS name through the Wyse/APs. It took 4–10 min after PPPoE came up. Fix: use `in-interface-list=wan` for the WireGuard accept, and add a public resolver as the router's last DNS server.
- **Medium: ether5 (the Wyse) links at 100 Mbps.** The link partner doesn't advertise 1G. Re-terminate or replace the cable.
- **Low:** Guest can reach the modem and IPTV subnets, and IoT can reach sfp1. IPv6 input still accepts IKE/AH/ESP/UDP-traceroute from the WAN with no IPsec configured. `admin` has no `address=` limit and SSH password login is allowed (`yes-if-no-key`). Bridge `ingress-filtering=no`, and ether3/4 accept tagged frames. The NTP client depends on pool hostnames: add `162.159.200.1` / `162.159.200.123` by IP.

## Documentation drift found

- **DEBIAN-SERVER.md:**
  - The kernel is 6.12.117.
  - The AdGuard query log is kept 7 days (about 460 MB).
  - Samba `interfaces` live is `lo enp1s0 enp1s0.10 wlp0s12f0`; the tune script only adds the line when it's missing.
  - smartd watches sda only.
  - sdb SMART is no longer clean.
  - qdrant, OmniRoute and the seven nginx vhosts under `/mnt/appsrv/nginx/` aren't listed.
  - Docker-published ports bypass nft-hostguard.
- **MIKROTIK-HEXS.md / README:**
  - RouterOS is 7.25.
  - wg-out1 is disabled and wg-out2 is dead.
  - The rollback files aren't on the router.
  - The site-to-site peer lacks `10.0.100.100` / `fd39:10:100::100`.
  - The IGMP timers differ from the doc.
  - NAT66 masquerade has no `src-address`.
  - The NTP toggles are gone.
  - The LTE MTU was 1280 at boot.
  - The README diagram and VLAN table still show br-iot/br-guest.
  - The `dns-internal` entry for 10.0.0.100 is still commented "chainedbox".
- **WIFI-APS.md / README:**
  - The firmware is 25.12.6.
  - The 5 GHz IoT BSS is up.
  - The usteer gotcha also happens at boot, and 5 GHz steering doesn't work.
  - AP-0 Unbound OOM is not fixed.
  - AP-3's mesh port forwards (designated port).
  - `wpa_disable_eapol_key_retries` in the template doesn't match live.
