# Toshiba IR AC (local)

Controls Toshiba air conditioners through a Tuya IR hub's `remote` entity from Tuya Local.
No Tuya cloud. The AC cannot report back, so the card shows the last command sent.

## Add another Toshiba air conditioner

Settings, Devices & services, Add integration, "Toshiba IR AC (local)". Enter a name and
the hub's remote entity (for example `remote.2b_infrared`), and optionally a room temperature
sensor. One air conditioner per hub remote.

## Use a code you learned (any brand)

Any learned IR code can be sent without this integration. In Developer tools, Actions:

    action: remote.send_command
    target:
      entity_id: remote.2b_infrared
    data:
      command: "b64:<the learned code>"

RF codes start with `rf:` and are sent as they are. Codes saved by name (with `device:`
and `command:`) live in `.storage/tuya_local_remote_<device id>_codes` on the Home Assistant host.

## Learn a code

    action: remote.learn_command
    target:
      entity_id: remote.2b_infrared
    data:
      device: my_ac
      command: [off]
      command_type: ir

Point the real remote at the hub and press the button within 30 seconds.

Known problem on the S11/UR01 hubs here: after the first learn, the hub answers about a second
after learning starts with an empty signal (`AAAAAAAAAAAE`), and Tuya Local stores that instead
of waiting for your button. Check any learned code with `python3 decode_ir.py <code>`:
"Empty or noise" means learn it again; a real Toshiba code prints messages starting `F2 0D`.

## Another brand

A different brand needs its own encoder. Learn a few codes (off, and a few temperature and
mode settings), run `decode_ir.py` on each, and the byte differences show the layout; the
Toshiba layout in `encoder.py` is a worked example. Ask before guessing a layout on a real AC.
