# Debian Server — Wyse 5070

Primary application server. It replaced the Chainedbox (see [ARMBIAN-SERVER.md](ARMBIAN-SERVER.md)) and took over its `10.0.0.100` identity, so the `chainedbox` alias in `~/.ssh/config` now lands here. State below was verified live on 2026-10-11.

## Hardware & OS

| | |
|---|---|
| **Hardware** | Dell Wyse 5070 Thin Client, BIOS 1.40.0 (2025-10-21) |
| **CPU** | Intel Pentium Silver J5005 (Gemini Lake, 4C/4T, 1.5 GHz base / 2.8 GHz burst) |
| **RAM** | 8 GB (7.6 GiB visible) |
| **GPU** | Intel UHD Graphics 605 (Gen9.5), used by Jellyfin for QSV transcoding |
| **Wired NIC** | Realtek RTL8111H (`r8169`), 1 GbE, `enp1s0` |
| **Wi-Fi** | Intel Gemini Lake CNVi (`iwlwifi`), `wlp0s12f0` |
| **OS** | Debian GNU/Linux 13 (Trixie), kernel `6.12.111+deb13-amd64`, `intel-microcode` 3.20251111 |

- **CPU frequency**: `intel_cpufreq` (intel_pstate in passive mode, because Gemini Lake has no HWP) with the `schedutil` governor. This is the right pairing for this CPU. Idle temperatures sit around 45–50 °C.
- **Kernel cmdline** (`/etc/default/grub`): `usbcore.autosuspend=-1 quiet`.
- **Module options** (`/etc/modprobe.d/`): `i915.conf` sets `enable_guc=2` so the HuC firmware (`glk_huc_4.0.0.bin`) loads, which Jellyfin's low-power encoders need. `usb-power.conf` repeats `usbcore autosuspend=-1`.
- **Time**: `chrony` (`systemd-timesyncd` is inactive).
- **Power management**: Wake-on-LAN (`g`) is armed by the `ethtool` `post-up` hook on `enp1s0`. A duplicate `wol.service` was removed on 2026-10-11.

## Storage

| Disk | Model | Use |
|---|---|---|
| `sda` (59.6 GiB, SATA) | "64GB SATA Flash Drive" module, firmware SFDM1B4D | rootfs, includes `/appsrv` |
| `sdb` (698.6 GiB, USB) | WD7500L 2.5" HDD, ext4 `LABEL=nasdata` | `/mnt/nasdata` bulk storage |

### Partition Layout (`/dev/sda`, GPT)

| Partition | Contents | Start (sector) | Size |
|---|---|---|---|
| `sda1` | EFI System (vfat) | 2048 | 100 MiB |
| `sda2` | `/` (ext4, `errors=remount-ro`) | 2000896 | 58.7 GiB (30% used) |

`sda3` (a 3.1 GiB swap partition) was removed on 2026-09-20 to extend `sda2`. Swap is now a 2 GiB `/swapfile` on root with `vm.swappiness = 10`. No `resume=` or hibernation is configured, so the swap file has no boot-time dependency. `/tmp` is tmpfs (the Debian 13 default).

Gotcha: growing a mounted root partition with `partx -u /dev/sda` in one shot fails with "Device or resource busy" when another partition on the same disk was deleted in the same table update (for example, removing `sda3` while growing `sda2`). Split it: run `partx -d --nr <deleted-partition>` first, then `partx -u --nr <resized-partition>`.

### Directory layout

- `/appsrv` is a plain directory on the rootfs holding app configs, the web root and service data. Sizes:

  | Directory | Size | Notes |
  |---|---|---|
  | `docker/` | 6.3G | Docker `data-root`; Home Assistant config lives in `docker/homeassistant/config` |
  | `adguard-home/` | 2.3G | Mostly the 30-day query log (`querylog.json`, `querylog.json.1`) |
  | `jellyfin/` | 292M | Data, config, cache, logs and metadata |
  | `ytb-owntone/` | 288M | YTB dashboard ⇄ OwnTone queue, pipes and cache |
  | `www/` | 164M | nginx web root |

