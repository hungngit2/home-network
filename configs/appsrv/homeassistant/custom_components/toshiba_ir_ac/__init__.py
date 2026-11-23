"""Toshiba air conditioners controlled locally through a Tuya IR hub's remote entity."""

from __future__ import annotations

from homeassistant.config_entries import ConfigEntry
from homeassistant.const import Platform
from homeassistant.core import HomeAssistant

from .const import DOMAIN

PLATFORMS = [Platform.CLIMATE, Platform.SWITCH, Platform.NUMBER, Platform.BUTTON]


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    # The toggles look their climate entity up in the registry, so it has to exist first.
    await hass.config_entries.async_forward_entry_setups(entry, [Platform.CLIMATE])
    await hass.config_entries.async_forward_entry_setups(entry, [Platform.SWITCH, Platform.NUMBER, Platform.BUTTON])
    return True


async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    unloaded = await hass.config_entries.async_unload_platforms(entry, PLATFORMS)
    if unloaded:
        hass.data.get(DOMAIN, {}).pop(entry.entry_id, None)
    return unloaded
