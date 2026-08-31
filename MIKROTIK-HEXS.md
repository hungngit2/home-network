# Home — MikroTik hEX S Router

## Identity & hardware

- **System identity**: `home` (aliased in `~/.ssh/config` as `home` / `hexs`)
- **Model**: RB760iGS ("hEX S") — MikroTik's 5-port Gigabit router with one SFP cage, running RouterOS v7.24 (MediaTek MT7621A, MMIPS 1004Kc 880MHz dual-core / 4-threads, 256MB RAM).
- **Serial number / Software ID**: present in the export header — not reproduced here; treat them like the credentials elsewhere in this repo (they identify/license this specific unit).
- **Timezone**: Asia/Bangkok (`+07:00`).
- **Auto-upgrade**: `/system routerboard settings` has `auto-upgrade=yes` — RouterOS updates itself and just needs a reboot to apply.

## Network topology

Three physical LAN segments plus a VPN-delivered "LAN" segment, layered over 5 Ethernet ports, 1 SFP, 1 LTE modem, and a PPPoE WAN:

| Bridge | Purpose | Members | Subnet |
|---|---|---|---|
| `br-lan` | Trusted home LAN | `ether1` (Switch), `ether2` (Wifi), `ether3` (**Chainedbox**, `10.0.0.100`), `ether4` | `10.0.0.0/24` (`.1`–`.210` pool, `.254` gateway) |
| `br-iot` | IoT / cameras / NVR — isolated from LAN | `ether5` ("IoT - NVR"), `vlan-e1.10`, `vlan-e2.10`, `vlan-e3.10` | `10.0.1.0/24` |
| `br-guest` | Guest Wi-Fi — isolated from LAN | `vlan-e1.12`, `vlan-e2.12` | `192.168.12.0/24` |
| `br-iptv` | ISP IPTV bridge | `vlan-sfp1.99` (Multicast / IGMP), `vlan-sfp1.1100` (Portal Web / DHCP) | DHCP client (`IPTV`), no default route |

`ether1` and `ether2` are trunked (carry VLANs 10 and 12 for IoT/Guest respectively, alongside their default untagged LAN membership) — i.e. the same physical switch port to the AP/Wi-Fi gear back-hauls all three segments via VLAN tags. `sfp1` (comment: "SFP - Hisense LTE3415-SCA+") carries the PPPoE VLAN (10), the IPTV Portal VLAN (1100), and the Multicast VLAN (99), bridged together on `br-iptv` to feed the IGMP Proxy and DHCP client untagged frames.

