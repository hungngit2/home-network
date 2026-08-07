# Chainedbox — Armbian Server

**Role**: application server — media, DNS blocking, dashboard, home automation, downloads, file share.

## Identity

- Hardware: RK3328 Android-TV-box ("Chainedbox L1 Pro"), running Armbian
- Storage: `/mnt/appsrv` (app configs + web root), `/mnt/nasdata` (bulk media/downloads/Samba shares)

## Topology

| Interface | VLAN | Subnet | Address |
|---|---|---|---|
| `end0` | 1 (LAN) | `10.0.0.0/24` | `10.0.0.100` |
| `end0.10` | 10 (IoT) | `10.0.1.0/24` | `10.0.1.15` |

No Guest-VLAN interface — Chainedbox is unreachable from Guest Wi-Fi.

## Installed / running

| Service | Role |
|---|---|
| nginx + PHP-FPM | web server — `/ytb` dashboard, `/iptv`, `/kod` (file manager), `/wifi` (AP admin tool), `/jk`, `/ip` |
| AdGuard Home | LAN-wide DNS + ad/tracker blocking |
| Unbound | recursive resolver, upstream of AdGuard Home |
| avahi-daemon | mDNS reflection between LAN and IoT |
| Jellyfin | media server |
| OwnTone | music server (DAAP/MPD/AirPlay), feeds the `/ytb` dashboard |
| rtp2httpd | IPTV multicast→HTTP relay |
| go2rtc | camera streaming (spawned by Home Assistant) |
| Aria2 | download manager → `/mnt/nasdata/downloads` |
| Home Assistant | smart-home hub (Docker, host networking) |
| Samba | file shares (`apps`, `docs`, `downloads`, `media` on `/mnt/nasdata`) |
| Docker | container runtime for Home Assistant (data-root on `/mnt/appsrv/docker`) |

Base OS packages: `unbound`, `aria2`, `nginx`, `php`/`php-fpm`, `samba`, `certbot`, `mosh`.
