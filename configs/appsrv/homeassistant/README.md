# Home Assistant config (Wyse 5070)

Custom cards in `www/` are split into a tiny loader (`NAME.js`, the Lovelace resource) and the code (`NAME.impl.js`): the loader re-fetches the implementation every minute, so edits show up without bumping the resource version (HA caches `/local` for 31 days). Edit `NAME.impl.js`.

Exported from `/opt/docker/homeassistant/config` on the Wyse (HA 2026.9, Docker, host network) on 2026-11-20.

| File | Restores to | Contents |
|---|---|---|
| `configuration.yaml` | `configuration.yaml` | Recorder tuning; kWh templates for the Tuya energy plugs, which report Wh (with `availability:` guards); Battery Status, Battery Runtime and Battery energy left sensors; Settings health-check sensors (Devices offline, Updates pending, Safety automations off: the device and automation lists live here and in `gen_dashboard.py`) |
| `automations.yaml` | `automations.yaml` | Grid fallback at 30%/40% battery SOC, low-battery and grid-fallback alerts, inverter fault and offline alerts, irrigation auto-off, bike charger cut-off |
| `lovelace.lovelace.json` | `.storage/lovelace.lovelace` | Default dashboard: Home, Energy, Equipment, Settings (admin only) |
| `lovelace.inverter-setup.json` | `.storage/lovelace.inverter_setup` | **Solar Inverter Settings** (page title Luxpower SNA PRO 6.5kW) dashboard: sidebar entry `/inverter-setup`, admin only (registered in `.storage/lovelace_dashboards` as id `inverter_setup`, `require_admin: true`) |
| `gen_inverter_tab.py` | — | Builds the admin-only **Inverter** dashboard (all LuxPower controls) and trims Settings. Controls show only when their driver makes them meaningful (AC charging, Charge first, Forced discharge, Export, Peak shaving, charge/discharge control types); `input_boolean.inverter_advanced` reveals tuning, generator/smart load, AC coupling and the read-only installer list. Run after `gen_dashboard.py`, on the Wyse with HA stopped: `python3 gen_inverter_tab.py .storage/core.entity_registry .storage/lovelace.lovelace .storage/lovelace.inverter_setup .storage/lovelace_dashboards`; `--check STATES.json` runs the offline visibility self-check |
| `www/collapsible-section-card.js` | `www/collapsible-section-card.js` | `custom:collapsible-section-card`: foldable group of cards (remembers open/closed per browser, renders children only while open). Used for every group on the Inverter tab. Needs a Lovelace resource `/local/collapsible-section-card.js` |
| `www/time-window-card.js` | `www/time-window-card.js` | `custom:time-window-card`: compact numbered Start/End editor for inverter time windows (small time inputs, read-back tick, Clear). Needs a Lovelace resource `/local/time-window-card.js` |
| `www/inverter-wizard-card.js` | `www/inverter-wizard-card.js` | `custom:inverter-wizard-card`: four guided wizards (backup reserve, charge from grid, export, battery protection) with a review step and serialized, read-back writes. Needs a Lovelace resource `/local/inverter-wizard-card.js` |
| `www/battery-report-card.js` | `www/battery-report-card.js` | `custom:battery-report-card` on the Equipment tab: Day/Week/Month/Year picker, total and overnight (18:00–06:00) discharge kWh, and measured real battery capacity kWh and health (the inverter's SoH is always 100%). Needs a Lovelace resource `/local/battery-report-card.js` |
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

## Grid fallback and the inverter

The LuxPower inverter is rated 6.5 kW and runs Battery First with Take Load Together on. `switch.grid_protector` connects the grid:

| Rule | Connects the grid | Disconnects it |
|---|---|---|
| Low battery | SOC ≤ 30% | SOC ≥ 40% and load < 4 kW |
| High load | load > 5.5 kW for 5 s | load < 4 kW for 5 min and SOC > 39% |

The inverter's **on-grid cut-off SOC is 30%** (set 2026-11-21; it was 15%). With 15%, the inverter kept draining the battery after the grid was connected, so the low-battery fallback did nothing. Keep the cut-off equal to the fallback threshold.

Grid outage: `alert_grid_outage` fires when the grid protector, which measures the EVN supply side, reads under 150 V for 1 min or is offline for 5 min. It sets `input_boolean.grid_outage`, which shows a red line in the Home status row. `alert_grid_restored` fires when the voltage is back above 190 V for 2 min and clears the flag.

The alerts (low battery, grid engaged, grid outage and restored, inverter fault, inverter offline) go to `notify.mobile_app_hung_s_iphonese2` and `notify.mobile_app_mais_iphone16p`. Use the `notify.mobile_app_*` actions; the phones' `notify.*` entities are not services.

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
