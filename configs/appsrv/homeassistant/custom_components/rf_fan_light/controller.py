"""State and RF commands for one ceiling fan with a light.

The fan's RF remote only has keys (no state), so the last command sent is the state. It is kept in a
Store so a restart does not forget it. An optional wall switch cuts the fan's power; the integration only
notes when it goes off. Sending the speed after it comes back on is left to an automation.
"""

from __future__ import annotations

from typing import Any, Callable

from .const import PRESETS, SPEED_COUNT, TIMERS

NONE = "none"


class FanController:
    def __init__(self, hass, store, remote: str, device: str, power_switch: str | None, default_speed: int) -> None:
        self.hass = hass
        self._store = store
        self.remote = remote
        self.device = device
        self.power_switch = power_switch
        self.default_speed = default_speed
        self.speed = 0
        self.preset: str | None = None
        self.timer = NONE
        self.reverse = False
        self.running = False
        self.next_speed = default_speed
        self._listeners: list[Callable[[], None]] = []

    async def async_load(self) -> None:
        data = await self._store.async_load() or {}
        self.speed = int(data.get("speed", 0))
        self.preset = data.get("preset")
        self.timer = data.get("timer", NONE)
        self.reverse = bool(data.get("reverse", False))
        self.running = bool(data.get("running", False))

    def add_listener(self, listener: Callable[[], None]) -> Callable[[], None]:
        self._listeners.append(listener)
        return lambda: self._listeners.remove(listener)

    def _changed(self) -> None:
        self._store.async_delay_save(self._snapshot, 1)
        for listener in list(self._listeners):
            listener()

    def _snapshot(self) -> dict[str, Any]:
        return {"speed": self.speed, "preset": self.preset, "timer": self.timer,
                "reverse": self.reverse, "running": self.running}

    @property
    def powered(self) -> bool:
        if not self.power_switch:
            return True
        state = self.hass.states.get(self.power_switch)
        return state is not None and state.state == "on"

    @property
    def available(self) -> bool:
        if not self.power_switch:
            return True
        state = self.hass.states.get(self.power_switch)
        return state is not None and state.state not in ("unavailable", "unknown")

    @property
    def is_on(self) -> bool:
        return self.powered and self.running

    @property
    def percentage(self) -> int:
        return round(self.speed * 100 / SPEED_COUNT) if self.is_on else 0

    async def _send(self, command: str) -> None:
        await self.hass.services.async_call(
            "remote", "send_command", {"entity_id": self.remote, "device": self.device, "command": command},
            blocking=True)

    async def _send_speed(self, speed: int) -> None:
        await self._send(f"fan_speed{speed}")

    async def async_turn_on(self, speed: int | None = None) -> None:
        speed = min(max(speed or self.default_speed, 1), SPEED_COUNT)
        if not self.powered:
            self.next_speed = speed
            await self.hass.services.async_call("switch", "turn_on", {"entity_id": self.power_switch}, blocking=True)
            return
        await self._send_speed(speed)
        self.speed, self.preset, self.running = speed, None, True
        self.next_speed = self.default_speed
        self._changed()

    async def async_turn_off(self) -> None:
        await self._send("power")
        self.running, self.timer, self.preset = False, NONE, None
        self._changed()

    async def async_set_speed(self, speed: int) -> None:
        if speed <= 0:
            await self.async_turn_off()
        else:
            await self.async_turn_on(speed)

    async def async_set_preset(self, preset: str) -> None:
        if preset not in PRESETS:
            raise ValueError(f"Unknown preset {preset}")
        await self._send(f"{preset}_wind")
        self.preset, self.running = preset, True
        self._changed()

    async def async_set_direction(self, direction: str) -> None:
        if (direction == "reverse") != self.reverse:
            await self._send("fan_power")
            self.reverse = not self.reverse
            self._changed()

    async def async_cycle_wind(self) -> None:
        order = (None, *PRESETS)
        following = order[(order.index(self.preset) + 1) % len(order)] if self.preset in order else PRESETS[0]
        if following is None:
            # The remote has no "cancel wind" key; sending the speed again returns to normal wind.
            await self.async_turn_on(max(self.speed, 1))
        else:
            await self.async_set_preset(following)

    async def async_cycle_timer(self) -> None:
        order = (NONE, *TIMERS)
        following = TIMERS[0] if self.timer == TIMERS[-1] or self.timer not in order else order[order.index(self.timer) + 1]
        await self._send(f"timer_{following}")
        self.timer = following
        self._changed()

    async def async_toggle_light(self) -> None:
        await self._send("light_power")

    def power_switch_changed(self, old: str | None, new: str | None) -> None:
        if new == "off" and old == "on":
            self.running, self.timer, self.preset = False, NONE, None
            self._changed()
