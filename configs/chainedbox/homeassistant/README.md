# Home Assistant config (Wyse 5070)

Exported from `/opt/docker/homeassistant/config` on the Wyse (HA 2026.9, Docker, host network) on 2026-11-20. The folder name is historical; this is the live Wyse config.

| File | Restores to | Contents |
|---|---|---|
| `configuration.yaml` | `configuration.yaml` | Recorder tuning; kWh templates for the Tuya energy plugs, which report Wh (with `availability:` guards); Battery Status and Battery Runtime sensors |
| `automations.yaml` | `automations.yaml` | Grid fallback at 30%/40% battery SOC, low-battery and grid-fallback alerts, inverter fault and offline alerts, irrigation auto-off, bike charger cut-off |
| `lovelace.lovelace.json` | `.storage/lovelace.lovelace` | Default dashboard: Home, Energy, Battery & Inverter, System |

The dashboard needs the HACS cards `apexcharts-card` and `Power-Flow-Card-Inverter`.

## Restore

1. Replace `EVN_CUSTOMER_ID` in `lovelace.lovelace.json` with the EVN customer code; it is part of the `nestup_evn` entity IDs.
2. `docker stop -t 120 homeassistant`. HA overwrites `.storage` files that are edited while it runs.
3. Copy the files into place, run `docker exec homeassistant python -m homeassistant --script check_config -c /config` once it is started again, and check the log.

## Not in this folder

- `secrets.yaml`, `.storage/auth*` and the database (`home-assistant_v2.db`) hold credentials or history. Never commit them.
- **HTTP settings are not in YAML.** HA 2026.9 ignores an `http:` block in `configuration.yaml` without any warning. They live in `.storage/http` → `data.stable`:

  ```json
  "server_host": ["127.0.0.1", "::1"],
  "use_x_forwarded_for": true,
  "trusted_proxies": ["127.0.0.1", "::1"],
  "ip_ban_enabled": true,
  "login_attempts_threshold": 5
  ```

  `internal_url` and `external_url` (`https://ha.REDACTED-domain`) live in `.storage/core.config`. HA is reachable only through the nginx vhost (see DEBIAN-SERVER.md).

## Safety

Do not add dashboard controls or automations for `switch.cb_floor_1`, `switch.cb_ground_floor`, `switch.cb_floor_2` or the Sonoff network relay. Floor 1 feeds the router and the Wyse itself, so switching it off from HA cannot be undone remotely. `switch.cb_floor_1` is disabled in the entity registry.
