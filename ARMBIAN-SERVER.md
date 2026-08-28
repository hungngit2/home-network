# Chainedbox — Armbian Server

## Hardware & OS

- **Board**: "Chainedbox L1 Pro" — an RK3328 (Rockchip) Android-TV-box-style device (`BOARD_NAME=RK.Chainedbox`, `BOARDFAMILY=rk3328`, `LINUXFAMILY=rockchip`), repurposed to run Armbian. This is the origin of the box's name.
- **OS**: Armbian 26.5.1 "noble" (Ubuntu 24.04 LTS base), kernel `6.1.159-ophub` (aarch64). The `-ophub` kernel suffix is the tell: this isn't stock Armbian but a build from **[ophub/amlogic-s9xxx-armbian](https://github.com/ophub/amlogic-s9xxx-armbian)** — a third-party rebuild project that produces Armbian images for TV-box SoCs (Amlogic S9xxx, and by extension other boxes like this RK3328) that upstream Armbian doesn't officially support.
- **Resources**: ~971 MiB RAM, 485 MiB swap.
- **Network**: LAN-only at `10.0.0.100`, aliased as `chainedbox` in `~/.ssh/config`. No public IP/hostname; external access (if any) goes through the router/DDNS (`REDACTED-domain`, referenced in the nginx TLS cert).

## Storage

Two disks, mounted by filesystem label (not UUID) so they survive disk reordering:

```
LABEL=appsrv  → /mnt/appsrv   (59G, /dev/sdb1) — app configs, web root, service data
LABEL=nasdata → /mnt/nasdata  (687G, /dev/sda1) — bulk storage: media, downloads, Samba shares
```

Root filesystem is the onboard eMMC (`/dev/mmcblk0p2`, 6.6G, 65% used) — small, so app data intentionally lives on `/mnt/appsrv`, not the rootfs.

> **Discrepancy from source notes**: the original notes describe app configs (nginx, AdGuard Home, aria2) as living under `/mnt/nasdata/appsrv/...`. On the live box, they're actually symlinked from **`/mnt/appsrv/...`** (confirmed for nginx, AdGuard Home, aria2, Jellyfin). `nasdata` is used for bulk media/download storage and Samba shares, not service config. The doc below reflects the live paths.

### `/mnt/appsrv` — full contents

```
/mnt/appsrv/
├── adguard-home/   AdGuard Home config + data (see DNS section)
├── aria2/          Aria2 config + session state (see Downloads section)
├── docker/         Docker's data-root itself (dockerd's storage, not just one app's config — see below)
├── jellyfin/       Jellyfin config/cache/log/tmp/web (see Jellyfin section)
├── nasdata/        (empty — legacy mount-point leftover, not the nasdata disk itself)
├── nginx/          nginx vhost config + access/error logs (see nginx section)
├── samba/          smb.conf, symlinked live at /etc/samba/smb.conf
├── www/            nginx web root (see Web root section)
└── ytb-owntone/    YTB dashboard ⇄ OwnTone integration state (queue, pipes, cache — see below)
```

Per-directory detail not covered elsewhere:

- **`docker/`** — this *is* Docker's `data-root`, redirected off the small eMMC rootfs via `/etc/docker/daemon.json` (`"data-root": "/mnt/appsrv/docker"`, plus a custom bridge subnet `172.31.0.1/24` and China-based registry mirrors — `mirror.baidubce.com`, `hub-mirror.c.163.com` — probably left over from pulling images from a network where Docker Hub is slow/blocked). `/opt/docker` is a symlink to this same directory, which is how Home Assistant's compose-style bind mounts (`/opt/docker/homeassistant/config`, `/opt/docker/homeassistant/media`) resolve here too.
- **`samba/smb.conf`** — the real Samba config; `/etc/samba/smb.conf` is just a symlink to it (same pattern as nginx/AdGuard Home/aria2 — config kept on `appsrv` so it survives an OS reflash).
- **`ytb-owntone/`** — runtime state for the dashboard/OwnTone integration: `pipes/youtube.fifo` (a named pipe OwnTone reads as an audio source — likely fed by a `yt-dlp`-style downloader driven by `backend.php`/`queue-daemon.php`), `cache/` (downloaded audio, e.g. `dUkGrSPbSOE.audio`), and `data/` (`queue_state.json`, `playlist.json`, `confirmed_playing.json`, `resolved_stream.json`, `last_search.json`, `playback.lock` — the lock file the nginx `fastcgi_ignore_client_abort` comment refers to). This is the actual bridge between the web dashboard and OwnTone's playback.

