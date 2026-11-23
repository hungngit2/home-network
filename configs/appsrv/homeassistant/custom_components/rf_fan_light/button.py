from __future__ import annotations

from homeassistant.components.button import ButtonEntity
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import CONF_NAME
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback

from .const import DOMAIN
from .controller import FanController

KEYS = {  # key, name, icon, controller method
    "light": ("Light", "mdi:lightbulb", "async_toggle_light"),
    "wind": ("Wind", "mdi:weather-windy", "async_cycle_wind"),
    "timer": ("Timer", "mdi:timer-outline", "async_cycle_timer"),
}


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    controller = hass.data[DOMAIN][entry.entry_id]
    async_add_entities([RfFanKey(entry, controller, key) for key in KEYS])


class RfFanKey(ButtonEntity):
    _attr_has_entity_name = True
    _attr_should_poll = False

    def __init__(self, entry: ConfigEntry, controller: FanController, key: str) -> None:
        name, icon, method = KEYS[key]
        self._controller = controller
        self._method = method
        self._attr_name = name
        self._attr_icon = icon
        self._attr_unique_id = f"{entry.entry_id}_{key}"
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME]}

    async def async_press(self) -> None:
        await getattr(self._controller, self._method)()