- Compatibility symlinks keep the Chainedbox layout working: `/mnt/appsrv → /appsrv`, `/opt/docker → /appsrv/docker` and `/nasdata → /mnt/nasdata`.
- `/mnt/nasdata` is the USB HDD (`LABEL=nasdata`), used for apps, docs, downloads and media. `/etc/fstab` mounts it with `defaults,nofail,x-systemd.automount,x-systemd.idle-timeout=0,x-systemd.device-timeout=10`, and a dedicated `/etc/systemd/system/mnt-nasdata.automount` unit keeps the automount alive across USB bus events.
- **USB mass storage and power tuning**: `usbcore.autosuspend=-1` on the kernel cmdline, plus `/etc/udev/rules.d/99-nasdata.rules`, which:
  - forces `power/control=on` on every USB device;
  - caps `max_sectors_kb=1024` on USB disks to avoid bridge buffer stalls;
  - lazily unmounts `/mnt/nasdata` when the disk disappears and restarts the mount when it returns.

  No vendor/product quirks are hardcoded.

### Disk health and flash wear

- **`sda`**: SMART PASSED, about 27.3k power-on hours, about 5.2 TB written in total, no reallocated sectors or CRC errors. TRIM works and `fstrim.timer` runs weekly.
- **`sdb`**: SMART PASSED, 344 power-on hours, no pending or reallocated sectors, about 50 °C.
- **`smartd`** monitors both disks with the Debian default `DEVICESCAN` line.

The flash module is the weakest part of the box. On 2026-10-11, the module took about 6 GB of writes in the 15 h after boot (roughly 9–10 GB/day). Measured writers:

| Writer | Writes in 15h | Cause |
|---|---|---|
| Home Assistant (`python3`) | 3.5 GB | Recorder commits SQLite every 5 s (default); about 193k state rows/day, mostly inverter and meter power sensors |
| `systemd-journald` | 1.3 GB | Uncapped persistent journal (1.4 GB on disk); about 2.1k `iwlwifi` "Unhandled alg" lines/h plus NetworkManager warnings |
| `php-fpm` | 0.3 GB | Dashboard, session and cache writes |
| ext4 journal (`jbd2`) | 0.3 GB | Metadata commits |

Mitigations applied on 2026-10-11, also deployed by `setup-debian.sh`:

| Mitigation | Repo file | Live path |
|---|---|---|
| Journal cap: `SystemMaxUse=256M`, `SystemMaxFileSize=32M` (journal went from 1.4 GB to 241 MB) | `configs/chainedbox/system/journald-size.conf` | `/etc/systemd/journald.conf.d/10-size.conf` |
| HA recorder: `commit_interval: 30`; excludes `sensor.*_last_raw_mqtt_hex` (about 11k rows/day of raw debug hex) | `configs/chainedbox/homeassistant/configuration.yaml` | `/opt/docker/homeassistant/config/configuration.yaml` |

