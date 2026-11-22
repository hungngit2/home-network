# Tuya IR/RF remote backup (2026-11-22)

- `tuya-ir-remotes-*.json`: both IR hubs' Smart Life remotes, their key lists and learned codes, read from the Tuya cloud.
  The Toshiba ACs use Tuya's code library (remote_index 11867); the "AC - Fn. Keys" remotes (Eco, Hi Power, Fix, Swing)
  and the 1B RF fan light (16 keys, 433 MHz) are learned codes.
- `config-entries-*.json`: Home Assistant config entries for Tuya Smart IR AC and the two hubs in Tuya Local.

Keys are removed (Tuya Access ID/Secret, hub local keys show as REDACTED). The unredacted copy is kept privately on the
Wyse (`/opt/docker/homeassistant/config/backups/tuya_ir/`) and the Mac (`~/Backups/home-assistant/tuya_ir/`).
