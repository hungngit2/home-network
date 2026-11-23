"""Remote-style buttons: power, mode and fan speed cycle like the keys on the real remote, plus Sync."""

from __future__ import annotations

from homeassistant.components.button import ButtonEntity
from homeassistant.components.climate import HVACMode
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import CONF_NAME, STATE_UNAVAILABLE, STATE_UNKNOWN
from homeassistant.core import HomeAssistant
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.entity_platform import AddEntitiesCallback

from .const import DOMAIN

MODE_CYCLE = [HVACMode.AUTO, HVACMode.COOL, HVACMode.DRY, HVACMode.FAN_ONLY]
FAN_CYCLE = ["auto", "low", "medium", "high"]


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    async_add_entities([ToshibaIrSync(entry), ToshibaIrPower(entry), ToshibaIrNextMode(entry), ToshibaIrNextFan(entry)])


class _ToshibaIrButton(ButtonEntity):
    _attr_has_entity_name = True
    _key = ""

    def __init__(self, entry: ConfigEntry) -> None:
        self._entry_id = entry.entry_id
        self._attr_unique_id = f"{entry.entry_id}_{self._key}"
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME]}

    @property
    def _climate(self):  # the climate entity object, so taps in a row see each other's assumed state
        return self.hass.data[DOMAIN][self._entry_id]


class ToshibaIrSync(_ToshibaIrButton):
    """Send the state Home Assistant shows, so the air conditioner matches it again."""

    _key = "sync"
    _attr_name = "Sync"
    _attr_icon = "mdi:sync"

    async def async_press(self) -> None:
        climate_id = er.async_get(self.hass).async_get_entity_id("climate", DOMAIN, f"{self._entry_id}_climate")
        state = self.hass.states.get(climate_id) if climate_id else None
        if state is None or state.state in (STATE_UNAVAILABLE, STATE_UNKNOWN):
            return
        await self.hass.services.async_call(
            "climate", "set_hvac_mode", {"entity_id": climate_id, "hvac_mode": state.state}, blocking=True
        )


class ToshibaIrPower(_ToshibaIrButton):
    """Off to cool, anything else to off."""

    _key = "power"
    _attr_name = "Power"
    _attr_icon = "mdi:power"

    async def async_press(self) -> None:
        climate = self._climate
        await climate.async_set_hvac_mode(HVACMode.COOL if climate.hvac_mode == HVACMode.OFF else HVACMode.OFF)


class ToshibaIrNextMode(_ToshibaIrButton):
    """auto, cool, dry, fan only, then round again. Does nothing while off."""

    _key = "next_mode"
    _attr_name = "Next mode"
    _attr_icon = "mdi:thermostat-auto"

    async def async_press(self) -> None:
        climate = self._climate
        if climate.hvac_mode == HVACMode.OFF:
            return
        index = MODE_CYCLE.index(climate.hvac_mode) if climate.hvac_mode in MODE_CYCLE else 1
        await climate.async_set_hvac_mode(MODE_CYCLE[(index + 1) % len(MODE_CYCLE)])


class ToshibaIrNextFan(_ToshibaIrButton):
    """auto, low, medium, high, then round again. Does nothing while off."""

    _key = "next_fan"
    _attr_name = "Next fan"
    _attr_icon = "mdi:fan"

    async def async_press(self) -> None:
        climate = self._climate
        if climate.hvac_mode == HVACMode.OFF:
            return
        index = FAN_CYCLE.index(climate.fan_mode) if climate.fan_mode in FAN_CYCLE else 0
        await climate.async_set_fan_mode(FAN_CYCLE[(index + 1) % len(FAN_CYCLE)])