The NetworkManager warning flood was also fixed (see [Network](#network)). The power and energy sensors stay recorded on purpose.

## Network

One cable runs from `enp1s0` to the MikroTik's `ether5`, carrying VLAN 1 untagged and VLAN 10 tagged. The box has three interfaces, all reserved by IP on the MikroTik's DHCP server:

| Interface | Segment | IPv4 | IPv6 | Managed by | Default-route metric (v4 / v6) |
|---|---|---|---|---|---|
| `enp1s0` | LAN (VLAN 1) | `10.0.0.100` (DHCP reservation) | `fd39:10::100/64` (static + token `::100`) | ifupdown (`/etc/network/interfaces`) | 0 / 100 (kernel RA, `ra_defrtr_metric`) |
| `enp1s0.10` | IoT (VLAN 10) | `10.0.1.100` (DHCP reservation) | `fd39:10:0:1::100/64` (static, `ipv6.method=manual`, no RA) | NetworkManager, generated from `/etc/netplan/90-vlan10.yaml` | 400 / none |
| `wlp0s12f0` | LAN over Wi-Fi, 5 GHz only | `10.0.0.101` (DHCP reservation) | `fd39:10::101/64` (static, gateway `fd39:10::254`) | NetworkManager keyfile (home Wi-Fi SSID, WPA-PSK, `802-11-wireless.band a`, `powersave 2` = off) | 600 / 600 |

How the pieces fit together:

- **Split management.** `/etc/NetworkManager/NetworkManager.conf` has `plugins=ifupdown,keyfile` and `[ifupdown] managed=false`, so NetworkManager leaves `enp1s0` to ifupdown and handles the VLAN and Wi-Fi.
  - The VLAN 10 profile is the netplan-generated `/run/NetworkManager/system-connections/netplan-enp1s0.10.nmconnection`. Edit the netplan YAML, not an `/etc` keyfile, and don't run `netplan apply` remotely: it regenerates every profile. Use `netplan generate && nmcli con reload && nmcli con up vlan10` instead.
  - The dead `/etc/netplan/10-enp1s0.yaml`, which never activated while ifupdown owned the NIC, was moved to `/root/` on 2026-10-11.
- **IPv6 egress uses the wired NIC.** VLAN 10 used to learn an RA default route at metric 400. That route beat the wired one (1024), so IPv6 egress left through the IoT VLAN.
  - VLAN 10 is now static IPv6 with no RA, and `/etc/sysctl.d/60-ipv6-ra-metric.conf` sets `net.ipv6.conf.enp1s0.ra_defrtr_metric = 100`.
  - The same change stopped NetworkManager's `do-change-link ... Router advertisement is disabled on device` warning, which fired twice a minute. The kernel had been rejecting the `ipv6.token` passthrough because NetworkManager runs SLAAC in userspace.
- **The Wi-Fi link is LAN DNS #2.** The router's LAN DHCP hands out DNS `10.0.0.100, 10.0.0.101, 10.0.0.254`. `.101` is this same box over Wi-Fi, so it only covers a wired-link or switch-port failure, not the server being down.
  - The profile is pinned to 5 GHz with power save off. Before that, the radio ping-ponged between the 2.4 and 5 GHz radios of the nearest AP (AP-1, at about −15 dBm) every few minutes, and the router logged 56 lease renewals in 16 h. It still roams between APs on 5 GHz.
- **The box's own resolver**: `/etc/resolv.conf` is static (`127.0.0.1`, `::1`, then the router `10.0.0.254`), and `/etc/NetworkManager/conf.d/90-dns-none.conf` (`dns=none`) stops NetworkManager rewriting it. Until 2026-10-11 NetworkManager wrote it from DHCP, with the box's IoT address first, so local lookups depended on VLAN 10 being up.

### `/etc/network/interfaces`

```
auto enp1s0
iface enp1s0 inet dhcp
    metric 100
    post-up ip route replace 224.0.0.0/4 dev enp1s0 || true
    post-up ethtool -s enp1s0 wol g || true
    # Reply from 10.0.0.100 via the router, not straight out VLAN 10 (asymmetric routing)
    post-up ip route replace 10.0.0.0/24 dev enp1s0 src 10.0.0.100 table 100 || true
    post-up ip route replace default via 10.0.0.254 dev enp1s0 table 100 || true
    post-up ip rule add from 10.0.0.100 to 172.16.0.0/12 lookup main priority 97 || true
    post-up ip rule add from 10.0.0.100 lookup 100 priority 100 || true
    pre-down ip rule del priority 100 || true
    pre-down ip rule del priority 97 || true

iface enp1s0 inet6 auto
    up ip -6 addr add fd39:10::100/64 dev enp1s0 || true
    up ip token set ::100 dev enp1s0 || true
```

The `metric 100` line has no effect with ifupdown's DHCP client: the wired default route is installed at metric 0. It still wins over VLAN 10 (400) and Wi-Fi (600).

### Policy routing (IPv4)

The box sits on both LAN and IoT. Without policy routing, a reply to an IoT client that reached `10.0.0.100` through the router would leave directly via `enp1s0.10`. The router's stateful firewall would then see asymmetric traffic and drop it. Source-based rules pin each address to its own uplink:

| Priority | Rule | Purpose |
|---|---|---|
| 97 | `from 10.0.0.100 to 172.16.0.0/12 lookup main` | Keep Docker bridge replies local |
| 98 | `from 10.0.1.100 to 172.16.0.0/12 lookup main` | Same, for VLAN 10 |
| 100 | `from 10.0.0.100 lookup 100` | Table 100: `10.0.0.0/24` on-link + default via `10.0.0.254` on `enp1s0` |
| 101 | `from 10.0.1.100 lookup 101` | Table 101: `10.0.1.0/24` on-link + default via `10.0.1.254` on `enp1s0.10` |

Rules 97 and 100 come from the ifupdown hooks above. Rules 98 and 101 come from the `routes`/`routing-policy` blocks in `90-vlan10.yaml`. The Wi-Fi address (`10.0.0.101`) has no policy rule and uses the main table.

### Multicast, ARP and sysctl

- **IPTV multicast**: a static `224.0.0.0/4 dev enp1s0` route forces `rtp2httpd`'s IGMP joins out the wired LAN NIC toward the router's IGMP proxy.
- **ARP flux**: two NICs share `10.0.0.0/24`, so `arp_ignore=1` / `arp_announce=2` stop each NIC from answering for the other's address.
- **`/etc/sysctl.d/99-server.conf`** (repo: `configs/chainedbox/system/sysctl-swapfile.conf`) sets:
  - `fq` + BBR;
  - IPv4 `ip_forward` on (Docker needs it); IPv6 forwarding **off** since 2026-10-11, because Docker has no IPv6 and the box must not route between its segments;
  - `igmp_max_memberships=100`;
  - `swappiness=10`;
  - `max_map_count=262144`;
  - `hung_task_timeout_secs=0`;
  - TCP keepalive 120 s and FIN timeout 30 s.

The unused NetworkManager profiles `cellular` (GSM, no modem present) and `Wired connection 1` were deleted on 2026-10-11.

### Host firewall, forwarding and SSH

The box has an interface directly on the IoT VLAN, so the router's IoT→LAN block never sees camera→Wyse traffic. Until 2026-10-11 the box also forwarded IPv4 and IPv6 between `enp1s0`, `enp1s0.10` and `wlp0s12f0` with an ACCEPT policy. An IoT device that used `10.0.1.100` as its gateway could therefore reach the whole LAN. These layers now cover that path:

- **`nft-hostguard.service`** loads `/etc/nftables-hostguard.nft`, a standalone `inet hostguard` table. Traffic from `10.0.1.0/24` or `fd39:10:0:1::/64` gets a TCP reset on 22 (SSH), 139/445 (Samba), 3000 (AdGuard UI), 6800 (Aria2 RPC) and 8088 (code-server), and NetBIOS 137/138 is dropped.
  - Everything else stays open to IoT: DNS, mDNS, HTTP/S, Jellyfin, HA, go2rtc, OwnTone and SSDP replies.
  - The table lives outside `nftables.service` on purpose: that unit's `ExecStop` flushes the whole ruleset, Docker's tables included.
  - Its `forward` chain drops anything routed between `enp1s0`, `enp1s0.10` and `wlp0s12f0`, in any direction. Docker bridges are not in that list, so container traffic is unaffected.
  - Check hits with `nft list table inet hostguard`.
- **SSH**: `/etc/ssh/sshd_config.d/10-password-lan-vpn-only.conf` turns off password auth except from `10.0.0.0/24`, `10.0.100.0/24`, `fd39:10::/64` and `fd39:10:100::/64`. Password logins from LAN and VPN clients keep working, and keys work from anywhere. `PermitRootLogin` is still `yes`.

## Services

| Service | Port(s) | Config / data | Notes |
|---|---|---|---|
| AdGuard Home v0.107.79 | 53 tcp/udp, UI 3000 | `/appsrv/adguard-home/` (`/opt/AdGuardHome/AdGuardHome.yaml` symlinks here) | Upstreams in parallel mode: local Unbound `127.0.0.1:5335` + the 4 APs' Unbound (`10.0.0.200`–`203`); the ISP IPTV domain goes to the ISP resolver. Bootstrap Quad9, fallback Cloudflare/Google. DNSSEC on. Query log and stats kept 30 days. |
| Unbound 1.26 | `127.0.0.1:5335`, `[::1]:5335` | `/etc/unbound/unbound.conf.d/custom-port.conf` | Recursive resolver behind AdGuard: 2 threads, 50 MB message + 100 MB RRset cache, prefetch, serve-expired. The Chainedbox-era duplicate `custom.conf` and its 1-hour minimum TTL were removed on 2026-10-11; the minimum TTL kept CDN and DDNS answers stale. |
| avahi-daemon 0.8 | 5353/udp | `/etc/avahi/avahi-daemon.conf` | mDNS reflector `enp1s0` ⇄ `enp1s0.10` (LAN ⇄ IoT); Guest excluded on purpose |
| nginx 1.26 + PHP-FPM 8.4 | 80, 443 | vhost `/appsrv/nginx/default`, root `/appsrv/www` | `/ytb` dashboard, `/iptv` playlist, `/tv/` → rtp2httpd, `/jellyfin/`, `/owntone-ws/`, `/wifi-config-tool`, `/mikrotik-lte`, `/jk`, `/kodbox`. Let's Encrypt cert via `certbot.timer`. `configs`, `src` and `*.json` under the two PHP tools return 404. |
| MariaDB 11.8 | `127.0.0.1:3306` | `/var/lib/mysql` | Single `kodbox` database for the KodBox file manager |
| queue-daemon | — | `/appsrv/www/ytb/queue-daemon.php` | YTB dashboard queue auto-advance, runs as `www-data` |
| Jellyfin 12.2 | 8096, 7359/udp | `/appsrv/jellyfin` (paths set in `/etc/default/jellyfin`) | Intel QSV, see below. `/etc/jellyfin/*.xml` are stale install defaults and are not read. |
| OwnTone 29.2 | 3689 (DAAP), 6600 (MPD), 3688 (stream) | `/appsrv/ytb-owntone` | `MemoryMax=256M` |
| rtp2httpd | 5140 | `/etc/rtp2httpd.conf` | IPTV multicast → HTTP; `maxclients=5`, `workers=1`, served under `/tv`, external M3U `http://10.0.0.100/iptv/` refreshed every 2 h |
| Aria2 1.37 | 6800 (RPC) | `/appsrv/aria2/aria2.conf` | `aria2-post-download.sh` moves finished movie files and torrent folders (with subtitles) to `/nasdata/media/movies` and sets `nobody:nogroup` / `777`. The dead rarbg tracker was dropped from `bt-tracker`. |
| Samba 4.22 | 139, 445 | `/etc/samba/smb.conf` (the real file; `/appsrv/samba/smb.conf` links to it) | Shares `apps`, `docs`, `downloads`, `media` from `/mnt/nasdata`. NetBIOS name `WYSE-5070` with alias `chainedbox`, so old `\\chainedbox\…` paths still work. NetBIOS is answered on `lo` + `enp1s0` only (`interfaces`). |
| Home Assistant 2026.9.4 | 8123, go2rtc 18555 | `/opt/docker/homeassistant/config` | Docker container `homeassistant`: `--net=host`, `--privileged`, `--restart unless-stopped`, image `homeassistant/home-assistant:latest`. Integrations include the Lumentree inverter and meter/grid protectors. |
| code-server 4.140 | 8088 | `/home/admin/.config/code-server/config.yaml` | Runs as `admin` |
| Also running | — | — | `chrony`, `smartd`, `vnstat`, `cron`, `docker`/`containerd`, `nft-hostguard` (oneshot) |
| unattended-upgrades | — | `/etc/apt/apt.conf.d/20auto-upgrades` | Daily Debian security updates, no auto-reboot |

### Jellyfin hardware transcoding (Intel QSV)

The live config is `/appsrv/jellyfin/config/encoding.xml`. It uses `jellyfin-ffmpeg8` 8.1.3 with `intel-media-va-driver-non-free` 25.2.3 (iHD) on `/dev/dri/renderD128`. The `jellyfin` user is in the `video` and `render` groups.

| Setting | Value |
|---|---|
| Acceleration | `qsv` |
| HW decoding | h264, hevc (incl. 10-bit), vp9 (incl. 10-bit), vc1, mpeg2 |
| HW encoding | H.264 only (HEVC encode off); Intel low-power H.264/HEVC encoders on |
| Tone mapping | VPP tone mapping on |
| Encoder | preset `superfast`, CRF 23, 4 threads; throttling and segment deletion on |

- **Low-power (VDENC) encoding** needs HuC firmware, hence `i915 enable_guc=2`. Confirm with `cat /sys/kernel/debug/dri/0/gt/uc/huc_info` (should show `status: RUNNING`).
- **Gemini Lake limits**: no AV1 decode, no VP9 encode, and HEVC encode is 8-bit only. `vainfo --display drm --device /dev/dri/renderD128` lists the supported profiles.
- The legacy `i965-va-driver` was purged on 2026-10-11. Only iHD is installed.

### Docker

`/etc/docker/daemon.json` (repo: `configs/chainedbox/system/daemon.json`) does the following:
- moves `data-root` to `/appsrv/docker`;
- sets the default bridge to `172.31.0.1/24`;
- sets `json-file` logs to 10 MB × 5;
- sets `"live-restore": true`, so restarting `dockerd` leaves Home Assistant running (verified 2026-10-11).

The two China-based registry mirrors (dead) and the unused `omni-network` bridge were removed on 2026-10-11. `scripts/docker-upgrade.sh` (installed as `/usr/local/bin/docker-upgrade`) does one-shot Watchtower upgrades.

### Notes on inherited service config (reviewed 2026-10-11)

These settings were cloned from the Chainedbox (1 GB ARM).
- **Samba config**: `/etc/samba/smb.conf` had been a standalone file since 2026-09-19 (a package upgrade replaced the symlink). The `/appsrv` copy was stale, with old share paths.
  - The live file still announced itself as `chainedbox`, and nmbd registered that name on the Docker bridges, so lookups returned `172.31.0.1`.
  - The stale copy is kept at `/root/smb.conf.appsrv-stale-20261011`.
- **nginx logs**: the logs on `/appsrv` were never rotated. `/etc/logrotate.d/nginx-appsrv` now rotates them weekly, keeping 8.
- **Checked and left alone**:
  - PHP-FPM `pm.max_children=5`: never hit.
  - OwnTone `MemoryMax=256M`: peak 138 MB, no OOM kills.
  - nginx's default 1 MB body limit: its only rejections were internet scanners POSTing 10 MB to the WAN IP.
  - MariaDB defaults: one small `kodbox` database.
  - rtp2httpd: `workers=1`, `maxclients=5`.

### Packages removed (2026-10-11)

- **Installer leftovers and tasks**: `os-prober`, `tasksel`, `tasksel-data`, `task-english`, `task-ssh-server` (`openssh-server` was marked manual first), `laptop-detect`, `installation-report`, `reportbug` and its Python libraries.
- **Unused modem tools**: `libqmi-utils`, `libmbim-utils` and their libraries; the modem lives in the router now.
- **Mesa OpenCL**: `mesa-opencl-icd` with libclc, libclang and 32-bit libraries. Jellyfin uses QSV with VPP tone mapping, not OpenCL.
- **Old kernel and leftovers**: kernel `6.12.94`, the leftover `systemd-timesyncd` config, `/var/log/installer`.

About 3 GB was freed. Kernels `6.12.107` and `6.12.111` remain.

Kept on purpose:
- `firmware-sof-signed`: the audio uses SOF.
- `python3-pip`: it provides `yt-dlp` for the YTB dashboard.
- Compilers, `git` and code-server: development on the box.
- `popularity-contest`: opted in at install.

## Tuning script and what's left

[`scripts/tune-wyse5070.sh`](scripts/tune-wyse5070.sh) applies everything marked 2026-10-11 above. It is idempotent, backs up each edited file as `*.bak-YYYYMMDD`, and leaves `enp1s0` (the SSH path) alone. Re-run it after a rebuild:

```bash
ssh -i ~/.ssh/id_hungnguyen root@10.0.0.100 'bash -s' < scripts/tune-wyse5070.sh
```

Kept as-is on purpose:
- **`iwlwifi` "Unhandled alg" messages** (about 2k lines/h): a harmless firmware/driver message on this CNVi card. The journal cap contains it.
- **AdGuard query log at 30 days** (2.3 GB). There's plenty of free space, and a shorter retention doesn't reduce writes.
- **`PermitRootLogin yes`**: root password logins from LAN clients are in regular use.
- **`/etc/jellyfin/*.xml`**: stale install defaults owned by the package. Jellyfin reads `/appsrv/jellyfin/config`.

## Custom MOTD / Welcome Screen

Although the box runs standard Debian 13, it shows an Armbian-style dynamic MOTD on SSH login. Custom scripts in `/etc/update-motd.d/` (for example `10-armbian-header`, `30-armbian-sysinfo`, `41-commands`) print dynamic stats such as load, memory usage, IP addresses and uptime.

- **IPv6 support**: the MOTD parses and displays both IPv4 and IPv6 addresses.
- **WAN addresses**: the public IPv4 and IPv6 are looked up live and shown in full. The IPv4 used to be masked as `x.x.***.***`.
- **Commands section**: shows a curated list of commands (for example `nmtui` for network configuration, `apt upgrade` for system upgrades, `htop` for monitoring).
- **Default Debian MOTD disabled**: the standard Debian copyright and warranty text is removed for a cleaner look.

## Automated Bootstrap Installer (`setup-debian.sh`)

To rebuild or provision a fresh **Debian** server from scratch, run the bootstrap installer directly from GitHub:

```bash
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

### Supported Custom Environment Variables (for Non-Interactive Deployments)

All settings are auto-detected with sensible defaults, or can be set through environment variables.

**Single-drive layout (e.g. Debian on a SATA SSD / NVMe rootfs only):**
```bash
export NON_INTERACTIVE=true
export STATIC_IPV4="10.0.0.100"          # Server IPv4 (auto-detected if unset)
export STATIC_IPV6_ULA="fd39:10::100/64" # Static IPv6 ULA address/prefix
export APPSRV_DIR="/appsrv"              # App storage directly on rootfs
export NASDATA_DIR="/nasdata"            # Bulk/Samba storage on rootfs
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

**Hybrid layout (Wyse 5070 with SSD rootfs + external USB drive for `/mnt/nasdata`):**
```bash
export NON_INTERACTIVE=true
export STATIC_IPV4="10.0.0.100"          # Server IPv4 (auto-detected if unset)
export STATIC_IPV6_ULA="fd39:10::100/64" # Static IPv6 ULA address/prefix
export APPSRV_DIR="/appsrv"              # App storage on local SSD
export NASDATA_DIR="/mnt/nasdata"        # Bulk storage on external USB disk
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

The script automatically runs these steps:
1. **Storage setup**: provisions directories on `/appsrv` and `/mnt/nasdata`, with the compatibility symlinks `/mnt/appsrv -> /appsrv`, `/nasdata -> /mnt/nasdata` and `/opt/docker -> /appsrv/docker`.
2. **Base OS tuning**: deploys `sysctl-swapfile.conf` when there's no zram swap (the Chainedbox gets the zram `sysctl.conf` instead), caps the journal, and configures the Docker daemon.
3. **Network setup**: ULA configuration, the IoT VLAN, and Wake-on-LAN.
4. **MOTD customization**: fetches and applies the Armbian-style welcome screen from `configs/debian-motd/`.
5. **DNS and web stack**: nginx + PHP-FPM, web tools, Unbound, AdGuard Home.
6. **Media services**: `rtp2httpd`, OwnTone, Jellyfin, Aria2, Samba.
7. **Smart home**: Home Assistant in Docker.

Not covered by `setup-debian.sh`, so redo these by hand on a rebuild:
- the ifupdown policy-routing hooks;
- the Wi-Fi profile;
- `i915 enable_guc=2`;
- the `/swapfile`;
- code-server, MariaDB/KodBox and `queue-daemon`;
- `/etc/NetworkManager/conf.d/90-dns-none.conf` and the static `/etc/resolv.conf`.

After that, run `tune-wyse5070.sh`.
