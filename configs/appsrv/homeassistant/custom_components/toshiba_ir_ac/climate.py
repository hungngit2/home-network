from __future__ import annotations

from typing import Any

from homeassistant.components.climate import ClimateEntity, ClimateEntityFeature, HVACMode
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import ATTR_TEMPERATURE, CONF_NAME, STATE_UNAVAILABLE, STATE_UNKNOWN, UnitOfTemperature
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.helpers.restore_state import RestoreEntity

from .const import CONF_REMOTE, CONF_TEMPERATURE_SENSOR, DOMAIN
from .encoder import FIX_FRAME, FLAG_BOOST, FLAG_ECO, MAX_TEMP, MIN_TEMP, SWING_FRAME, encode, short_bytes, state_bytes

FAN_TO_SPEED = {"auto": "auto", "low": "1", "medium": "3", "high": "5"}
HVAC_TO_MODE = {
    HVACMode.AUTO: "auto",
    HVACMode.COOL: "cool",
    HVACMode.DRY: "dry",
    HVACMode.FAN_ONLY: "fan_only",
}
PRESET_FLAGS = {"none": None, "eco": FLAG_ECO, "boost": FLAG_BOOST}


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry, async_add_entities: AddEntitiesCallback) -> None:
    entity = ToshibaIrClimate(entry)
    hass.data.setdefault(DOMAIN, {})[entry.entry_id] = entity
    async_add_entities([entity])


class ToshibaIrClimate(ClimateEntity, RestoreEntity):
    _attr_has_entity_name = True
    _attr_name = None
    _attr_assumed_state = True
    _attr_should_poll = False
    _attr_native_temperature_unit = UnitOfTemperature.CELSIUS
    _attr_min_temp = MIN_TEMP
    _attr_max_temp = MAX_TEMP
    _attr_target_temperature_step = 1
    _attr_hvac_modes = [HVACMode.OFF, HVACMode.COOL, HVACMode.DRY, HVACMode.FAN_ONLY, HVACMode.AUTO]
    _attr_fan_modes = list(FAN_TO_SPEED)
    _attr_preset_modes = list(PRESET_FLAGS)
    _attr_swing_modes = ["off", "on"]
    _attr_supported_features = (
        ClimateEntityFeature.TARGET_TEMPERATURE
        | ClimateEntityFeature.FAN_MODE
        | ClimateEntityFeature.PRESET_MODE
        | ClimateEntityFeature.SWING_MODE
        | ClimateEntityFeature.TURN_ON
        | ClimateEntityFeature.TURN_OFF
    )

    def __init__(self, entry: ConfigEntry) -> None:
        self._remote = entry.data[CONF_REMOTE]
        self._sensor = entry.data.get(CONF_TEMPERATURE_SENSOR)
        self._attr_unique_id = f"{entry.entry_id}_climate"
        self._attr_device_info = {"identifiers": {(DOMAIN, entry.entry_id)}, "name": entry.data[CONF_NAME],
                                  "manufacturer": "Toshiba", "model": "IR air conditioner"}
        self._attr_hvac_mode = HVACMode.OFF
        self._attr_native_target_temperature = 26
        self._attr_fan_mode = "auto"
        self._attr_preset_mode = "none"
        self._attr_swing_mode = "off"
        self._last_mode = HVACMode.COOL

    async def async_added_to_hass(self) -> None:
        if (last := await self.async_get_last_state()) is None:
            return
        if last.state in self.hvac_modes:
            self._attr_hvac_mode = HVACMode(last.state)
            if self._attr_hvac_mode != HVACMode.OFF:
                self._last_mode = self._attr_hvac_mode
        attrs = last.attributes
        if (temp := attrs.get(ATTR_TEMPERATURE)) is not None:
            self._attr_native_target_temperature = int(temp)
        if attrs.get("fan_mode") in FAN_TO_SPEED:
            self._attr_fan_mode = attrs["fan_mode"]
        if attrs.get("preset_mode") in PRESET_FLAGS:
            self._attr_preset_mode = attrs["preset_mode"]
        if attrs.get("swing_mode") in self._attr_swing_modes:
            self._attr_swing_mode = attrs["swing_mode"]

    @property
    def native_current_temperature(self) -> float | None:
        if not self._sensor or (state := self.hass.states.get(self._sensor)) is None:
            return None
        if state.state in (STATE_UNAVAILABLE, STATE_UNKNOWN):
            return None
        try:
            return float(state.state)
        except ValueError:
            return None

    async def _send(self, message: list[int]) -> None:
        await self.hass.services.async_call(
            "remote", "send_command", {"entity_id": self._remote, "command": [f"b64:{encode(message)}"]}, blocking=True
        )

    async def _send_state(self) -> None:
        mode = "off" if self._attr_hvac_mode == HVACMode.OFF else HVAC_TO_MODE[self._attr_hvac_mode]
        fan = "auto" if mode == "off" else FAN_TO_SPEED[self._attr_fan_mode]
        flags = None if mode == "off" else PRESET_FLAGS[self._attr_preset_mode]
        await self._send(state_bytes(int(self._attr_native_target_temperature), mode, fan, flags))

    async def async_set_hvac_mode(self, hvac_mode: HVACMode) -> None:
        self._attr_hvac_mode = hvac_mode
        if hvac_mode != HVACMode.OFF:
            self._last_mode = hvac_mode
        else:
            self._attr_preset_mode = "none"
        await self._send_state()
        self.async_write_ha_state()

    async def async_turn_on(self) -> None:
        await self.async_set_hvac_mode(self._last_mode)

    async def async_turn_off(self) -> None:
        await self.async_set_hvac_mode(HVACMode.OFF)

    async def async_set_temperature(self, **kwargs: Any) -> None:
        if (mode := kwargs.get("hvac_mode")) is not None:
            self._attr_hvac_mode = HVACMode(mode)
            if self._attr_hvac_mode != HVACMode.OFF:
                self._last_mode = self._attr_hvac_mode
        if (temp := kwargs.get(ATTR_TEMPERATURE)) is not None:
            self._attr_native_target_temperature = max(MIN_TEMP, min(MAX_TEMP, round(temp)))
            if self._attr_hvac_mode == HVACMode.OFF:
                # Like the real remote: choosing a temperature switches the unit on in cool.
                self._attr_hvac_mode = self._last_mode = HVACMode.COOL
        if self._attr_hvac_mode != HVACMode.OFF:
            await self._send_state()
        self.async_write_ha_state()

    async def async_set_fan_mode(self, fan_mode: str) -> None:
        self._attr_fan_mode = fan_mode
        if self._attr_hvac_mode != HVACMode.OFF:
            await self._send_state()
        self.async_write_ha_state()

    async def async_set_preset_mode(self, preset_mode: str) -> None:
        self._attr_preset_mode = preset_mode
        if self._attr_hvac_mode == HVACMode.OFF:
            self._attr_hvac_mode = self._last_mode
        await self._send_state()
        self.async_write_ha_state()

    async def async_set_swing_mode(self, swing_mode: str) -> None:
        self._attr_swing_mode = swing_mode
        await self._send(short_bytes(SWING_FRAME if swing_mode == "on" else FIX_FRAME))
        self.async_write_ha_state()
