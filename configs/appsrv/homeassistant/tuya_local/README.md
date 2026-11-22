# Tuya Local profiles not (yet) in upstream

`reclosing_protector_breaker.yaml`: the kitchen IH Cooker breaker, Tuya product `ajxxvizkghnasyxi`
("Smart Reclosing Protector", category cz). DPs: 101 switch, 106 current (0.1 A), 107 energy
(0.1 kWh), 108 voltage, 109 leakage mA, 26 fault bitmap, 110/114-117 protection limits, 111 mute.

A HACS update of Tuya Local deletes files it does not ship. After updating, restore and restart:

    scp -i ~/.ssh/id_hungnguyen configs/appsrv/homeassistant/tuya_local/*.yaml \
      root@10.0.0.100:/opt/docker/homeassistant/config/custom_components/tuya_local/devices/
    ssh -i ~/.ssh/id_hungnguyen root@10.0.0.100 docker restart -t 120 homeassistant

Upstream: submit the profile to make/tuya-local (devices/ plus a line in DEVICES.md) so updates keep it.
