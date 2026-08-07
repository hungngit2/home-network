# Home — Wi-Fi Access Points (OpenWrt fleet)

**Role**: Wi-Fi — 4 dumb APs meshed together, no DHCP/DNS authority of their own.

## Fleet

| Hostname | Model | Address |
|---|---|---|
| `redmi-rm2100-f0` | Redmi AC2100 | `10.0.0.200` |
| `jcg-q20-f1` | JCG Q20 | `10.0.0.201` |
| `jcg-q20-f2` | JCG Q20 | `10.0.0.202` |
| `jcg-q20-f3` | JCG Q20 | `10.0.0.203` |

## Topology

Each AP carries the same 3 VLANs as the MikroTik (LAN=1, IoT=10, Guest=12), tagged over both the wired uplink and the wireless mesh backhaul (`home-mesh`, 802.11s). A wired device on any AP's LAN port lands on the correct segment automatically.

## Installed / configured

- **SSIDs** (identical on all 4, both 2.4GHz and 5GHz): `home` (LAN), `home IoT` (IoT), `home⁺` (Guest), plus `home-mesh` (mesh backhaul only)
- **usteer** — roaming / band-steering between the 4 APs
- **Unbound** — local recursive resolver on each AP, used as a redundant upstream by AdGuard Home
- **odhcpd** — IPv6 RA/DHCP relay (passive; MikroTik is the DHCPv4/v6 authority)
- **dropbear** (SSH) and **uhttpd** (LuCI web UI) for management
