# Home Network

A 3-tier home network: a MikroTik router (`home`) doing routing/firewall/VPN/DHCP, a mesh of 4 OpenWrt access points for Wi-Fi, and an Armbian TV-box (`chainedbox`) running the household's actual services (media, DNS blocking, dashboard, home automation). Everything below is cross-referenced from the 3 detail docs in this repo, each independently live-verified where possible.

## Network diagram

```
                                        INTERNET
                                           |
                +--------------------------+--------------------------+
                |                          |                          |
          VNPT PPPoE                 Vinaphone LTE               ISP IPTV feed
      (WAN1, vlan-sfp1.10)          (WAN2, lte1 modem)      (multicast, vlan-sfp1.99/.1100)
                |                          |                          |
                +------------+             |             +------------+
                             |             |             |
                    [ Hisense LTE3415-SCA+ bridge modem, on sfp1 ]
                             |             |             |
   remote clients ---------->|             |             |
   WireGuard-in :13231       |             |             |
                             v             v             v
     +-------------------------------------------------------------------------------+
     |                    MikroTik hEX S "home"  (10.0.0.254)                       |
     |         RB760iGS · RouterOS 7.23.2 · DHCP + DNS + Firewall + VPN + PCC LB      |
     |---------------------------------------------------------------------------------|
     |   br-lan (vlan1)     br-iot (vlan10)     br-guest (vlan12)     br-iptv          |
     |   10.0.0.0/24        10.0.1.0/24         192.168.12.0/24       IGMP-proxied     |
     +----+--------+--------+----+------------------+------------------+---------------+
          |        |        |    |                  |
       ether1    ether2   ether3 ether4           ether5
       Switch     Wifi   Chainedbox  (spare)     "IoT - NVR"
          |        |    10.0.0.100/                 |
          |        |    10.0.1.15                    +--> NVR-Home, 5x IP cameras,
          |        |         |                            Lumentree 4kW inverter,
          |        |         |                            JK-BMS monitor, misc IoT
          |        |         v
          |        |    +-----------------------------------------------------------+
          |        |    |            Chainedbox  (Armbian, RK3328 "L1 Pro")          |
          |        |    |   /mnt/appsrv (59G, configs+data) · /mnt/nasdata (687G)    |
          |        |    |-------------------------------------------------------------|
          |        |    | nginx+PHP-FPM  -> /ytb dashboard, /iptv, /kod, /wifi, /jk   |
          |        |    | AdGuard Home :53/:3000 -> Unbound :5335 -> upstreams        |
          |        |    | avahi-daemon  -- mDNS reflect: end0(LAN) <-> end0.10(IoT)   |
          |        |    | Jellyfin :8096  -- media library                           |
          |        |    | OwnTone :3689/:3688/:6600 -- music/DAAP/AirPlay             |
          |        |    | rtp2httpd + udpxy + go2rtc -- IPTV relay + HA camera stream |
          |        |    | Aria2 :6800  -- downloads -> nasdata                        |
          |        |    | Home Assistant (Docker, host-net) :8123 -- smart home       |
          |        |    | Samba (smbd/nmbd) -- apps/docs/downloads/media shares       |
          |        |    +-------------------------------------------------------------+
          |        |
          +--------+----------------- trunk (vlan1 + vlan10 + vlan12) ----------------+
                                                                                        |
                                                                                        v
     +----------------------------------------------------------------------------------+
     |                4x OpenWrt dumb APs, meshed over 802.11s "home-mesh"              |
     |------------------------------------------------------------------------------------|
     |  redmi-rm2100-f0    jcg-q20-f1        jcg-q20-f2        jcg-q20-f3                  |
     |  10.0.0.200         10.0.0.201        10.0.0.202        10.0.0.203                  |
     |  (AP-0)             (AP-1)            (AP-2)            (AP-3)                      |
     |                                                                                      |
     |  SSIDs broadcast identically by all 4:                                              |
     |    "home"       -> vlan1  (LAN)                                                     |
     |    "home IoT"    -> vlan10 (IoT, no PMF)                                            |
     |    "home⁺"       -> vlan12 (Guest, PMF required)                                    |
     |    "home-mesh"   -> 802.11s backhaul (5GHz, SAE-encrypted)                          |
     |                                                                                      |
     |  usteer (roaming/band-steering) + own udpxy + own Unbound                           |
     |  (each AP's Unbound is a redundant upstream in AdGuard Home's resolver chain)       |
     +--------------------------------------------------------------------------------------+
                    |                  |                  |                  |
              LAN / IoT / Guest   LAN / IoT / Guest   LAN / IoT / Guest  LAN / IoT / Guest
                 clients              clients              clients          clients
           (laptops, phones,     (cameras, sensors,    (visitor phones,   (wherever a 4th
            TVs, consoles)        smart plugs, etc)      laptops, etc)     AP happens to sit)

     WireGuard-out x2 (from the MikroTik, always-on): --> VPN-SG
                                                        --> VPN-HK
     Used for a small hand-picked "Unblock Sites" list (BBC, Medium) forced through the tunnel
     instead of the local ISP path. OpenVPN server also available for remote client access.
```

