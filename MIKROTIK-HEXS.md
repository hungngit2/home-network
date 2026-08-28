# Home — MikroTik hEX S Router

## Identity & hardware

- **System identity**: `lotus` (aliased in `~/.ssh/config` as `home` / `hexs`)
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
| `br-iptv` | ISP multicast IPTV feed | `vlan-sfp1.99`, `vlan-sfp1.1100` (trusted, unicast/multicast flood off) | DHCP client (`IPTV`), no default route |

`ether1` and `ether2` are trunked (carry VLANs 10 and 12 for IoT/Guest respectively, alongside their default untagged LAN membership) — i.e. the same physical switch port to the AP/Wi-Fi gear back-hauls all three segments via VLAN tags. `sfp1` (comment: "SFP - Hisense LTE3415-SCA+") carries the PPPoE VLAN (10), the IPTV VLAN (1100), and a management VLAN (99).

WAN: **dual-WAN with failover/load-balancing**:
- **WAN 1** — PPPoE (`pppoe-out1`, user redacted) over `vlan-sfp1.10`, via VNPT (Vietnamese ISP), through the SFP-connected Hisense LTE3415 (an LTE-to-Ethernet/SFP bridge device, despite the "SFP" naming — it's actually another modem, not a fiber ONT).
- **WAN 2** — the router's built-in LTE modem (`lte1`), carrier Vinaphone, roaming allowed — a cellular failover path.

## Multi-WAN routing & policy routing (the interesting part)

This is the most involved piece of the config — several routing tables plus a mangle chain implement per-connection load balancing across WAN 1/WAN 2, with two carve-outs on top:

1. **Vietnam-vs-rest split (implicit)**: `to-wan1`/`to-wan2` routing tables both default-route out their respective WAN, selected via connection marks (`wan1`/`wan2`) that mangle assigns per-connection using `per-connection-classifier` (an 7:1-ish hash-based split across both WANs — "Connection 1"–"Connection 7" rules). This is standard PCC load-balancing, not literally IP-based.
2. **"Unblock Sites" → forced through VPN-out**: a `mark-routing` rule sends anything matching the `Unblock Sites` address-list (a short hand-picked list — BBC's IP ranges and a Medium.com IP) out `to-vpn-out` instead — i.e. specific geo-blocked sites are forced through the WireGuard tunnels rather than the ISP.
3. **The giant `Vietnam` address-list** (line ~271, hundreds of CIDR blocks covering Vietnamese ISP/hosting IP space) — **defined but not referenced by any live mangle/routing rule** in this export. The two mangle rules that would have used it (`"To Wan 2"`, `"To VPN"`, both `disabled=yes`) are disabled. This looks like leftover infrastructure for a "Vietnam traffic stays on local ISP, everything else via VPN" policy that either hasn't been turned on yet or was superseded by the PCC load-balancing approach — worth clarifying intent before deleting the list, since it's clearly maintained (recently-dated ranges) despite being unused.

Static/recursive routes handle the two check-gateway targets for each WAN (so failover actually triggers on ping loss) and recursive routes for the WireGuard "VPN out" tunnels' endpoint reachability.

## VPN

- **WireGuard inbound** (`vpn-in-lotus`, UDP `13231`, comment "lotus <- VPN") — the router's own remote-access VPN, fully dual-stack:
  - **IPv4 subnet**: `10.0.100.254/24` (peers on `10.0.100.1`–`.3`)
  - **IPv6 subnet**: `fd39:10:100::254/64` (peers on `fd39:10:100::1`–`::3/128`)
  - 3 peers configured (`iPhoneSE2`, `mac16`, `hpEnvy`).
- **WireGuard outbound ×2** — `vpn-out1` ("VPN → SG") and `vpn-out2` ("VPN → HK") — these are the tunnels traffic gets routed into for the geo-unblocking rule above.
- **OpenVPN server** (`ovpn-server1`, UDP, client-cert required) — a second, separate remote-access VPN path alongside WireGuard.
- IPsec is present only as defaults/DPD tuning (`dpd-interval=2m`) — no active IPsec peers configured in this export.

## DNS

- **Upstream servers**: `10.0.0.100` (Chainedbox/AdGuard Home — see [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md)) listed first, then the 4 OpenWrt APs (`10.0.0.200`–`.203`), then public fallbacks (`1.1.1.1`, `1.0.0.1`, `8.8.8.8`, `8.8.4.4`).
- **`cache-max-ttl=1m`** — deliberately tiny, so the router's own cache doesn't paper over AdGuard Home being down (pairs with the scheduler script below).
- **Split DNS for IPTV**: a static DNS rule forwards anything matching `*.vmp.tv` to a dedicated forwarder (`mytv-dns` → `172.16.3.246`/`172.16.3.247`, DoH-cert-check disabled) — routes the ISP's IPTV EPG/portal domain to the ISP's own resolvers instead of the normal upstream chain, matching the `iptv/fetchNewList.php` EPG scraper seen on Chainedbox.
- **A disabled scheduler script** (`Check-AGHome`, currently off) implements automatic DNS failover: every minute, if the router is using Chainedbox as DNS and a test resolve fails, it fails over to the 4 AP IPs as backup DNS; if already on the backup, it tries switching back. Worth knowing this exists even though it's off — flip `disabled=yes`→`no` if AdGuard Home outages become a recurring LAN-DNS problem.

## Firewall

Standard MikroTik default-configuration baseline (established/related/untracked accept, drop-invalid, drop-from-WAN-unless-DSTNATed) plus:

- **IGMP/UDP accepted inbound from `br-iptv`** — lets the ISP's multicast IPTV stream traffic actually reach the router.
- **WireGuard inbound port (`13231`) accepted** from the WAN IP address-list.
- **IoT & Guest isolation**:
  - **IPv4**: IoT can reach Chainedbox (`10.0.0.100`) directly and resolve DNS, but is blocked from LAN (`br-iot`→`private`). Guest is completely blocked from LAN/IoT (`br-guest`→`private`).
  - **IPv6**: Mirrors IPv4 exactly — allows IoT to Chainedbox (`fd39:10::100/128`) and DNS (`:53`), while dropping `br-iot`→`private` and `br-guest`→`private`.
- **FastTrack**: Rule #1 in the `forward` chain on both IPv4 (`FastTrack: IPv4`) and IPv6 (`FastTrack: IPv6`), hardware-accelerating established/related flows and keeping router CPU load at ~10-15%.
- **NAT**: 
  - **IPv4**: Standard masquerade for WAN/VPN-out egress, a hairpin NAT rule for LAN-to-LAN via the public/DDNS name, port-forward `80,443`→Chainedbox (`10.0.0.100`).
  - **IPv6 (NAT66 / Masquerade & `pd-WAN` Anchor)**:
    - **Why ULA + NAT66 instead of Native PD on LAN**: VNPT (ISP) upstream BRAS/BNG and OMC core routing suffer from routing loops and blackholes when handling individual downstream client SLAAC `/128` host routes. NAT66 collapses all outbound traffic into the router's single public GUA identity, completely bypassing VNPT's OMC routing mess while giving internal LAN/IoT/Guest rock-solid static ULA addressing (`fd39:...`).
    - **The `pd-WAN` Anchor Mechanism**: VNPT delegates only a prefix (`IA_PD`), without assigning a WAN GUA address (`IA_NA`) to `pppoe-out1` (link-local only). RouterOS's `action=masquerade` engine requires at least one active GUA bound on the router to use as the public source IP. Binding `from-pool=ipv6-wan1-pool` directly to `pppoe-out1` (`pd-WAN`, `advertise=no`) provides this public anchor address cleanly without broadcasting public RAs to any client VLANs.
    - **Outbound Rule**: `action=masquerade chain=srcnat out-interface-list=wan src-address=!2001::/16`.
    - **Inbound Destination NAT**: Port-forwards `80,443` to Chainedbox (`fd39:10::100/128`), with explicit forward filter acceptance (`connection-nat-state=dstnat in-interface-list=wan`).

## Scheduler & scripts

- **`IGMP-Proxy-Fix`** (every 8h) — disables then re-enables the `br-lan` IGMP-proxy interface. A recurring workaround, which usually means IGMP proxying silently wedges over time on this platform — if IPTV/multicast-to-LAN issues ever come up, this is the known mitigation already in place.
- **`Reboot-Weekly`** — exists but disabled. (Interesting contrast with Chainedbox's *enabled* nightly reboot — the router isn't rebooted on a schedule, only Chainedbox is.)
- **`Check-AGHome`** — see [DNS](#dns) above; exists but disabled.

## IGMP / multicast (IPTV)

- `br-iptv` is the multicast querier/IGMP-proxy upstream (`alternative-subnets=0.0.0.0/0`), `br-lan` is a downstream proxy interface — this is what lets IPTV multicast groups actually traverse from the ISP feed to LAN clients (and ultimately to rtp2httpd on Chainedbox, per the [Armbian doc](ARMBIAN-SERVER.md#iptv-stack)).
- A bridge filter explicitly **drops multicast forwarded out `br-iptv`** (prevents LAN-sourced multicast leaking upstream to the ISP) while still accepting mDNS (`224.0.0.251`) and SSDP (`239.255.255.250`) forwarding elsewhere — so local service discovery (Chromecast, etc.) still works across the LAN/other bridges.

## Services

| Service | State |
|---|---|
| SSH | **enabled** (key-based auth configured with `id_ed25519`, aliased in `~/.ssh/config` as `home` / `hexs`) |
| FTP, Telnet, API, API-SSL | **disabled** |
| WWW (HTTP config UI), Winbox | enabled, restricted to `10.0.0.0/24` + `10.0.100.0/24` (LAN + WireGuard-in) |
| SMB (file sharing on the router itself) | disabled |
| NTP client | enabled; NTP server also enabled (broadcast/multicast, `local-clock-stratum=10`) — the router serves time to the LAN in addition to syncing itself against Cloudflare/`vn.pool.ntp.org`/`asia.pool.ntp.org` |
| `/tool sniffer` | stopped / default (no active filters) |
| Kid Control | disabled / no active profiles |
