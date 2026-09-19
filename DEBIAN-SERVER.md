# Debian Server — Wyse 5070

## Hardware & OS

- **Hardware**: Dell Wyse 5070 Thin Client (x86_64 architecture).
- **OS**: Debian GNU/Linux 13 (Trixie), kernel `6.12.107+deb13-amd64` (standard amd64 Debian kernel).
- **Resources**: ~8GB RAM (7.59G visible to OS).
- **Network**: Dual-stack on trusted LAN. IPv4 `10.0.0.100` and static IPv6 ULA `fd39:10::100/64` via the `enp1s0` interface. 
- **Power Management**: Wake-on-LAN is explicitly configured and persisted using a `wol.service` systemd unit running `ethtool -s enp1s0 wol g` on boot, keeping the Realtek NIC alive when suspended or powered off.

## Storage

Unlike the Chainedbox which relies heavily on external media for app data, the Wyse 5070 uses its internal SSD (approx 64GB) as the primary storage.

- `/appsrv` — Local directory for app configs, web root, service data. 
- `/mnt/appsrv` — Symlinked to `/appsrv` to maintain compatibility with legacy scripts and services that expect the Chainedbox layout.
- `/mnt/nasdata` — Mounted USB external drive (`LABEL=nasdata`) for bulk storage (apps, docs, downloads, media). Configured in `/etc/fstab` with `defaults,nofail,x-systemd.automount,x-systemd.idle-timeout=0,x-systemd.device-timeout=10` and backed by a dedicated `/etc/systemd/system/mnt-nasdata.automount` unit for persistent automounting across USB bus events.
- `/nasdata` — Symlinked to `/mnt/nasdata` for backward compatibility.
- USB Mass Storage & Power Tuning: Configured with generic `usbcore.autosuspend=-1` in GRUB cmdline and udev rules (`/etc/udev/rules.d/99-nasdata.rules`) to disable USB autosuspend across all USB devices, limit USB block queue transfer sizes (`max_sectors_kb=1024`) to prevent bridge buffer stalls, and auto-recover stale mounts without hardcoded vendor/product quirks.
- Swap is used instead of Zram, configured on the local SSD.

## Custom MOTD / Welcome Screen

Although running standard Debian 13, the server is customized with an Armbian-style dynamic MOTD upon SSH login. 
This is achieved by deploying custom scripts to `/etc/update-motd.d/` (e.g., `10-armbian-header`, `30-armbian-sysinfo`, `41-commands`), which calculate and print dynamic stats like Load, Memory Usage, IP Addresses, and Uptime.

- **IPv6 Support**: The MOTD correctly parses and displays both IPv4 and IPv6 addresses.
- **Commands Section**: Displays a curated list of commands (e.g., `nmtui` for network configuration, `apt upgrade` for system upgrades, `htop` for monitoring).
- **Default Debian MOTD Disabled**: The standard Debian copyright and warranty texts have been removed/truncated for a cleaner look.

## Automated Bootstrap Installer (`setup-debian.sh`)

To rebuild or provision a fresh **Debian** server from scratch, run the bootstrap installer directly via GitHub:

```bash
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

### Supported Custom Environment Variables (for Non-Interactive Deployments)

All settings are auto-detected with sensible defaults, or customizable via environment variables:

**Single-Drive Layout (e.g. Debian on SATA SSD / NVMe rootfs only):**
```bash
export NON_INTERACTIVE=true
export STATIC_IPV4="10.0.0.100"          # Server IPv4 (auto-detected if unset)
export STATIC_IPV6_ULA="fd39:10::100/64" # Static IPv6 ULA address/prefix
export APPSRV_DIR="/appsrv"              # App storage directly on rootfs
export NASDATA_DIR="/nasdata"            # Bulk/Samba storage on rootfs
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

**Hybrid Layout (Wyse 5070 with SSD rootfs + External USB Drive for `/mnt/nasdata`):**
```bash
export NON_INTERACTIVE=true
export STATIC_IPV4="10.0.0.100"          # Server IPv4 (auto-detected if unset)
export STATIC_IPV6_ULA="fd39:10::100/64" # Static IPv6 ULA address/prefix
export APPSRV_DIR="/appsrv"              # App storage on local SSD
export NASDATA_DIR="/mnt/nasdata"        # Bulk storage on external USB disk
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

The script automatically executes:
1. **Storage Setup**: Directory structure provisioning on `/appsrv` and `/mnt/nasdata` (with backward compatibility symlinks `/mnt/appsrv -> /appsrv` and `/nasdata -> /mnt/nasdata`).
2. **Network Setup**: ULA configuration, interface setup, and Wake-on-LAN configuration.
3. **MOTD Customization**: Fetches and applies the custom Armbian-style welcome screen from `configs/debian-motd/`.
4. **DNS & Web Stack**: Nginx + PHP-FPM, web tools, Unbound, AdGuard Home.
5. **Media Services**: `rtp2httpd`, OwnTone, Jellyfin, Aria2, Samba.
6. **Smart Home**: Docker-based Home Assistant container.

## Network Configuration Details

The Wyse 5070 uses `/etc/network/interfaces` alongside Netplan (`/etc/netplan/`) for network configuration:

```
auto lo
iface lo inet loopback

allow-hotplug enp1s0
iface enp1s0 inet dhcp
  metric 100
  post-up ip route replace 224.0.0.0/4 dev enp1s0 || true
  post-up ethtool -s enp1s0 wol g || true

iface enp1s0 inet6 auto
  up ip -6 addr add fd39:10::100/64 dev enp1s0 || true
  up ip token set ::100 dev enp1s0 || true
```

- **Wake-on-LAN**: Armed automatically on interface up (`post-up /sbin/ethtool -s enp1s0 wol g`).
- **IPTV Multicast Routing**: Explicit static multicast route (`224.0.0.0/4 dev enp1s0`) forces all IGMP join reports from `rtp2httpd` out the primary LAN interface (`enp1s0`) to reach the router's `br-lan` downstream IGMP proxy.

## Services (Mirror of Chainedbox)

Because the setup uses the shared `setup-debian.sh` bootstrap script, the Wyse 5070 mirrors the services found on the Chainedbox, with hardware-specific tunings:
- **DNS / mDNS**: AdGuard Home, Unbound, Avahi
- **Web**: Nginx + PHP-FPM
- **Media (Jellyfin)**: Hardware-accelerated transcoding via Intel QuickSync (QSV) using `intel-media-va-driver-non-free` (iHD Gen9.5 driver) on the Intel UHD Graphics 605 GPU, supporting 10-bit HEVC/VP9 decoding, low-power H.264/HEVC encoding, and VPP tone mapping.
- **IPTV (rtp2httpd)**: Multicast-to-HTTP IPTV streamer with external M3U playlist integration published via Nginx (`http://10.0.0.100/iptv/`).
- **Smart Home**: Home Assistant (Docker)
- **Downloads & Storage (Aria2 & Samba)**: Aria2 download daemon with `aria2-post-download.sh` hook that automatically identifies completed movie/video downloads (single files and torrent directories), relocates them to `/nasdata/media/movies`, preserves subtitles, and sets permissions (`nobody:nogroup`, `777`). Samba shares expose `/nasdata` to the local network.