## VLANs

| VLAN | Bridge (router) | Subnet | Purpose | Isolation |
|---|---|---|---|---|
| 1 | `br-lan` | `10.0.0.0/24` | Trusted LAN — Chainedbox, laptops, TVs | Full access |
| 10 | `br-iot` | `10.0.1.0/24` | Cameras, NVR, smart-home, solar inverter | Can reach Chainedbox only; blocked from LAN |
| 12 | `br-guest` | `192.168.12.0/24` | Guest Wi-Fi | Blocked from LAN and IoT |
| — | `br-iptv` | ISP-assigned, no default route | ISP multicast IPTV feed | IGMP-proxied into `br-lan`/`br-iot` only; multicast never leaks back to the ISP |
| 99 | (management, on `sfp1`) | — | ISP-side management VLAN | — |

Chainedbox is the only server dual-homed onto two of these (`end0`=LAN, `end0.10`=IoT) — everything else reaches it by routing through the MikroTik. Guest never touches Chainedbox at all (no VLAN-12 interface exists there), which is also why `avahi`'s mDNS reflection stops at LAN↔IoT and never extends to Guest.

## Devices at a glance

| Device | Role | Address(es) | Detail doc |
|---|---|---|---|
| **home** (MikroTik RB760iGS "hEX S") | Router: DHCP, DNS forwarding, firewall, dual-WAN load balancing, VPN | `10.0.0.254` (LAN), `10.0.100.254` (WireGuard-in) | [MIKROTIK-HEXS.md](MIKROTIK-HEXS.md) |
| **chainedbox** (Armbian, RK3328 TV box) | Application server: DNS blocking, media, dashboard, home automation, downloads, file share | `10.0.0.100` (LAN), `10.0.1.15` (IoT) | [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md) |
| **redmi-rm2100-f0** | Wi-Fi AP (mesh) | `10.0.0.200` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f1** | Wi-Fi AP (mesh) | `10.0.0.201` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f2** | Wi-Fi AP (mesh) | `10.0.0.202` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f3** | Wi-Fi AP (mesh) | `10.0.0.203` | [WIFI-APS.md](WIFI-APS.md) |

## Documentation

