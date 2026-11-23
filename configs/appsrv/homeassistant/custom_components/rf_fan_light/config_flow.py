from __future__ import annotations

from typing import Any

import voluptuous as vol
from homeassistant.config_entries import ConfigFlow, ConfigFlowResult
from homeassistant.const import CONF_NAME
from homeassistant.helpers import selector

from .const import CONF_DEFAULT_SPEED, CONF_DEVICE, CONF_POWER_SWITCH, CONF_REMOTE, DOMAIN


class RfFanLightConfigFlow(ConfigFlow, domain=DOMAIN):
    VERSION = 1

    async def async_step_user(self, user_input: dict[str, Any] | None = None) -> ConfigFlowResult:
        if user_input is not None:
            await self.async_set_unique_id(f"{user_input[CONF_REMOTE]}:{user_input[CONF_DEVICE]}")
            self._abort_if_unique_id_configured()
            return self.async_create_entry(title=user_input[CONF_NAME], data=user_input)
        schema = vol.Schema(
            {
                vol.Required(CONF_NAME, default="Kitchen Fan"): str,
                vol.Required(CONF_REMOTE): selector.EntitySelector(selector.EntitySelectorConfig(domain="remote")),
                vol.Required(CONF_DEVICE, default="fan_light"): str,
                vol.Optional(CONF_POWER_SWITCH): selector.EntitySelector(selector.EntitySelectorConfig(domain="switch")),
                vol.Required(CONF_DEFAULT_SPEED, default=3): selector.NumberSelector(
                    selector.NumberSelectorConfig(min=1, max=6, step=1, mode=selector.NumberSelectorMode.BOX)),
            }
        )
        return self.async_show_form(step_id="user", data_schema=schema)
