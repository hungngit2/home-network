# Home — MikroTik hEX S Router

State below was verified with a read-only audit on 2026-10-11. Several live object names embed the site name: the identity, the WireGuard-in interface, the site-to-site peer and the local/remote network address lists. This doc uses generic names (`home`, `wg-home`, `local-network`, `remote-network`) for them.

## Identity & hardware

- **Identity**: `home` (aliased in `~/.ssh/config` as `home` / `hexs`).
- **Model**: RB760iGS "hEX S": 5× GbE + 1 SFP, MT7621A (880 MHz, 2 cores / 4 threads), 256 MB RAM, 16 MB flash.
- **Software**:
  - RouterOS **7.24.4** stable, with 7.24.5 available. RouterBOOT is 7.24.4 (current = upgrade).
  - Installed packages are `routeros` and `wireless`. The board has no radio, so `wireless` only takes flash.
- **Load and health**: CPU 2–8 % per core, about 186 MB RAM free. Flash is nearly full at about 3.4 MB free. 12 V, 54 °C.
- **Timezone**: Asia/Bangkok (`+07:00`).
- **Upgrades**: `/system routerboard settings auto-upgrade=yes` only reflashes RouterBOOT after a RouterOS upgrade, and a second reboot applies it. RouterOS itself is upgraded by hand.
- **Not reproduced here**: the serial number and software ID.

## Network topology

| Bridge | Purpose | Members | Subnet |
|---|---|---|---|
| `br-lan` | Trusted LAN | `ether1` (Switch), `ether2` (Wifi), `ether5` (**Wyse 5070**, `10.0.0.100`); `ether3`/`ether4` are members with no link | `10.0.0.0/24`, gateway `.254`, pool `.1`–`.254` (an unused secondary `192.168.1.254/24` was removed on 2026-10-11) |
| `br-iot` | IoT / cameras / NVR, isolated from LAN | `vlan-e1.10`, `vlan-e2.10`, `vlan-e5.10` (Wyse IoT `10.0.1.100`) | `10.0.1.0/24` |
| `br-guest` | Guest Wi-Fi, isolated from LAN | `vlan-e1.12`, `vlan-e2.12` | `192.168.12.0/24` |
| `br-iptv` | ISP IPTV | `vlan-sfp1.99` (multicast), `vlan-sfp1.1100` (portal / DHCP) | DHCP client (`IPTV`), no default route |

Notes on the ports and bridges:
- **Trunks**: `ether1` and `ether2` carry VLAN 1 untagged plus VLANs 10 and 12 tagged to the switch and APs. `ether5` carries VLAN 1 untagged plus VLAN 10 tagged to the Wyse.
- **IoT gear** (NVR, cameras, inverter) joins VLAN 10 through the trunks; no router port is IoT-only any more.
- **RSTP**: all bridges run it. `br-iot` shares `ether1`'s MAC with `br-lan` and changed its auto-MAC twice at boot. That's harmless, but `admin-mac` would pin it.
- **`sfp1`**: comment "SFP - Hisense LTE3415-SCA+". It carries the PPPoE VLAN (10), the IPTV portal VLAN (1100) and the multicast VLAN (99).

WANs:
- **WAN 1**: PPPoE (`pppoe-out1`, user redacted) over `vlan-sfp1.10`, VNPT. The connection passes through the SFP-attached Hisense LTE3415, which is another modem, not a fiber ONT.
- **WAN 2**: `lte1`, a Telit LN940-A9 (Foxconn T77W676, Cat9) in **MBIM mode** (`SETMODE=1`) on Vinaphone, LTE band 3. It is up and passing traffic.
  - The default APN has `add-default-route=yes default-route-distance=2`.
  - The modem logs harmless "mbim conn_ext bs info BAD_SIM" lines on connect, and dropped once ("wdm send failed") on 2026-10-10.
  - Mode switcher: [`scripts/lte-ln940-mode.sh`](scripts/lte-ln940-mode.sh), or natively: `/interface/lte/at-chat lte1 input="AT^SETMODE=1"`.

## Multi-WAN routing & policy routing

Mangle `prerouting` in both IPv4 and IPv6 marks connections, and routing tables `to-wan1` / `to-wan2` / `to-vpn-out` carry them.

