# Home Network

A 3-tier home network: a MikroTik router (`home`) doing dual-stack routing/firewall/VPN/DHCP with PCC load-balancing for free services, a fleet of 4 wired OpenWrt access points (with 802.11s mesh backup) for Wi-Fi, and a Dell Wyse 5070 application server running the household's services (DNS blocking, Jellyfin with Intel QSV hardware transcoding, dashboard, home automation, downloads with automatic movie sorting).

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
     |          RB760iGS · RouterOS · Router / Firewall / Dual-Stack PCC / VPN       |
     |---------------------------------------------------------------------------------|
     |   br-lan (vlan1)     br-iot (vlan10)     br-guest (vlan12)     br-iptv          |
     |   10.0.0.0/24        10.0.1.0/24         192.168.12.0/24       IGMP-proxied     |
     +----+--------+--------+----+------------------+------------------+---------------+
          |        |        |    |                  |
       ether1    ether2   ether3 ether4           ether5
       Switch     Wifi   Wyse 5070 (spare)       "IoT - NVR"
           |        |    10.0.0.100/                 |
           |        |    fd39:10::100/               +--> NVR-Home, 5x IP cameras,
           |        |    10.0.1.6                         Lumentree 4kW inverter,
           |        |         |                            JK-BMS monitor, misc IoT
           |        |         v
           |        |    +-------------------------------------------------------------+
           |        |    |          Wyse 5070  (Debian 13, Intel Pentium J5005)         |
           |        |    |   /appsrv (64G SSD, configs+data) · /mnt/nasdata (687G)     |
           |        |    |-------------------------------------------------------------|
           |        |    | nginx+PHP-FPM  -> /ytb dashboard, /iptv, /wifi-config-tool  |
           |        |    | AdGuard Home -> Unbound -> upstreams                        |
           |        |    | avahi-daemon  -- mDNS reflect: LAN <-> IoT                  |
           |        |    | Jellyfin  -- media library (Intel QSV HW transcoding)       |
           |        |    | OwnTone -- music/DAAP/AirPlay                               |
           |        |    | rtp2httpd -- IPTV relay (IGMP multicasting)                 |
           |        |    | Aria2 -- downloads -> auto-sorted /nasdata/media/movies     |
           |        |    | Home Assistant (Docker, host-net) -- smart home             |
           |        |    | Samba (smbd/nmbd) -- apps/docs/downloads/media shares       |
           |        |    +-------------------------------------------------------------+
           |        |
           +--------+----------------- trunk (vlan1 + vlan10 + vlan12) ----------------+
                                                                                        |
                                                                                        v
      +----------------------------------------------------------------------------------+
      |             4x OpenWrt dumb APs (wired trunk backhaul, 802.11s mesh backup)      |
      |----------------------------------------------------------------------------------|
      |  redmi-rm2100-f0    jcg-q20-f1        jcg-q20-f2        jcg-q20-f3                  |
      |  10.0.0.200         10.0.0.201        10.0.0.202        10.0.0.203                  |
      |  (AP-0)             (AP-1)            (AP-2)            (AP-3)                      |
      |                                                                                      |
      |  SSIDs broadcast identically by all 4:                                              |
      |    "home"       -> vlan1  (LAN)                                                     |
      |    "home IoT"    -> vlan10 (IoT, no PMF)                                            |
      |    "home⁺"       -> vlan12 (Guest, PMF required)                                    |
      |    "home-mesh"   -> 802.11s backup backhaul (5GHz, SAE-encrypted)                   |
      |                                                                                      |
      |  usteer (optimized 802.11v/k/r roaming & band-steering) + local Unbound            |
      |  (each AP's Unbound is a redundant upstream in AdGuard Home's resolver chain)       |
      +----------------------------------------------------------------------+-----------+
                     |                  |                  |                  |
               LAN / IoT / Guest   LAN / IoT / Guest   LAN / IoT / Guest  LAN / IoT / Guest
                  clients              clients              clients          clients
            (laptops, phones,     (cameras, sensors,    (visitor phones,   (wherever a 4th
             TVs, consoles)        smart plugs, etc)      laptops, etc)     AP happens to sit)

      WireGuard-in (UDP :13231, dual-stack): 10.0.100.254/24, fd39:10:100::254/64
      WireGuard-out x2 (from the MikroTik, always-on): --> VPN-SG
                                                         --> VPN-HK
      Used for a small hand-picked "Unblock Sites" list (BBC, Medium) forced through the tunnel
      instead of the local ISP path.
