# Home Assistant config (Wyse 5070)

Exported from `/opt/docker/homeassistant/config` on the Wyse (HA 2026.9, Docker, host network) on 2026-11-20.

| File | Restores to | Contents |
|---|---|---|
| `configuration.yaml` | `configuration.yaml` | Recorder tuning; kWh templates for the Tuya energy plugs, which report Wh (with `availability:` guards); Battery Status and Battery Runtime sensors |
| `automations.yaml` | `automations.yaml` | Grid fallback at 30%/40% battery SOC, low-battery and grid-fallback alerts, inverter fault and offline alerts, irrigation auto-off, bike charger cut-off |
| `lovelace.lovelace.json` | `.storage/lovelace.lovelace` | Default dashboard: Home, Energy, Equipment, Settings (admin only) |
| `gen_dashboard.py` | — | Rebuilds that dashboard. Run it on the Wyse with HA stopped: `python3 gen_dashboard.py .storage/lovelace.lovelace`. It keeps the live Home Energy Hub card and the EVN entity IDs |

The dashboard needs the HACS cards `apexcharts-card` and Home Energy Hub (`gratak/solar-flow-card`, `type: custom:home-energy-hub`, Diorama theme on the Energy view).

## Solar forecast

**Open-Meteo Solar Forecast** (HACS `rany2/ha-open-meteo-solar-forecast`) feeds the card and the Energy settings. It is one entry, "House 39 PV (Open-Meteo)", with two arrays at HA's home location, both facing south (azimuth 180):

| Array | Tilt | Peak power |
|---|---|---|
| String 1 (6 x 550 W) | 9° | 3300 W |
| String 2 (3 x 615 W) | 14° | 1845 W |

The card's `pv_forecast` uses `sensor.house_39_pv_open_meteo_energy_production_today`, `_today_remaining`, `_tomorrow` and `_d2`–`_d4`. In the Energy settings it is the only solar forecast; HA adds every listed forecast together, so never list two sources at once.

Open-Meteo replaced Forecast.Solar on 2026-11-20. For that day Open-Meteo forecast 28.2 kWh against 25.2 kWh actual, while Forecast.Solar's free tier forecast 9.37 kWh. The Forecast.Solar entries and their `sensor.pv_forecast_*` sums have been removed.

## Restore

1. Replace `EVN_CUSTOMER_ID` in `lovelace.lovelace.json` with the EVN customer code; it is part of the `nestup_evn` entity IDs.
2. `docker stop -t 120 homeassistant`. HA overwrites `.storage` files that are edited while it runs.
3. Copy the files into place, run `docker exec homeassistant python -m homeassistant --script check_config -c /config` once it is started again, and check the log.

## Not in this folder

- `secrets.yaml`, `.storage/auth*` and the database (`home-assistant_v2.db`) hold credentials or history. Never commit them.
- **HTTP settings are not in YAML.** HA 2026.9 ignores an `http:` block in `configuration.yaml` without any warning. They live in `.storage/http` → `data.stable`:

  ```json
  "use_x_forwarded_for": true,
  "trusted_proxies": ["127.0.0.1", "::1"],
  "ip_ban_enabled": true,
  "login_attempts_threshold": 5
  ```

  `internal_url` and `external_url` (`https://ha.REDACTED-domain`) live in `.storage/core.config`. HA listens on all addresses: `http://10.0.0.100:8123` works from the LAN, port 8123 is rejected from the IoT VLAN by nft-hostguard, and the internet reaches it only through the nginx vhost (see DEBIAN-SERVER.md).

## Safety

Do not add dashboard controls or automations for `switch.cb_floor_1`, `switch.cb_ground_floor`, `switch.cb_floor_2` or the Sonoff network relay. Floor 1 feeds the router and the Wyse itself, so switching it off from HA cannot be undone remotely. `switch.cb_floor_1` is disabled in the entity registry.
