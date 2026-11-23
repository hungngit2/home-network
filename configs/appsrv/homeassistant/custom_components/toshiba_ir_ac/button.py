"""Sync: send the state Home Assistant shows, so the air conditioner matches it again."""

from __future__ import annotations

from homeassistant.components.button import ButtonEntity
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import CONF_NAME, STATE_UNAVAILABLE, STATE_UNKNOWN
from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.entity_platform import AddEntitiesCallback

from .const import DOMAIN


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    async_add_entities([ToshibaIrSync(entry)])


class ToshibaIrSync(ButtonEntity):
    _attr_has_entity_name = True
    _attr_name = "Sync"
    _attr_icon = "mdi:sync"

    def __init__(self, entry: ConfigEntry) -> None:
        self._climate_unique_id = f"{entry.entry_id}_climate"
        self._attr_unique_id = f"{entry.entry_id}_sync"
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME]}

    async def async_press(self) -> None:
        climate_id = er.async_get(self.hass).async_get_entity_id("climate", DOMAIN, self._climate_unique_id)
        state = self.hass.states.get(climate_id) if climate_id else None
        if state is None or state.state in (STATE_UNAVAILABLE, STATE_UNKNOWN):
            return
        await self.hass.services.async_call(
            "climate", "set_hvac_mode", {"entity_id": climate_id, "hvac_mode": state.state}, blocking=True
        )
