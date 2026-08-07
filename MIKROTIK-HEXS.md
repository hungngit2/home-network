# Home — MikroTik hEX S Router

**Role**: core router — DHCP, DNS forwarding, firewall, dual-WAN, VPN.

## Identity

- System identity: `home`
- Model: RB760iGS ("hEX S")
- Timezone: Asia/Bangkok

## Topology

| Bridge | VLAN | Subnet | Ports |
|---|---|---|---|
| `br-lan` | 1 | `10.0.0.0/24` | `ether1` (Switch), `ether2` (Wifi), `ether3` (Chainedbox), `ether4` |
| `br-iot` | 10 | `10.0.1.0/24` | `ether5` (IoT/NVR), tagged on `ether1`/`ether2`/`ether3` |
| `br-guest` | 12 | `192.168.12.0/24` | tagged on `ether1`/`ether2` |
| `br-iptv` | — | ISP-assigned | tagged on `sfp1` |

WAN:
- **WAN 1** — PPPoE over `sfp1` (VNPT, via a Hisense LTE3415-SCA+ bridge modem)
- **WAN 2** — built-in LTE modem (`lte1`, Vinaphone)

Both WANs are load-balanced across connections (PCC), with automatic failover.

## Installed / configured

- **VPN**: WireGuard inbound (remote-access, 3 peers), WireGuard outbound ×2 (routes a small allowlist of sites through instead of the local ISP path), OpenVPN server (remote-access, cert auth)
- **DNS**: forwards to Chainedbox (AdGuard Home) first, then the 4 APs, then public resolvers; one domain-specific override for the ISP's IPTV portal
- **Firewall**: default-deny from WAN, IoT/Guest isolated from LAN, Chainedbox reachable from IoT, IGMP/multicast allowed in from the IPTV VLAN
- **IGMP proxy**: bridges ISP multicast IPTV from `br-iptv` into `br-lan`
- **Services**: Winbox + HTTP admin UI (LAN-only), NTP client + server; SSH/FTP/Telnet/API/SMB all disabled
- **Scheduler scripts**: IPv6 DNAT auto-update (keeps a port-forward valid across ISP prefix changes), periodic IGMP-proxy restart, a disabled DNS-failover script, a disabled weekly-reboot script
