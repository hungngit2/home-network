"""A temperature slider that follows the air conditioner's climate entity."""

from __future__ import annotations

from homeassistant.components.climate import ATTR_TEMPERATURE
from homeassistant.components.number import NumberDeviceClass, NumberEntity, NumberMode
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import CONF_NAME, STATE_UNAVAILABLE, STATE_UNKNOWN, UnitOfTemperature
from homeassistant.core import Event, EventStateChangedData, HomeAssistant, callback
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.helpers.event import async_track_state_change_event

from .const import DOMAIN
from .encoder import MAX_TEMP, MIN_TEMP


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    async_add_entities([ToshibaIrTemperature(entry)])


class ToshibaIrTemperature(NumberEntity):
    _attr_has_entity_name = True
    _attr_name = "Temperature"
    _attr_should_poll = False
    _attr_assumed_state = True
    _attr_icon = "mdi:thermometer"
    _attr_device_class = NumberDeviceClass.TEMPERATURE
    _attr_mode = NumberMode.SLIDER
    _attr_native_min_value = MIN_TEMP
    _attr_native_max_value = MAX_TEMP
    _attr_native_step = 1
    _attr_native_unit_of_measurement = UnitOfTemperature.CELSIUS

    def __init__(self, entry: ConfigEntry) -> None:
        self._climate_unique_id = f"{entry.entry_id}_climate"
        self._climate_id: str | None = None
        self._attr_unique_id = f"{entry.entry_id}_temperature"
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME]}

    async def async_added_to_hass(self) -> None:
        self._climate_id = er.async_get(self.hass).async_get_entity_id("climate", DOMAIN, self._climate_unique_id)
        if self._climate_id:
            self.async_on_remove(async_track_state_change_event(self.hass, [self._climate_id], self._climate_changed))

    @callback
    def _climate_changed(self, _event: Event[EventStateChangedData]) -> None:
        self.async_write_ha_state()

    @property
    def native_value(self) -> float | None:
        state = self.hass.states.get(self._climate_id) if self._climate_id else None
        if state is None or state.state in (STATE_UNAVAILABLE, STATE_UNKNOWN):
            return None
        return state.attributes.get(ATTR_TEMPERATURE)

    async def async_set_native_value(self, value: float) -> None:
        await self.hass.services.async_call(
            "climate", "set_temperature", {"entity_id": self._climate_id, ATTR_TEMPERATURE: value}, blocking=True
        )