1. **PCC load balancing (live)**:
   - `Load Blancing Mark` matches every new connection to a non-private destination (`dst-address-list=!rfc1918` on IPv4, `!private-ipv6` on IPv6) that isn't headed for the VPN. PCC then splits it into **5 buckets: `5/0`–`5/3` → WAN 1, `5/4` → WAN 2**, i.e. 4:1.
   - The result is that about 20 % of all new internet connections leave over LTE: 2.0 GB down in the 19 h before the audit.
   - **This is not the original design.** That design sent only zero-rated destinations (`dst-address-list=free-services`: YouTube/Google, TikTok, Meta) to LTE, to protect the Vinaphone 5 GB/day allowance. `free-services` is still resolved (47 IPv4 / 37 IPv6 entries), but no rule uses it.
   - To restore the original design, run `set [find comment="Load Blancing Mark"] dst-address-list=free-services` under both `/ip firewall mangle` and `/ipv6 firewall mangle`.
2. **Inbound WAN marks**: `WAN 1/2 - In` tag connections arriving on `pppoe-out1` / `lte1` so that replies leave the same way.
   - They match `new,established,related`.
   - Until 2026-10-11 they matched only `established,related`, so a new inbound connection (the 80/443 port-forwards) stayed unmarked on its first packet. The server's reply then got the load-balancing mark, and 1 in 5 replies left via LTE.
3. **"Unblock Sites"**: an IPv4 `mark-routing` sends `dst-address-list=unblock-sites` (FQDN entries) to `to-vpn-out`.

Routes:
- **`to-wan1`**: Cloudflare `1.1.1.1` / `2606:4700:4700::1111` (`d=1`), then Google (`d=2`), via recursive `check-gateway=ping`. Fallback is `lte1` (`d=10`).
- **`to-wan2`**: Cloudflare `1.0.0.1` / `2606:4700:4700::1001` (`d=1`), then OpenDNS (`d=2`). Fallback is `pppoe-out1` (`d=10`). Google is avoided here so an expired zero-rating quota doesn't fake a healthy check.
- **Main table**: dynamic defaults from the PPPoE client (IPv4 and IPv6, `d=1`) and from `lte1`'s APN (`d=2`).
- **`to-vpn-out`**: `wg-out1` (`9.9.9.9`, `d=1`), with `wg-out2` (`149.112.112.112`, `d=2`) as fallback.

## VPN

- **WireGuard in** (`wg-home`, UDP `13231`) is dual-stack: `10.0.100.254/24`, `fd39:10:100::254/64`. It has four peers:
  - three road-warrior clients (`10.0.100.1`–`.3`, `fd39:10:100::1`–`::3`);
  - a site-to-site peer to the remote OpenWrt gateway (`10.0.100.100`, `10.1.0.0/16`, `fd39:10:100::100`, `fd86:10::/48`, routed via static routes). Its endpoint is set to the remote site's DDNS hostname and the handshake is current; see [Troubleshooting](#troubleshooting).
- **WireGuard out ×2**: `wg-out1` (primary) and `wg-out2` (fallback), used by the "Unblock Sites" rule. Both handshake regularly.

## DNS