- **[MIKROTIK-HEXS.md](MIKROTIK-HEXS.md)** — router config: VLANs, dual-WAN policy routing, VPN (WireGuard ×3 + OpenVPN), firewall, DNS, IGMP/multicast, scheduler scripts. Read from a static `/export` (SSH is deliberately disabled on the router) — not live-verified.
- **[ARMBIAN-SERVER.md](ARMBIAN-SERVER.md)** — full service inventory for Chainedbox: storage layout, every service (nginx, AdGuard Home, Unbound, avahi, Jellyfin, OwnTone, rtp2httpd/udpxy/go2rtc, Aria2, Home Assistant, Samba), package/version audit, network/VLAN wiring. Live-verified via SSH.
- **[WIFI-APS.md](WIFI-APS.md)** — the 4-AP fleet: VLAN/SSID scheme, mesh backhaul, roaming, per-AP config drift, OpenWrt version audit. Live-verified via SSH, including 3 corrections after the original backup snapshot (`dawn`, `mdns_repeater`, `rtp2httpd` removed live from the APs — that functionality now lives solely on Chainedbox).
- **[configs/chainedbox/](configs/chainedbox/)** — the actual raw config files pulled live from Chainedbox (nginx vhost, systemd units, `aria2.conf`, `smb.conf`, `AdGuardHome.yaml`, `avahi-daemon.conf`, Unbound conf.d, `docker/daemon.json`, netplan, crontab, etc.), archived here specifically so a future reinstall/migration doesn't have to reverse-engineer this server from scratch the way this doc set did. **Secrets redacted** — see below. The router and APs aren't archived this way since both have a proper built-in config-export feature you can always regenerate (`/export` on RouterOS, `sysupgrade` backup on OpenWrt).

## Secrets & credentials

None of the docs or archived configs in this repo contain live plaintext secrets — every credential found while writing them was flagged and redacted rather than copied in:

- Wi-Fi PSKs (`home`, `home IoT`, `home⁺`, mesh key) — live only in each AP's own config
- The 4 APs' `root`/admin password — was in `wifi/config.json` on Chainedbox; redacted to `<REDACTED>` in [configs/chainedbox/wifi-config.json](configs/chainedbox/wifi-config.json)
- `rtp2httpd`'s basic-auth password — redacted in [configs/chainedbox/rtp2httpd.conf](configs/chainedbox/rtp2httpd.conf)
- AdGuard Home's admin login — stored as a bcrypt hash; redacted anyway in [configs/chainedbox/AdGuardHome.yaml](configs/chainedbox/AdGuardHome.yaml) rather than committing a crackable hash
- MikroTik WireGuard keys shown in `MIKROTIK-HEXS.md` are **public** keys only — safe by design
- SSH access to `chainedbox` and the router use key-based auth (`~/.ssh/config` on the client) — no passwords involved there

## Known housekeeping (aggregated from all 3 docs)

Nothing urgent — mostly routine drift and a few loose ends worth a look next time you're in there:

- **Version drift**: `nginx`/`unbound`/`samba` a few Ubuntu point-releases behind on Chainedbox (routine `apt upgrade`); Jellyfin 6 patches behind; Docker Engine several releases behind; Home Assistant's `:latest` tag hasn't actually moved in 3 months; `jcg-q20-f3` one OpenWrt service release behind the other 3 APs.
- **Stale leftovers not yet cleaned up**: old OwnTone Docker-Compose files under `/mnt/appsrv/docker/owntone/` (pre-migration to native systemd), a stopped `homeassistant_new` container, `KodExplorer-master (1).zip` sitting next to the unpacked app, `udpxy-opkg`/`unbound-opkg` leftover config on `redmi-rm2100-f0` from the OpenWrt opkg→apk migration.
- **The MikroTik's giant `Vietnam` address-list** (900+ CIDRs) is defined and actively maintained but referenced by zero live rules — worth confirming intent (finish wiring it up, or drop it) rather than leaving it as dead weight.
- **nginx access log** on Chainedbox has no rotation and is already ~140MB.
- Full detail and file locations for every item above are in the linked docs.

## History note

This repo originally held the raw source material this doc set was built from — a plaintext setup-notes file for Chainedbox, the MikroTik's `.rsc` export, and 4 extracted OpenWrt config backups. All of it was read, cross-checked against live devices, and folded into the 3 docs above (with corrections where live state had drifted from the static snapshots); the raw files have since been removed to avoid keeping two competing sources of truth. The one exception is [configs/chainedbox/](configs/chainedbox/), kept deliberately as an actual config archive (not just prose) since Chainedbox has no single-command config export the way the router and APs do.