```

## VLANs & Subnets

| VLAN | Bridge (router) | IPv4 Subnet | IPv6 ULA Subnet | Purpose | Isolation |
|---|---|---|---|---|---|
| 1 | `br-lan` | `10.0.0.0/24` | `fd39:10:0::/64` | Trusted LAN — Wyse 5070, laptops, TVs | Full access |
| 10 | `br-iot` | `10.0.1.0/24` | `fd39:10:1::/64` | Cameras, NVR, smart-home, solar inverter | Can reach Wyse 5070 only; blocked from LAN |
| 12 | `br-guest` | `192.168.12.0/24` | `fd39:192:168:12::/64` | Guest Wi-Fi | Blocked from LAN and IoT |
| — | `br-iptv` | ISP-assigned (DHCP) | — | ISP multicast IPTV feed | IGMP-proxied into `br-lan`/`br-iot` only; multicast never leaks back to the ISP |
| 99 | (management, on `sfp1`) | — | — | ISP-side management VLAN | — |

* **IPv6 NAT66 Architecture**: All internal segments run pure ULA (`fd39:...`) and egress loop-free via MikroTik's NAT66 masquerade engine (anchored on WAN PPPoE `pd-WAN`), completely immune to ISP upstream routing loops.
* **Dual-Stack PCC Load Balancing**: Per-Connection Classifier routes unlimited zero-rated traffic (`free-services`: YouTube/Google, TikTok, Facebook/Meta) across both WAN 1 and WAN 2, while preserving WAN 1 exclusively for all other internet traffic.
* Wyse 5070 is the primary server dual-homed onto both segments (LAN + IoT). Guest never touches the server at all.

## Devices at a glance

| Device | Role | Address(es) | Detail doc |
|---|---|---|---|
| **home** (MikroTik RB760iGS "hEX S") | Router: DHCP, DNS forwarding, firewall, dual-stack PCC load balancing for free services, NAT66, FastTrack, WireGuard | `10.0.0.254` (LAN), `fd39:10::254`, `10.0.100.254` / `fd39:10:100::254` (WireGuard-in) | [MIKROTIK-HEXS.md](MIKROTIK-HEXS.md) |
| **wyse5070** (Debian 13, Dell Wyse 5070) | Application server: DNS blocking, Jellyfin (Intel QSV HW transcoding), dashboard, home automation, downloads (auto-movie sorting), bulk storage | `10.0.0.100` / `fd39:10::100` (LAN), `10.0.1.6` (IoT) | [DEBIAN-SERVER.md](DEBIAN-SERVER.md) |
| **chainedbox** (Armbian, RK3328 TV box) | Application server (legacy/backup): DNS blocking, media, dashboard, downloads | `10.0.0.101` / `fd39:10::101` (LAN), `10.0.1.15` (IoT) | [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md) |
| **redmi-rm2100-f0** | Wi-Fi AP (mesh node, AP-0) | `10.0.0.200` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f1** | Wi-Fi AP (mesh node, AP-1) | `10.0.0.201` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f2** | Wi-Fi AP (mesh node, AP-2) | `10.0.0.202` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f3** | Wi-Fi AP (mesh node, AP-3) | `10.0.0.203` | [WIFI-APS.md](WIFI-APS.md) |

## Documentation

- **[MIKROTIK-HEXS.md](MIKROTIK-HEXS.md)** — router config: VLANs, dual-stack Multi-WAN policy routing & PCC load balancing (`free-services`), dual-stack WireGuard, IPv4/IPv6 FastTrack, NAT66 architecture, DNS, IGMP/multicast, scheduler scripts.
- **[DEBIAN-SERVER.md](DEBIAN-SERVER.md)** — Wyse 5070 server: Debian 13, hardware specs, storage layout (`/appsrv`, persistent `/mnt/nasdata` automount, USB power management), Jellyfin Intel QuickSync (QSV) hardware acceleration, Aria2 automated movie sorting, systemd services, automated bootstrap.
- **[ARMBIAN-SERVER.md](ARMBIAN-SERVER.md)** — full service inventory for Chainedbox: storage layout, every service, dual-homed network/VLAN wiring, static IPv4/IPv6 netplan.
- **[WIFI-APS.md](WIFI-APS.md)** — the 4-AP fleet: VLAN/SSID scheme, 802.11s mesh backhaul, optimized `usteer` 802.11v/k/r roaming parameters, and Unbound resolvers.
- **[configs/chainedbox/](configs/chainedbox/)** — the actual raw config files for Chainedbox / Wyse 5070 (nginx vhost, systemd units, `aria2.conf`, `smb.conf`, `AdGuardHome.yaml`, `avahi-daemon.conf`, Unbound conf.d, `docker/daemon.json`, netplan, crontab, etc.).