## Base packages

```bash
apt update && apt upgrade -y && apt install -y unbound unbound-anchor aria2 nginx php php-fpm php-gd php-curl php-mbstring build-essential devscripts fakeroot debhelper samba certbot python3-certbot-nginx mosh
```

Non-apt services ([AdGuard Home](https://github.com/AdguardTeam/AdGuardHome), [Jellyfin](https://jellyfin.org/), [rtp2httpd](https://github.com/hungngit2/rtp2httpd), [OwnTone](https://github.com/hungngit2/owntone-server), Docker Engine, Home Assistant) are each installed via their own curl-pipe-to-shell script or as a Docker container — none of them get picked up by `apt upgrade`, so re-run each project's own install/update script to update.

## Network & VLANs

Chainedbox is dual-homed, matching exactly 2 of the 4 VLANs defined on [the MikroTik](MIKROTIK-HEXS.md#network-topology) and replicated across [the AP fleet](WIFI-APS.md#how-they-plug-into-the-routers-vlan-scheme):

| Interface | VLAN | Subnet | Address | Default route metric |
|---|---|---|---|---|
| `end0` | 1 (LAN) | `10.0.0.0/24`<br>`fd39:10::/64` | `10.0.0.100` (DHCP reservation)<br>`fd39:10::100/64` (fixed static + token `::100`) | 100 (preferred) |
| `end0.10` | 10 (IoT) | `10.0.1.0/24` | `10.0.1.15` (DHCP from MikroTik) | 400 (fallback) |

**No Guest (VLAN 12) interface exists on this box** — deliberate, since Guest is meant to stay isolated from everything (matches the MikroTik's `Block Guest to Lan` firewall rule and explains why [avahi](#dns--mdns) can't reflect mDNS to Guest either).

`end0` is configured with a persistent fixed IPv6 address `fd39:10::100/64` alongside DHCPv4 (`10.0.0.100`) via `/etc/netplan/10-end0.yaml` (`renderer: NetworkManager`, `ipv6-address-generation: eui64`, `ipv6.token: "::100"`). `end0.10` is a NetworkManager-rendered VLAN sub-interface (`/etc/netplan/90-NM-*.yaml`, `renderer: NetworkManager`, `id: 10`, `link: end0`). Both interfaces pick up a DHCP/RA-assigned default route from the MikroTik; the LAN one wins on metric, so outbound-from-Chainedbox traffic normally egresses via `end0` and only falls back to the IoT path if that's somehow unavailable.

The IoT leg exists specifically so services on this box can reach IoT-VLAN devices directly without needing the MikroTik to route between segments: [Home Assistant](#home-assistant) (`network_mode: host`) uses it for device discovery, and [avahi](#dns--mdns) uses it to reflect mDNS between LAN and IoT.

## DNS / mDNS

Two resolvers layered together — AdGuard Home is the LAN-facing resolver/blocker; Unbound is its upstream recursive resolver. Separately, **`avahi-daemon`** (0.8, enabled + running) handles mDNS reflection between VLANs: `enable-reflector=yes`, restricted to `allow-interfaces=end0,end0.10`. Chainedbox is dual-homed on exactly those two — `end0` (LAN, `10.0.0.100`) and `end0.10` (IoT VLAN, `10.0.1.15` — this is also what the Home Assistant/`python3` process bound to `10.0.1.15:1893` earlier turned out to be: HA reaching directly onto the IoT VLAN for device discovery). **No `end0.12` (Guest) exists on this box**, so avahi can't and doesn't reflect mDNS to/from Guest — consistent with the MikroTik's explicit `Block Guest to Lan` firewall rule; Guest staying unreflected looks intentional, not a gap. This centralizes a role that used to live on the `redmi-rm2100-f0` AP's `mdns_repeater` (see [WIFI-APS.md](WIFI-APS.md#wireless)) — that AP-level repeater has since been removed in favor of this.

**AdGuard Home** — listens on `:53` (DNS) and `:3000` (web UI). Config symlinked from `appsrv`:

```bash
systemctl stop AdGuardHome
rm /opt/AdGuardHome/AdGuardHome.yaml
ln -s /mnt/appsrv/adguard-home/AdGuardHome.yaml /opt/AdGuardHome/AdGuardHome.yaml
systemctl restart AdGuardHome
```

Read `AdGuardHome.yaml` directly (schema v34) — a few things worth knowing beyond the defaults:

- **`upstream_dns`**: local Unbound (`127.0.0.1:5335`) first, then — notably — **the 4 OpenWrt APs' own resolvers** (`10.0.0.200`–`.203`), then public fallbacks (`1.1.1.1`/`1.0.0.1`/`8.8.8.8`/`8.8.4.4`), plus a per-domain override forwarding `*.vmp.tv` (the IPTV EPG provider) straight to `172.16.3.246` — mirrors the MikroTik's own static DNS rule for the same domain. **This resolves something flagged as unclear in [WIFI-APS.md](WIFI-APS.md#dns--dhcp)**: each AP's `unbound` instance isn't a leftover/unused config — AdGuard Home is actually configured to use them as upstream resolvers, presumably as redundancy if Chainedbox's own Unbound is slow/down.
- **TLS is disabled** (`tls.enabled: false`) even though DoH/DoT/DoQ ports and a cert path (`/etc/letsencrypt/live/REDACTED-domain/`, the same Let's Encrypt cert nginx uses) are configured — encrypted DNS is wired up but not switched on.
- **`ratelimit_whitelist`** exempts `10.0.0.254` (the MikroTik itself) from DNS rate-limiting — makes sense given the router is itself a DNS client (`servers=10.0.0.100,...` in its own `/ip dns` config) and could otherwise get rate-limited relaying LAN-wide queries.
- **`user_rules`** allowlist (`$important`, i.e. override any blocklist) for `mytv.com.vn`/`mytv.vn` (the ISP's IPTV portal) and `lichviet.org` (a Vietnamese calendar app's CDN/API) — sites that block-lists were apparently catching as false positives.
- Only the **AdGuard DNS filter** blocklist is enabled; **AdAway** is present but switched off. Query log and stats both kept for 30 days on `/mnt/appsrv/adguard-home/`. DHCP is explicitly disabled here (`dhcp.enabled: false`) — confirms MikroTik, not AdGuard Home, is the DHCP authority. Admin login is bcrypt-hashed (not plaintext) — not reproduced here regardless.

**Unbound** — recursive resolver AdGuard forwards to, on `127.0.0.1:5335` (not port 5353 as in the original notes — the live `custom-port.conf` sets `5335`). Also tuned with a real cache (50m msg-cache, 100m rrset-cache, 1–24h TTL, `serve-expired: yes`) beyond what the setup notes mention:

```
# /etc/unbound/unbound.conf.d/custom-port.conf
server:
    port: 5335
    msg-cache-size: 50m
    rrset-cache-size: 100m
    cache-max-ttl: 86400
    cache-min-ttl: 3600
    serve-expired: yes
```

`systemd-resolved` is disabled on both counts so it doesn't hold port 53.

## Web server — nginx + PHP-FPM

Single vhost, `server_name _`, web root `/mnt/appsrv/www` (symlinked config at `/etc/nginx/sites-available/default` → `/mnt/appsrv/nginx/default`), TLS via Certbot (`certbot.timer`, runs twice daily) for `REDACTED-domain`.

Routes:

| Path | Purpose |
|---|---|
| `/` | Static files under `/mnt/appsrv/www` (`try_files`, PHP via `php-fpm.sock`) |
| `/ytb/backend.php` | The YTB dashboard's PHP backend — dedicated location block with `fastcgi_ignore_client_abort on` (a reload-then-Play race was observed cutting playback requests short mid-`flock`, bypassing the playback lock — this keeps the FastCGI request alive across a client disconnect) |
| `/jellyfin/` | Reverse-proxies to Jellyfin on `localhost:8096`, buffering off (for streaming), WebSocket upgrade headers passed through |
| `/tv/` | Reverse-proxies to rtp2httpd on `localhost:5140` |
| `/owntone-ws/` | Reverse-proxies to OwnTone's WebSocket notify port on `127.0.0.1:3688`. This is the one direct browser↔service connection — everything else the dashboard needs from OwnTone goes through `backend.php` server-side (so real OwnTone credentials never reach the browser), because a browser WebSocket handshake can't carry a custom `Authorization` header and PHP-FPM can't proxy a WebSocket upgrade |
| `/.well-known/acme-challenge` | Certbot HTTP-01 webroot at `/mnt/nasdata/share/www/certbot/` |

`80` redirects to `443` for the cert's hostnames (2 SANs on the same cert, both `REDACTED-domain`). Access/error logs go to `/mnt/appsrv/nginx/log/` (not the system default) — `nginx-access.log` alone is already ~140MB with no rotation configured on top of what's there; worth adding a logrotate rule if it isn't already handled elsewhere.

## Web root — `/mnt/appsrv/www/`

Besides `ytb/` (below), the same nginx vhost serves a grab-bag of standalone tools/pages, each in its own subdirectory:

| Path | What it is |
|---|---|
| `iptv/` | IPTV channel-list backend: `index.php` merges `mytvList.json` + `sctv.json` into one channel list and builds player/udpxy URLs from `?u=&ip=&p=` query params (defaults to this box on `:5140`); `fetchNewList.php` scrapes a Vietnamese IPTV EPG provider (`repg.hcm.vmp.tv`) to refresh `mytvList.json`; `logo/` holds channel logos. This is the source for the `external-m3u` rtp2httpd publishes at `/iptv` (see [IPTV stack](#iptv-stack)). |
| `mytv/` | A standalone HTML5 IPTV/channel player (`index.html`, older `index2.html`, `static/` assets) — a lighter-weight alternative UI to rtp2httpd's own player, likely the frontend that consumes `iptv/index.php`'s channel list. |
| `kod/` | [KodExplorer](https://kodcloud.com/) — a self-hosted web file manager/explorer (PHP). Per its own README this project is in maintenance mode; upstream has moved to a fork, [kodbox](https://github.com/kalcaddle/kodbox) (also present on this box's GitHub account, unused here). A leftover `KodExplorer-master (1).zip` sits alongside the unpacked app — safe to remove once confirmed it's just the install artifact. |
| `wifi/` | A small PHP device manager (`OpenWrt\DeviceManager`) for the home's OpenWrt-based routers — add/list devices by name, URL, and credentials. `config.json` currently lists the 4 APs documented in [WIFI-APS.md](WIFI-APS.md) (`redmi-rm2100-f0`, `jcg-q20-f1/f2/f3` at `10.0.0.200`–`.203`) **with their admin passwords in plaintext** — treat this file as a secret, not just config. |
| `jk/` | "JK BMS Monitor — Password Generator" — a static HTML/JS tool (`jk-bms-generator.js`) for generating passwords/credentials for a JK battery-management-system monitor (home solar/battery setup), unrelated to anything else on the box. |
| `ip/` | `index.php` — a "what's my IP" utility; resolves the real client IP behind Cloudflare (`CF-Connecting-IP` header) or `X-Forwarded-For`, and logs it to the multi-MB `ip.txt` alongside it. `index1.php` at the web root is the same script, likely an older/duplicate entry point. |

Other loose files at the web root: `c.m3u` / `r.m3u` (IPTV playlists), `unbound.sh` (an **OpenWrt** Unbound installer/config script for the router — not used on this Armbian box itself; likely staged here for distribution to the OpenWrt boxes in `wifi/config.json`).

## Dashboard — `/ytb`

Lives at `/mnt/appsrv/www/ytb/`, owned by `www-data`: `app.js`, `backend.php`, `index.php`, `config.js`, `style.css`, plus `dashboard-auth.php` / `owntone-auth.php` (auth) and `queue-daemon.php`. This is the deploy target for this project — `scp` the built files here, then `chown www-data:www-data ... && systemctl reload php8.3-fpm.service`.

Backed by **OwnTone** (music server — see below); the dashboard's own GitHub repo [ytb-owntone-dashboard](https://github.com/hungngit2/ytb-owntone-dashboard). This local repo (`home-network`) appears to be its deploy-side counterpart.

## Media server — Jellyfin

Systemd service, runs as dedicated `jellyfin` user/group, all data under `/mnt/appsrv/jellyfin`. Listens on `:8096`, proxied at `/jellyfin/` above.

`/usr/lib/systemd/system/jellyfin.service`:

```ini
[Unit]
Description = Jellyfin Media Server
After = network-online.target

[Service]
Type = simple
EnvironmentFile = /mnt/appsrv/jellyfin/env
User = jellyfin
Group = jellyfin
WorkingDirectory = /mnt/appsrv/jellyfin/var-lib
ExecStart = /usr/bin/jellyfin $JELLYFIN_WEB_OPT $JELLYFIN_FFMPEG_OPT $JELLYFIN_SERVICE_OPT $JELLYFIN_NOWEBAPP_OPT $JELLYFIN_ADDITIONAL_OPTS
Restart = on-failure
TimeoutSec = 15
SuccessExitStatus = 0 143

[Install]
WantedBy = multi-user.target
```

(The original notes truncated this `ExecStart` line — confirmed full command above from the live env file, `/mnt/appsrv/jellyfin/env`.) Data/config/log/cache dirs are all redirected off the eMMC rootfs onto `/mnt/appsrv/jellyfin/{config,log,cache,tmp,web}`; ffmpeg binary is `jellyfin-ffmpeg`.

## IPTV stack

Two cooperating pieces convert multicast IPTV feeds to HTTP for clients that can't join multicast:

- **[rtp2httpd](https://github.com/hungngit2/rtp2httpd)** — RTP/UDP/RTSP-to-HTTP gateway with an FCC (Fast Channel Change) mode tuned for Chinese IPTV, HLS reverse-proxying, Reed-Solomon FEC for packet-loss resilience, M3U playlist rewriting, and a web player/EPG. A lightweight (~450KB) dependency-free C binary using epoll + multi-worker I/O — well suited to this box's limited RAM. Runs as a systemd service on `:5140`, proxied at `/tv/` by nginx:
  ```bash
  curl -fsSL https://raw.githubusercontent.com/hungngit2/rtp2httpd/main/scripts/install-armbian.sh | sudo sh
  ```
  Config at `/etc/rtp2httpd.conf`: `maxclients=5`, `workers=1`, external M3U published at `http://10.0.0.100/iptv` (refreshed every 2h), status/player/setting pages under `/tv/status`, `/tv/player`, `/tv/setting`, and basic-auth-protected (credentials in the config file — not reproduced here).
- **go2rtc** — running as an ad-hoc process (`/bin/go2rtc -c /tmp/go2rtc-.../go2rtc_....yaml`), not a systemd unit. This is spawned on-demand by Home Assistant's built-in camera streaming (go2rtc has shipped bundled with HA core since 2024) rather than being an independent service — expect its process/config path to change across HA restarts.

`udpxy` is not installed on this box — `rtp2httpd` covers multicast→HTTP relay on its own (`iptv/index.php`'s URL-building already defaults to rtp2httpd's port, `5140`).

## Downloads — Aria2

```
# /etc/systemd/system/aria2.service
[Unit]
Description=Aria2 Download Accelerator
ConditionPathIsMountPoint=/mnt/appsrv
After=network.target

[Service]
ExecStart=/usr/bin/aria2c --conf-path /mnt/appsrv/aria2/aria2.conf
Restart=always

[Install]
WantedBy=multi-user.target
```

RPC/web UI on `:6800`. `ConditionPathIsMountPoint=/mnt/appsrv` guards against starting before the disk is mounted (not present in the original notes). `aria2.conf` downloads to `/mnt/nasdata/downloads` (32 connections/download, BitTorrent DHT + a long public tracker list enabled, session saved every 60s to `/mnt/appsrv/aria2/.aria2/`), and runs `post-download.sh` after every completed download — which just re-opens permissions (`chown nobody:nogroup` + `chmod -R 777`) on the downloads folder so any LAN client/service can pick up finished files.

## Home Assistant

Runs as a plain Docker container (`homeassistant/home-assistant:latest`), **not** the Supervisor-managed OS install — `network_mode: host` (needed for mDNS/HomeKit/casting discovery and for go2rtc above), `restart: unless-stopped`, config bind-mounted from `/opt/docker/homeassistant/config`, media from `/opt/docker/homeassistant/media`. Web UI on `:8123`.

```bash
docker exec -it homeassistant bash
wget -O - https://get.hacs.xyz | bash -   # HACS community store, installed inside the container
docker start homeassistant
```

## Music — OwnTone

**[owntone-server](https://github.com/hungngit2/owntone-server)** is a fork of OwnTone (itself descended from forked-daapd/mt-daapd) — a DAAP/DACP/MPD media server for local files, Spotify, internet radio, and AirPlay/Remote output. This fork adds: per-output channel selection (stereo-pair two speakers), native ARM64 Debian builds via GitHub Actions with a Docker-to-native migration script, and custom HTTP headers for streamed HTTP/HTTPS queue items (e.g. a `Referer` header some CDNs require).

```bash
curl -fsSL https://raw.githubusercontent.com/hungngit2/owntone-server/master/install.sh | sudo bash
```

Systemd unit at `/usr/lib/systemd/system/owntone.service`, capped at `MemoryMax=256M` / `MemorySwapMax=32M` by design (headroom for a low-RAM box) — though a systemd drop-in (`50-MemoryMax.conf`) currently overrides both back to `infinity`. Ports: `:3689` (DAAP), `:3688` (notify/WebSocket, proxied at `/owntone-ws/`), `:6600` (MPD protocol).

## File sharing — Samba

`smbd`/`nmbd` running; `netbios name = chainedbox`, workgroup `WORKGROUP`. Guest-accessible read/write shares, all pointing at `/mnt/nasdata`:

| Share | Path |
|---|---|
| `apps` | `/mnt/nasdata/apps` |
| `docs` | `/mnt/nasdata/docs` |
| `downloads` | `/mnt/nasdata/downloads` |
| `media` | `/mnt/nasdata/media` |

Plus the standard `[homes]`, `[printers]`, `[print$]` shares (unused — no printer configured).

## Scheduled tasks

- `0 3 * * * /sbin/reboot` — nightly reboot at 03:00, most likely to work around the low-RAM/occasional-hang situation rather than for any config reason.
- `certbot.timer` — runs twice daily, renews the `REDACTED-domain` cert via the `/mnt/nasdata/share/www/certbot` webroot.
