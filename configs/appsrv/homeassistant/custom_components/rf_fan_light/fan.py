from __future__ import annotations

from typing import Any

from homeassistant.components.fan import FanEntity, FanEntityFeature
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import CONF_NAME
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.helpers.event import async_track_state_change_event

from .const import DOMAIN, PRESETS, SPEED_COUNT
from .controller import FanController


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    async_add_entities([RfFan(entry, hass.data[DOMAIN][entry.entry_id])])


class RfFan(FanEntity):
    _attr_has_entity_name = True
    _attr_name = None
    _attr_should_poll = False
    _attr_assumed_state = True
    _attr_speed_count = SPEED_COUNT
    _attr_preset_modes = list(PRESETS)
    _attr_supported_features = (FanEntityFeature.SET_SPEED | FanEntityFeature.PRESET_MODE | FanEntityFeature.DIRECTION
                                | FanEntityFeature.TURN_ON | FanEntityFeature.TURN_OFF)

    def __init__(self, entry: ConfigEntry, controller: FanController) -> None:
        self._controller = controller
        self._attr_unique_id = f"{entry.entry_id}_fan"
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME]}

    async def async_added_to_hass(self) -> None:
        self.async_on_remove(self._controller.add_listener(self.async_write_ha_state))
        if self._controller.power_switch:
            self.async_on_remove(async_track_state_change_event(
                self.hass, [self._controller.power_switch], lambda _event: self.async_write_ha_state()))

    @property
    def available(self) -> bool:
        return self._controller.available

    @property
    def is_on(self) -> bool:
        return self._controller.is_on

    @property
    def percentage(self) -> int:
        return self._controller.percentage

    @property
    def preset_mode(self) -> str | None:
        return self._controller.preset if self._controller.is_on else None

    @property
    def current_direction(self) -> str:
        return "reverse" if self._controller.reverse else "forward"

    @property
    def extra_state_attributes(self) -> dict[str, Any]:
        return {"timer": self._controller.timer if self._controller.is_on else "none",
                "wall_switch_on": self._controller.powered}

    async def async_turn_on(self, percentage: int | None = None, preset_mode: str | None = None, **kwargs: Any) -> None:
        if preset_mode:
            await self.async_set_preset_mode(preset_mode)
        else:
            await self._controller.async_turn_on(self._speed(percentage))

    async def async_turn_off(self, **kwargs: Any) -> None:
        await self._controller.async_turn_off()

    async def async_set_percentage(self, percentage: int) -> None:
        await self._controller.async_set_speed(self._speed(percentage) or 0)

    async def async_set_preset_mode(self, preset_mode: str) -> None:
        if not self._controller.powered:
            await self._controller.async_turn_on()
            return
        await self._controller.async_set_preset(preset_mode)

    async def async_set_direction(self, direction: str) -> None:
        await self._controller.async_set_direction(direction)

    @staticmethod
    def _speed(percentage: int | None) -> int | None:
        if not percentage:
            return None
        return max(1, -(-percentage * SPEED_COUNT // 100))
