# Tuya Local profiles not (yet) in upstream

`reclosing_protector_breaker.yaml`: the kitchen IH Cooker breaker, Tuya product `ajxxvizkghnasyxi`
("Smart Reclosing Protector", category cz). DPs: 101 switch, 106 current (0.1 A), 107 energy
(0.1 kWh), 108 voltage, 109 leakage mA, 26 fault bitmap, 110/114-117 protection limits, 111 mute.

A HACS update of Tuya Local deletes files it does not ship. After updating, restore and restart:

    scp -i ~/.ssh/id_hungnguyen configs/appsrv/homeassistant/tuya_local/*.yaml \
      root@10.0.0.100:/opt/docker/homeassistant/config/custom_components/tuya_local/devices/
    ssh -i ~/.ssh/id_hungnguyen root@10.0.0.100 docker restart -t 120 homeassistant

Upstream: submit the profile to make/tuya-local (devices/ plus a line in DEVICES.md) so updates keep it.

## IR/RF remote backup (kept out of git)

The two Tuya IR hubs (1B: IR + RF, 2B: IR only) and their Smart Life remotes (two Toshiba ACs,
two "AC - Fn. Keys" remotes, one RF fan light) are in Home Assistant through the HACS integration
Tuya Smart IR AC. A backup of every remote's key list and learned IR/RF codes, plus the integration
config entries, lives outside this public repo because it contains device IDs and the Tuya secret:

- Wyse: `/opt/docker/homeassistant/config/backups/tuya_ir/` (included in Home Assistant backups)
- Mac: `~/Backups/home-assistant/tuya_ir/`

The ACs use Tuya's Toshiba code library (no learned codes); the Fn Keys and fan light codes are learned
and can be replayed by any IR/RF blaster that accepts raw codes.

## Meter/Protector energy (house meter for Energy Cost Manager)

A Tuya Local update on 2026-11-23 reset `sensor.meter_protector_energy` (profile `smartplugv2_energyv3`, DP 17) to
0/1 Wh deltas, which made the Energy Cost Manager read the house as ~0 and "savings today" negative.
The Tuya Local sensor is now `sensor.meter_protector_energy_raw` (disabled). `sensor.meter_protector_energy` is an
Integration helper (Settings, Devices & services, Helpers) that integrates `sensor.meter_protector_power`
(trapezoidal, Wh, max sub interval 1 min), so Tuya Local updates cannot break it. It starts at 0 Wh.
To rebuild it: add helper "Integral" named "Meter/Protector Energy", source the power sensor, unit prefix none,
time unit hours; if it is named `sensor.<area>_meter_protector_energy`, rename the entity id.
