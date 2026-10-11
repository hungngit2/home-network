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
     +----+--------+--------+--------+---------------------------------------+--------+
          |        |        |        |                                       |
       ether1    ether2   ether3   ether4                                  ether5
       Switch     Wifi   (no link) (no link)                        Wyse 5070: VLAN 1 untagged
           |        |                                               + VLAN 10 tagged, one cable
           |        |    +-------------------------------------------------+
           |        |    |  10.0.0.100 / fd39:10::100 (LAN)
           |        |    |  10.0.1.100 / fd39:10:0:1::100 (IoT)
           |        |    |  + Wi-Fi 10.0.0.101 / fd39:10::101 (LAN DNS #2)
           |        |    v
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
      |    "home IoT"    -> vlan10 (IoT, 2.4 GHz only, no PMF)                              |
      |    "home⁺"       -> vlan12 (Guest, PMF optional, clients isolated)                 |
      |    "home-mesh"   -> 802.11s backup backhaul (5GHz, SAE-encrypted)                   |
      |                                                                                      |
      |  usteer (802.11k/v/r roaming & band-steering, LAN + Guest) + local Unbound          |
      |  (each AP's Unbound is a redundant upstream in AdGuard Home's resolver chain)       |
      +----------------------------------------------------------------------+-----------+
                     |                  |                  |                  |
               LAN / IoT / Guest   LAN / IoT / Guest   LAN / IoT / Guest  LAN / IoT / Guest
                  clients              clients              clients          clients
            (laptops, phones,     (NVR, 5x cameras,      (visitor phones,   (wherever a 4th
             TVs, consoles)        inverter, JK-BMS,      laptops, etc)     AP happens to sit)
                                   smart plugs)

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
| 10 | `br-iot` | `10.0.1.0/24` | `fd39:10:0:1::/64` | Cameras, NVR, smart-home, solar inverter | Blocked from LAN at the router; reaches the Wyse 5070 directly on its VLAN 10 address, where the Wyse's own firewall closes admin ports |
| 12 | `br-guest` | `192.168.12.0/24` | `fd39:192:168:12::/64` | Guest Wi-Fi | Blocked from LAN and IoT |
| 99 + 1100 | `br-iptv` (on `sfp1`) | ISP-assigned (DHCP) | — | ISP IPTV: VLAN 99 multicast stream, VLAN 1100 portal/DHCP | IGMP-proxied into `br-lan` only; multicast never leaks back to the ISP |

* **IPv6 NAT66 Architecture**: All internal segments run pure ULA (`fd39:...`) and egress loop-free via MikroTik's NAT66 masquerade engine (anchored on WAN PPPoE `pd-WAN`), completely immune to ISP upstream routing loops.
* **Dual-Stack PCC Load Balancing**: the Per-Connection Classifier currently splits *all* non-private traffic 4:1 between WAN 1 (PPPoE) and WAN 2 (LTE). The original design sent only zero-rated `free-services` (YouTube/Google, TikTok, Meta) to LTE to protect its 5 GB/day allowance; see [MIKROTIK-HEXS.md](MIKROTIK-HEXS.md#multi-wan-routing--policy-routing).
* Wyse 5070 is the primary server, dual-homed onto LAN and IoT over one cable: VLAN 1 untagged plus VLAN 10 tagged on `enp1s0`. It also has a Wi-Fi link into the LAN (`10.0.0.101`, handed out as LAN DNS #2). Guest never touches the server at all.

## Devices at a glance

| Device | Role | Address(es) | Detail doc |
|---|---|---|---|
| **home** (MikroTik RB760iGS "hEX S") | Router: DHCP, DNS forwarding, firewall, dual-stack PCC load balancing for free services, NAT66, FastTrack, WireGuard | `10.0.0.254` (LAN), `fd39:10::254`, `10.0.100.254` / `fd39:10:100::254` (WireGuard-in) | [MIKROTIK-HEXS.md](MIKROTIK-HEXS.md) |
| **wyse5070** (Debian 13, Dell Wyse 5070) | Application server: DNS blocking, Jellyfin (Intel QSV HW transcoding), dashboard, home automation, downloads (auto-movie sorting), bulk storage | `10.0.0.100` / `fd39:10::100` (LAN), `10.0.0.101` / `fd39:10::101` (LAN Wi-Fi), `10.0.1.100` / `fd39:10:0:1::100` (IoT) | [DEBIAN-SERVER.md](DEBIAN-SERVER.md) |
| **chainedbox** (Armbian, RK3328 TV box) | **Retired**: replaced by the Wyse 5070, offline, DHCP reservation (`10.0.0.110`) disabled | — | [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md) |
| **redmi-rm2100-f0** | Wi-Fi AP (mesh node, AP-0) | `10.0.0.200` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f1** | Wi-Fi AP (mesh node, AP-1) | `10.0.0.201` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f2** | Wi-Fi AP (mesh node, AP-2) | `10.0.0.202` | [WIFI-APS.md](WIFI-APS.md) |
| **jcg-q20-f3** | Wi-Fi AP (mesh node, AP-3) | `10.0.0.203` | [WIFI-APS.md](WIFI-APS.md) |

## Documentation

- **[MIKROTIK-HEXS.md](MIKROTIK-HEXS.md)** — router config (plus the applied `tune-mikrotik-hexs.rsc` and open decisions): VLANs, dual-stack Multi-WAN policy routing & PCC load balancing (`free-services`), dual-stack WireGuard, IPv4/IPv6 FastTrack, NAT66 architecture, DNS, IGMP/multicast, scheduler scripts.
- **[DEBIAN-SERVER.md](DEBIAN-SERVER.md)** — Wyse 5070 server: Debian 13, hardware specs, storage layout and flash-wear tuning, three-interface network with policy routing, IoT-facing host firewall, Jellyfin Intel QuickSync (QSV), service inventory, bootstrap and `tune-wyse5070.sh`.
- **[ARMBIAN-SERVER.md](ARMBIAN-SERVER.md)** — (retired box, kept for reference) full service inventory for Chainedbox: storage layout, every service, dual-homed network/VLAN wiring, static IPv4/IPv6 netplan.
- **[WIFI-APS.md](WIFI-APS.md)** — the 4-AP fleet: VLAN/SSID scheme (IoT keeps 802.11r off for the Mi Box 3), 802.11s mesh + STP backup, radios, `usteer`, Unbound, AP firewall, and the wifi-config-tool (including copying Wi-Fi config to a new AP).
- **[configs/chainedbox/](configs/chainedbox/)** — the actual raw config files for Chainedbox / Wyse 5070 (nginx vhost, systemd units, `aria2.conf`, `smb.conf`, `AdGuardHome.yaml`, `avahi-daemon.conf`, Unbound conf.d, `docker/daemon.json`, netplan, crontab, etc.).
- **[scripts/](scripts/)** — provisioning (`setup-debian.sh`, `setup-openwrt-ap.sh`, `setup-openwrt-network.sh`, legacy `setup-chainedbox.sh`) and idempotent live tuning (`tune-wyse5070.sh`, `tune-openwrt-aps.sh`, `tune-mikrotik-hexs.rsc`).
