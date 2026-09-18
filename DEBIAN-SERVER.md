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

**Single-Drive Layout (e.g. Debian on 64GB SATA SSD / NVMe rootfs):**
```bash
export NON_INTERACTIVE=true
export STATIC_IPV4="10.0.0.100"          # Server IPv4 (auto-detected if unset)
export STATIC_IPV6_ULA="fd39:10::100/64" # Static IPv6 ULA address/prefix
export APPSRV_DIR="/appsrv"              # App storage directly on rootfs
export NASDATA_DIR="/nasdata"            # Bulk/Samba storage on rootfs
curl -fsSL https://raw.githubusercontent.com/hungngit2/home-network/main/scripts/setup-debian.sh | sudo bash
```

The script automatically executes:
1. **Storage Setup**: Directory structure provisioning on `/appsrv` and `/nasdata`.
2. **Network Setup**: ULA configuration, interface setup, and Wake-on-LAN configuration.
3. **MOTD Customization**: Fetches and applies the custom Armbian-style welcome screen from `configs/debian-motd/`.
4. **DNS & Web Stack**: Nginx + PHP-FPM, web tools, Unbound, AdGuard Home.
5. **Media Services**: `rtp2httpd`, OwnTone, Jellyfin, Aria2, Samba.
6. **Smart Home**: Docker-based Home Assistant container.

## Network Configuration Details

The Wyse 5070 uses `/etc/network/interfaces` for its network configuration instead of `netplan` (which the older Chainedbox primarily relies on).

```
auto lo
iface lo inet loopback

auto enp1s0
iface enp1s0 inet dhcp
  post-up /sbin/ethtool -s enp1s0 wol g

iface enp1s0 inet6 static
  address fd39:10::100/64
```

This configuration ensures that Wake-on-LAN is armed automatically when the interface comes up.

## Services (Mirror of Chainedbox)

Because the setup uses the shared `setup-debian.sh` bootstrap script, the Wyse 5070 mirrors most of the services found on the Chainedbox, including:
- **DNS / mDNS**: AdGuard Home, Unbound, Avahi
- **Web**: Nginx + PHP-FPM
- **Media**: Jellyfin, OwnTone, rtp2httpd
- **Smart Home**: Home Assistant (Docker)
- **Downloads & File Sharing**: Aria2, Samba
