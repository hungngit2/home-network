"""Eco, boost and swing as toggles that follow the air conditioner's climate entity."""

from __future__ import annotations

from typing import Any

from homeassistant.components.climate import ATTR_PRESET_MODE, ATTR_SWING_MODE
from homeassistant.components.switch import SwitchEntity
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import CONF_NAME, STATE_OFF, STATE_UNAVAILABLE, STATE_UNKNOWN
from homeassistant.core import Event, EventStateChangedData, HomeAssistant, callback
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.helpers.event import async_track_state_change_event

from .const import DOMAIN

TOGGLES = (
    ("eco", "Eco", "mdi:leaf", ATTR_PRESET_MODE, "eco", "none", "set_preset_mode"),
    ("boost", "Boost", "mdi:flash", ATTR_PRESET_MODE, "boost", "none", "set_preset_mode"),
    ("swing", "Swing", "mdi:arrow-oscillating", ATTR_SWING_MODE, "on", "off", "set_swing_mode"),
)


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    async_add_entities([ToshibaIrToggle(entry, *toggle) for toggle in TOGGLES])


class ToshibaIrToggle(SwitchEntity):
    _attr_has_entity_name = True
    _attr_should_poll = False
    _attr_assumed_state = True

    def __init__(
        self, entry: ConfigEntry, key: str, name: str, icon: str, attribute: str, on: str, off: str, service: str
    ) -> None:
        self._attribute, self._on, self._off, self._service = attribute, on, off, service
        self._climate_unique_id = f"{entry.entry_id}_climate"
        self._climate_id: str | None = None
        self._attr_unique_id = f"{entry.entry_id}_{key}"
        self._attr_name = name
        self._attr_icon = icon
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME]}

    async def async_added_to_hass(self) -> None:
        self._climate_id = er.async_get(self.hass).async_get_entity_id("climate", DOMAIN, self._climate_unique_id)
        if self._climate_id:
            self.async_on_remove(async_track_state_change_event(self.hass, [self._climate_id], self._climate_changed))

    @callback
    def _climate_changed(self, _event: Event[EventStateChangedData]) -> None:
        self.async_write_ha_state()

    @property
    def available(self) -> bool:
        state = self.hass.states.get(self._climate_id) if self._climate_id else None
        return state is not None and state.state not in (STATE_OFF, STATE_UNAVAILABLE, STATE_UNKNOWN)

    @property
    def is_on(self) -> bool:
        state = self.hass.states.get(self._climate_id) if self._climate_id else None
        return state is not None and state.attributes.get(self._attribute) == self._on

    async def _call(self, value: str) -> None:
        await self.hass.services.async_call(
            "climate", self._service, {"entity_id": self._climate_id, self._attribute: value}, blocking=True
        )

    async def async_turn_on(self, **kwargs: Any) -> None:
        await self._call(self._on)

    async def async_turn_off(self, **kwargs: Any) -> None:
        await self._call(self._off)