WAN: **dual-WAN with failover/load-balancing**:
- **WAN 1** — PPPoE (`pppoe-out1`, user redacted) over `vlan-sfp1.10`, via VNPT (Vietnamese ISP), through the SFP-connected Hisense LTE3415 (an LTE-to-Ethernet/SFP bridge device, despite the "SFP" naming — it's actually another modem, not a fiber ONT).
- **WAN 2** — the router's built-in LTE modem (`lte1`), carrier Vinaphone, roaming allowed — a cellular failover path.

## Multi-WAN routing & policy routing (the interesting part)

This is the most involved piece of the config — several routing tables plus a mangle chain implement per-connection load balancing across WAN 1/WAN 2, with two carve-outs on top:

1. **Vietnam-vs-rest split (implicit)**: `to-wan1`/`to-wan2` routing tables both default-route out their respective WAN, selected via connection marks (`wan1`/`wan2`) that mangle assigns per-connection using `per-connection-classifier` (an 7:1-ish hash-based split across both WANs — "Connection 1"–"Connection 7" rules). This is standard PCC load-balancing, not literally IP-based.
2. **"Unblock Sites" → forced through VPN-out**: a `mark-routing` rule sends anything matching the `Unblock Sites` address-list (a short hand-picked list of geo-blocked services) out `to-vpn-out` instead — i.e. specific geo-blocked sites are forced through the WireGuard tunnels rather than the ISP. Unused legacy GeoIP static address lists have been purged to keep config lightweight and conserve flash memory.

Static/recursive routes handle the check-gateway targets for each WAN (so failover actually triggers on ping loss) and recursive primary + fallback routes (`distance=1` primary, `distance=2` fallback with `check-gateway=ping`) for the WireGuard "VPN out" tunnels.

## VPN

- **WireGuard inbound** (`wg-home`, UDP `13231`, comment "home <- VPN") — the router's own remote-access & site-to-site VPN, fully dual-stack:
  - **IPv4 subnet**: `10.0.100.254/24` (road-warrior client peers on `10.0.100.1`–`.3`, site-to-site peer on `10.0.100.100`)
  - **IPv6 subnet**: `fd39:10:100::254/64` (road-warrior client peers on `fd39:10:100::1`–`::3/128`, site-to-site peer on `fd39:10:100::100/128`)
  - Peers configured:
    - **Remote client peers**: Road-warrior mobile & laptop devices (`10.0.100.1`–`.3/32`, `fd39:10:100::1`–`::3/128`)
    - **Site-to-site peer** (`site1-to-site2`): Remote site (Site 2) OpenWrt gateway (`10.0.100.100/32`, `10.1.0.0/16` [LAN + WG], `fd39:10:100::100/128`, `fd86:10::/48` [ULA], routed via static routes on `wg-home`)
      - ⚠️ **Must set `endpoint-address=<site2-wan-ip> endpoint-port=13231`** on this peer (Site 2's current WAN IP). See [Troubleshooting](#troubleshooting).
- **WireGuard outbound ×2** — `wg-vpn-out1` (Primary, `distance=1`) and `wg-vpn-out2` (Fallback, `distance=2`) — these are the tunnels traffic gets routed into for the geo-unblocking rule above, configured with recursive ping health checks.

## DNS

- **Upstream servers**: `10.0.0.100` (Chainedbox/AdGuard Home — see [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md)) listed first, then the 4 OpenWrt APs (`10.0.0.200`–`.203`), then public fallbacks (`1.1.1.1`, `1.0.0.1`, `8.8.8.8`, `8.8.4.4`).
- **`cache-max-ttl=1m`** — deliberately tiny, so the router's own cache doesn't paper over AdGuard Home being down (pairs with the scheduler script below).
- **Split DNS for IPTV**: a static DNS rule forwards anything matching `*.vmp.tv` to a dedicated forwarder (`mytv-dns` → `172.16.3.246`/`172.16.3.247`, DoH-cert-check disabled) — routes the ISP's IPTV EPG/portal domain to the ISP's own resolvers instead of the normal upstream chain, matching the `iptv/fetchNewList.php` EPG scraper seen on Chainedbox.
- **A disabled scheduler script** (`Check-AGHome`, currently off) implements automatic DNS failover: every minute, if the router is using Chainedbox as DNS and a test resolve fails, it fails over to the 4 AP IPs as backup DNS; if already on the backup, it tries switching back. Worth knowing this exists even though it's off — flip `disabled=yes`→`no` if AdGuard Home outages become a recurring LAN-DNS problem.

## Firewall

Standard MikroTik default-configuration baseline (established/related/untracked accept, drop-invalid, drop-from-WAN-unless-DSTNATed) plus:

- **IGMP/UDP accepted inbound from `br-iptv`** — lets the ISP's multicast IPTV stream traffic actually reach the router.
- **Address Lists for Subnets & Hosts**:
  - **`home-servers`**: Application servers permitted for inter-site & IoT access (`10.0.0.100`, `10.0.0.101` on IPv4; `fd39:10::100/128`, `fd39:10::101/128` on IPv6).
  - **`sala-lan`**: Remote site network (`10.1.0.0/16` on IPv4, `fd86:10::/48` on IPv6).
  - **`home-lan`**: Local site umbrella (`10.0.0.0/16` on IPv4, `fd39:10::/48` on IPv6).
  - **`wan-ip`**: Dynamic Cloud DDNS FQDN (`f94b0f9e4d9f.sn.mynetname.net` on IPv4 & IPv6).
  - **`rfc1918`**: RFC 1918 private IPv4 subnets (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).
  - **`dns-internal`**: Internal DNS forwarders (`10.0.0.100`, `10.0.0.200`–`203`).
  - **`unblock-sites`**: Domain/IP bypass list for selective VPN routing.
- **IoT & Guest isolation**:
  - **IPv4**: IoT can reach `home-servers` directly and resolve DNS, but is blocked from LAN (`br-iot`→`private`). Guest is completely blocked from LAN/IoT (`br-guest`→`private`).
  - **IPv6**: Mirrors IPv4 exactly — allows IoT to `home-servers` and DNS (`:53`), while dropping `br-iot`→`private` and `br-guest`→`private`.
- **Site-to-Site Access Control (`sala-lan` $\rightarrow$ Home)**:
  - **IPv4**: `sala-lan` is blocked from accessing `home-lan`, with explicit exceptions only for `home-servers`: DNS UDP (`:53`), Combined TCP (`:53, :80, :443, :5140`), and ICMP.
  - **IPv6**: `sala-lan` is blocked from accessing `home-lan`, with explicit exceptions only for `home-servers`: DNS UDP (`:53`), Combined TCP (`:53, :80, :443, :5140`), and ICMPv6.
- **FastTrack**: Rule #1 in the `forward` chain on both IPv4 (`FastTrack: IPv4`) and IPv6 (`FastTrack: IPv6`), hardware-accelerating established/related flows and keeping router CPU load at ~10-15%.
- **NAT**: 
  - **IPv4**: Standard masquerade for WAN/VPN-out egress, a hairpin NAT rule for LAN-to-LAN via the public/DDNS name, port-forward `80,443`→Chainedbox (`10.0.0.100`).
  - **IPv6 (NAT66 / Masquerade & `pd-WAN` Anchor)**:
    - **Why ULA + NAT66 across all segments (LAN, IoT, Guest, VPN)**: VNPT (ISP) only delegates a single `/64` prefix (insufficient for native multi-VLAN segmentation) and their upstream BRAS/BNG and OMC core routing suffer from routing loops and blackholes when handling individual downstream client SLAAC `/128` host routes. NAT66 collapses all outbound traffic from all segments into the router's single public GUA identity, completely bypassing VNPT's OMC routing mess while giving all internal VLANs and remote WireGuard clients rock-solid static ULA addressing (`fd39:...`).
    - **The `pd-WAN` Anchor Mechanism**: VNPT delegates only a prefix (`IA_PD`), without assigning a WAN GUA address (`IA_NA`) to `pppoe-out1` (link-local only). RouterOS's `action=masquerade` engine requires at least one active GUA bound on the router to use as the public source IP. Binding `from-pool=ipv6-wan1-pool` directly to `pppoe-out1` (`pd-WAN`, `advertise=no`) provides this public anchor address cleanly without broadcasting public RAs to any client VLANs.
    - **Outbound Rule**: `action=masquerade chain=srcnat out-interface-list=wan src-address=fc00::/7 comment="ULA -> Internet"`.
    - **Inbound Destination NAT**: Port-forwards `80,443` to Chainedbox (`fd39:10::100/128`), with explicit forward filter acceptance (`connection-nat-state=dstnat in-interface-list=wan`).

## Scheduler & scripts

- **`IPTV DHCP Client Hook`** — **active**: Event-driven script attached directly to `/ip dhcp-client` for `br-iptv`. Whenever the dynamic IPTV lease binds or renews (`$bound=1`), it automatically cycles `br-lan` in `/routing igmp-proxy` so the kernel MFC table is instantly refreshed with valid upstream sockets.
- **`IGMP-Proxy-Fix`** — **disabled** (superseded by the event-driven IPTV DHCP client hook above).
- **`Reboot-Weekly`** — exists but disabled. (Interesting contrast with Chainedbox's *enabled* nightly reboot — the router isn't rebooted on a schedule, only Chainedbox is.)
- **`Check-AGHome`** — see [DNS](#dns) above; exists but disabled.

## IGMP / multicast (IPTV)

- **Architecture**: `br-iptv` is the upstream bridge (`alternative-subnets=0.0.0.0/0`, `multicast-querier=yes`, `igmp-version=2`), and `br-lan` is the downstream proxy interface with `multicast-router=permanent` on LAN switch ports (`ether1`–`ether4`) and active `query-interval=60s` / `query-response-interval=5s`.
- **The Upstream IGMPv2 Protocol Fix**: RouterOS 7's IGMP Proxy defaults to IGMPv3 (`224.0.0.22`), which VNPT's IGMPv2 multicast BNGs ignore. Enabling `multicast-querier=yes` and `igmp-version=2` on `br-iptv` forces RouterOS's IGMP proxy into **IGMPv2 compatibility mode**, ensuring all upstream joins are transmitted as native IGMPv2 reports directly to the channel multicast IP (e.g. `232.84.1.117`), which triggers the ISP stream immediately.
- **Automated Socket Recovery**: The event-driven DHCP client hook on `br-iptv` automatically cycles `br-lan` to purge stale kernel MFC table entries (`upstream-interface=*FFFFFFFF`) across dynamic DHCP renewals without relying on polling schedulers.

## Services

| Service | State |
|---|---|
| SSH | **enabled** (key-based auth `yes-if-no-key` with `id_hungnguyen`, `strong-crypto=yes`, restricted to `10.0.0.0/24` + `10.0.100.0/24`, aliased in `~/.ssh/config` as `home` / `hexs`) |
| FTP, Telnet, API, API-SSL | **disabled** |
| WWW (HTTP config UI), Winbox | enabled, restricted to `10.0.0.0/24` + `10.0.100.0/24` (LAN + WireGuard-in) |
| SMB (file sharing on the router itself) | disabled |
| NTP client | enabled; NTP server also enabled (broadcast/multicast, `local-clock-stratum=10`) — the router serves time to the LAN in addition to syncing itself against Cloudflare/`vn.pool.ntp.org`/`asia.pool.ntp.org` |
| `/tool sniffer` | stopped / default (no active filters) |
| Kid Control | disabled / no active profiles |

## Troubleshooting

### Site-to-Site VPN drops after reboot

**Symptom**: The remote site router sends WireGuard handshake initiations every ~5s to this router's WAN IP on port `13231`. The IPv4 firewall rule accepts the packets. But this router's WireGuard sends **zero responses** — `rx=0 tx=0`, `current-endpoint-address=""` forever.

**Root cause (2026-08-30)**: After upgrading to **RouterOS 7.24**, WireGuard in pure passive/responder mode (peer with `endpoint-address=""`) silently drops valid handshake initiations without responding. This was not observed on earlier versions — likely a regression introduced in 7.24, or a RouterOS design limitation that was masked by a warm session surviving the previous reboot.

**Fix**: Set `endpoint-address` and `endpoint-port` on the site-to-site peer so this router also acts as an initiator (bidirectional):

```
# Get Site 2's current WAN IP first (run on Site 2 router):
#   ip address show dev pppoe-internet | grep 'inet '

/interface/wireguard/peers/set [find name=site2-to-home] \
  endpoint-address=<site2-wan-ip> endpoint-port=13231
```

> **Rule of thumb**: For site-to-site WireGuard on MikroTik, **always configure endpoints on both sides**. This lets either peer recover from a dropped session independently and avoids the passive-responder issue entirely.
>
> Note: Site 2's WAN IP is assigned dynamically by the ISP (PPPoE). If it changes after a reconnect, update the endpoint accordingly — or set up a dynamic DNS hostname for Site 2 and use that instead.