- **Servers**: `10.0.0.100` and `10.0.0.101` (the Wyse 5070's AdGuard Home over wired and Wi-Fi, see [DEBIAN-SERVER.md](DEBIAN-SERVER.md)), then the four AP Unbounds `10.0.0.200`–`.203`. There are **no public fallbacks**.
- **`allow-remote-requests=yes`**: the WAN is dropped by the input chain.
- **`cache-max-ttl=0s`**: avoids double-caching behind AdGuard. `address-list-extra-time=12h` keeps the FQDN address lists stable.
- **Split DNS for IPTV**: `*.vmp.tv` is forwarded to the ISP resolvers (`172.16.3.246`/`.247`) for the EPG/portal domain used by `iptv/fetchNewList.php` on the Wyse.
- **`mdns-repeat-ifaces`**: empty. It used to list only `br-iot`, which did nothing. Avahi on the Wyse does the LAN ⇄ IoT reflection.

## DHCP

LAN clients get DNS `10.0.0.100, 10.0.0.101, 10.0.0.254` and NTP `10.0.0.254`. IoT clients get DNS `10.0.1.100, 10.0.1.254`. Guest clients get the router only.

Notable reservations:

| Address | Device |
|---|---|
| `10.0.0.100` | Wyse 5070, wired |
| `10.0.0.101` | Wyse 5070, Wi-Fi |
| `10.0.1.100` | Wyse 5070, VLAN 10 |
| `10.0.0.200`–`.203` | the four APs |
| `10.0.1.3` | Mi Box 3 (Android TV 9, on the IoT SSID) |

The disabled Chainedbox (`10.0.0.110`) and STK1AW32SC (`10.0.1.6`) reservations were removed on 2026-10-11.

## Firewall

**IPv4 input**, in order:
1. accept established/related/untracked;
2. drop invalid;
3. accept ICMP;
4. accept WireGuard-in UDP 13231 to `wan-ip`;
5. accept IGMP and all UDP from `br-iptv`;
6. accept loopback;
7. drop `in-interface-list=vpn-out`, drop `sfp1`, drop TCP from `br-iptv` (UDP 53/123/161/5678 from `br-iptv` is dropped just before the IPTV UDP accept);
8. **drop `in-interface-list=wan`** (`pppoe-out1`, `lte1`).

The step 7 drops were added on 2026-10-11. Before that, `wg-out1/2`, `sfp1` and `br-iptv` could reach the router's DNS, NTP, MNDP and API. IPv6 input was already fine.

**Forward**, in order:
1. FastTrack;
2. accept established/related;
3. drop invalid;
4. IPsec accepts;
5. drop new from WAN unless DSTNATed, and (since 2026-10-11) new from `vpn-out` unless DSTNATed;
6. **Block Remote Site to Local Network** (blanket);
7. **Block IoT to LAN** (`br-iot` → `private` list);
8. **Block Guest to LAN**.

IPv6 has the same forward rules.

Notes on the forward chain:
- The exception rules that let IoT and the remote site reach `local-servers` (DNS, 80/443/5140, ICMP) were deleted via WebFig on 2026-10-10. Six IPv4 and five IPv6 rules were removed.
- IoT still reaches the Wyse directly on its VLAN 10 address, where the Wyse's own firewall closes its admin ports. The remote site can no longer reach the Wyse.
- `local-servers` (IPv4 `10.0.0.100`; IPv6 `fd39:10::100`, `fd39:10::101`) and `dns-internal` remain, but nothing references them.

**Address lists**:

| List | Contents | Used by a rule |
|---|---|---|
| `wan-ip` | Cloud DDNS FQDN | yes |
| `rfc1918` / `private-ipv6` | private ranges | yes |
| `unblock-sites` | FQDN entries | yes |
| `local-network` / `remote-network` | `10.0.0.0/16` + `fd39:10::/48` / `10.1.0.0/16` + `fd86:10::/48` | yes |
| `free-services` | zero-rated destinations | no |
| `local-servers` | see above | no |
| `dns-internal` | internal resolvers | no |
| ~~`vpn-routing-sites`~~ | removed 2026-10-11 (8 disabled entries, its rule had 0 hits) | — |

**FastTrack**: IPv4 FastTrack (the software fast path) handled 8.9M packets / 10.9 GB since boot. The IPv6 rule matched 3.2M packets but fast-tracked none.

**IPv4 NAT**:
- masquerade out `wan` / `vpn-out`, plus out `sfp1` (modem management) and `br-iptv`;
- hairpin NAT for LAN → public name;
- port-forward `80,443` → Wyse `10.0.0.100`;
- disabled toggles: torrent `6889` tcp/udp → Wyse, "Force NTP IoT Local" and an NTP srcnat.

**IPv6 NAT66** (why ULA + NAT66 everywhere):
- **The problem**: VNPT delegates a single `/64` (`IA_PD` only, no `IA_NA` on `pppoe-out1`), and its BNG/OMC routing loops on per-client SLAAC `/128` routes.
- **The fix**: every internal segment uses ULA `fd39:…`, and egress masquerades to one public address:
  - `pd-WAN` binds `from-pool=ipv6-wan1-pool` to `pppoe-out1` (`advertise=no`), giving masquerade a GUA source without advertising public prefixes to clients;
  - outbound: `chain=srcnat action=masquerade src-address=fc00::/7 out-interface-list=wan`;
  - inbound: `80,443` → `fd39:10::100`, accepted by `connection-nat-state=dstnat in-interface-list=wan`.

**Other mangle**: MSS clamp to PMTU on forward SYN, and "LTE - TTL Override" (set TTL 65 on `lte1`, disabled).

## Scheduler & scripts

- **`IGMP-Proxy-Fix`**: enabled, every 4 h. It cycles the IGMP proxy's `br-lan` downstream so stale MFC entries are flushed after IPTV DHCP renewals, which causes a multicast blip of about 1 s. No DHCP-client hook exists.
- **`Weekly-Reboot-Mon-4AM`**: disabled.
- No netwatch entries, no `Check-AGHome` script.

## IGMP / multicast (IPTV)

- **Proxy layout**: `br-iptv` is the upstream (`alternative-subnets=0.0.0.0/0`, `multicast-querier=yes`, `igmp-version=2`), and `br-lan` is the only downstream. `multicast-router=permanent` is set on the LAN switch ports, with `query-interval=60s` / `query-response-interval=5s`.
- **IGMPv2 fix**: RouterOS 7's proxy defaults to IGMPv3 reports (`224.0.0.22`), which VNPT's IGMPv2 BNG ignores. Querier + `igmp-version=2` on `br-iptv` forces IGMPv2 joins to the group address (e.g. `232.84.1.117`).
- **Routes**: the `172.16.0.0/16` IPTV route hard-codes the ISP gateway `10.52.88.1`. If the IPTV DHCP lease ever moves to another gateway, that route breaks.

## Services

| Service | State |
|---|---|
| SSH | enabled, from `10.0.0.0/24` + `10.0.100.0/24` only, key auth (`id_hungnguyen`), `strong-crypto=yes` |
| WWW, Winbox | enabled, LAN + WireGuard-in only |
| API, API-SSL, reverse-proxy, FTP, Telnet, WWW-SSL | disabled (API and reverse-proxy since 2026-10-11; the API was reachable from Guest/IoT before). `admin` has no address restriction. |
| bandwidth-server | disabled |
| NTP | client + server (`local-clock-stratum=10`), unicast only |
| MAC-Winbox / neighbor discovery | limited to the `lan` interface list, which holds only `wg-home`, so effectively off on the LAN |
| detect-internet | off (`detect-interface-list=none`); the `wan` list is static |
| IPv6 ND | per-bridge entries for `br-lan`, `br-iot`, `br-guest`; the default `interface=all` entry is disabled |

## Tuning applied 2026-10-11: `scripts/tune-mikrotik-hexs.rsc`

The script is idempotent and saves `pre-tune.rsc` on the router before changing anything. Re-apply with:

```
scp scripts/tune-mikrotik-hexs.rsc admin@10.0.0.254:
ssh admin@10.0.0.254 '/import file-name=tune-mikrotik-hexs.rsc verbose=yes'
```

| Area | Change |
|---|---|
| Services | API, reverse-proxy and bandwidth-server off; NTP broadcast/multicast/manycast off; `mdns-repeat-ifaces` cleared; detect-internet off |
| WAN In marks | `WAN 1/2 - In` (IPv4 + IPv6) also match `new`, so port-forward replies no longer leak to LTE |
| Input / forward hardening | Drop input from `vpn-out`, `sfp1`, TCP from `br-iptv`, and UDP 53/123/161/5678 from `br-iptv`; drop new forwarded connections from `vpn-out` unless DSTNATed |
| STP | `br-lan` priority `0x1000`: the router is root (the APs report root `1000.<router MAC>`) |
| IPv6 ND | Default `interface=all` ND entry disabled; RAs only on `br-lan`, `br-iot`, `br-guest` |
| Cleanup | Removed the disabled Chainedbox/STK1AW32SC leases, the "Test MSM8916 IP" address, the dangling `lan` list member, the "To VPN" mangle rule and its `vpn-routing-sites` entries, and the empty `to-wan2-f` table |

Verified afterwards:
- IPv4/IPv6 internet and router DNS work.
- The site-to-site and both VPN-out tunnels handshake.
- The IPTV DHCP client is bound and the IPTV accept rule still counts, while the new IPTV drop rules have 0 hits.

## Open decisions

- **PCC scope**: keep balancing all traffic 4:1 onto LTE, or go back to `free-services` only (see [Multi-WAN](#multi-wan-routing--policy-routing)).
- **RouterOS 7.24.5**: upgrade, and `/system package uninstall wireless` to free flash. This needs two reboots (RouterOS, then RouterBOOT) and a WAN/VPN outage.
- **Remote-site access**: restore remote-site access to the Wyse (DNS/HTTP/IPTV), or delete the now-unused `local-servers` / `dns-internal` lists.

## Troubleshooting

### Site-to-site VPN drops after reboot

**Symptom**: the remote router sends WireGuard handshake initiations every ~5 s to this router's WAN IP on port `13231`. The firewall accepts them, but this router never answers (`rx=0 tx=0`, empty `current-endpoint-address`).

**Root cause (2026-08-30, RouterOS 7.24)**: a peer in pure passive/responder mode (empty `endpoint-address`) silently ignored valid initiations after the upgrade.

**Fix (in place since then)**: give the site-to-site peer an endpoint, so this router also initiates. Live, it points at the remote site's DDNS hostname, which survives the remote PPPoE address changing:

```
/interface/wireguard/peers/set <site-to-site peer> endpoint-address=<remote-site-ddns-name> endpoint-port=13231
```

> **Rule of thumb**: for site-to-site WireGuard on MikroTik, configure endpoints on both sides, so either peer can recover a dropped session on its own.
