"""A ceiling fan with a light, controlled locally by RF through a Tuya hub's remote entity."""

from __future__ import annotations

from homeassistant.config_entries import ConfigEntry
from homeassistant.const import Platform
from homeassistant.core import Event, HomeAssistant, callback
from homeassistant.helpers.event import async_track_state_change_event
from homeassistant.helpers.storage import Store

from .const import CONF_DEFAULT_SPEED, CONF_DEVICE, CONF_POWER_SWITCH, CONF_REMOTE, DOMAIN
from .controller import FanController

PLATFORMS = [Platform.FAN, Platform.BUTTON]


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    data = entry.data
    controller = FanController(
        hass, Store(hass, 1, f"{DOMAIN}.{entry.entry_id}"), data[CONF_REMOTE], data[CONF_DEVICE],
        data.get(CONF_POWER_SWITCH) or None, int(data.get(CONF_DEFAULT_SPEED, 3)))
    await controller.async_load()
    hass.data.setdefault(DOMAIN, {})[entry.entry_id] = controller

    if controller.power_switch:
        @callback
        def _switch_changed(event: Event) -> None:
            old, new = event.data.get("old_state"), event.data.get("new_state")
            controller.power_switch_changed(old.state if old else None, new.state if new else None)

        entry.async_on_unload(async_track_state_change_event(hass, [controller.power_switch], _switch_changed))

    await hass.config_entries.async_forward_entry_setups(entry, PLATFORMS)
    return True


async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    unloaded = await hass.config_entries.async_unload_platforms(entry, PLATFORMS)
    if unloaded:
        hass.data.get(DOMAIN, {}).pop(entry.entry_id, None)
    return unloaded
