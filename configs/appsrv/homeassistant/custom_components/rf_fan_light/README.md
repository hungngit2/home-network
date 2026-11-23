# RF Fan Light (local)

A ceiling fan with a light, controlled over local RF through a Tuya hub's `remote` entity
(Tuya Local). The fan's remote has only keys and no state, so the last command sent is shown.

## Set up

Settings, Devices & services, Add integration, "RF Fan Light (local)". Choose the hub remote,
the device name the codes were learned under (`fan_light`), the optional wall switch that powers the
fan, and the speed to use when turning on. The codes must exist under these command names:
`fan_speed1` to `fan_speed6`, `power`, `natural_wind`, `sleep_wind`, `fan_power` (reverse rotation),
`timer_1h`, `timer_2h`, `timer_4h`, `light_power`.

## What you get

- `fan.<name>`: on/off, 6 speeds, natural and sleep presets, direction. Attribute `timer` shows the timer.
- Buttons Light, Wind (cycles natural, sleep, normal) and Timer (cycles 1h, 2h, 4h).
- With a wall switch: the integration notes when it goes off. The automation "Kitchen fan speed when the
  switch turns on" sends the speed after the fan boots (after 2 s and again after 5 s), using the fan's
  `next_speed` attribute (2 unless you turned the fan on from the card while the switch was off).
  Turning the fan on while the switch is off turns the switch on first.
- There is no key to cancel wind or the timer. Leaving wind resends the speed; the timer only clears on power off.

State is kept in `.storage/rf_fan_light.<entry id>`. Test the logic without Home Assistant: `python3 test_controller.py`.
