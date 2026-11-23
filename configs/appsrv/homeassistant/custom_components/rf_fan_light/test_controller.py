"""Run: python3 test_controller.py  (no Home Assistant needed; the hub and wall switch are faked)."""
import asyncio
import importlib.util
import pathlib
import sys
import types

root = pathlib.Path(__file__).parent
pkg = types.ModuleType("rf_fan_light")
pkg.__path__ = [str(root)]
sys.modules["rf_fan_light"] = pkg
for name in ("const", "controller"):
    spec = importlib.util.spec_from_file_location(f"rf_fan_light.{name}", root / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    sys.modules[f"rf_fan_light.{name}"] = mod
    spec.loader.exec_module(mod)
from rf_fan_light.controller import FanController  # noqa: E402


class State:
    def __init__(self, state):
        self.state = state


class Services:
    def __init__(self, hass):
        self.hass = hass

    async def async_call(self, domain, service, data, blocking=False):
        if domain == "remote":
            self.hass.sent.append(data["command"])
        elif (domain, service) == ("switch", "turn_on"):
            self.hass.switch = "on"
            self.hass.switch_turned_on = True


class Hass:
    def __init__(self, switch="on"):
        self.sent, self.switch, self.switch_turned_on = [], switch, False
        self.services = Services(self)
        self.states = types.SimpleNamespace(get=lambda _id: State(self.switch))

    def async_create_task(self, coro):
        return asyncio.ensure_future(coro)


class Store:
    async def async_load(self):
        return None

    def async_delay_save(self, *_):
        pass


def make(switch="on"):
    hass = Hass(switch)
    return hass, FanController(hass, Store(), "remote.hub", "fan_light", "switch.wall", 3)


async def main():
    hass, f = make("on")
    await f.async_turn_on()
    assert hass.sent == ["fan_speed3"] and f.percentage == 50 and f.is_on, (hass.sent, f.percentage)

    await f.async_set_speed(6)
    assert hass.sent[-1] == "fan_speed6" and f.percentage == 100

    await f.async_cycle_wind()
    await f.async_cycle_wind()
    assert hass.sent[-2:] == ["natural_wind", "sleep_wind"] and f.preset == "sleep"
    await f.async_cycle_wind()
    assert hass.sent[-1] == "fan_speed6" and f.preset is None, "leaving wind resends the current speed"

    await f.async_cycle_timer(); await f.async_cycle_timer(); await f.async_cycle_timer(); await f.async_cycle_timer()
    assert hass.sent[-4:] == ["timer_1h", "timer_2h", "timer_4h", "timer_1h"] and f.timer == "1h"

    await f.async_set_direction("reverse"); await f.async_set_direction("reverse")
    assert hass.sent.count("fan_power") == 1 and f.reverse

    await f.async_toggle_light()
    assert hass.sent[-1] == "light_power"

    await f.async_set_speed(0)
    assert hass.sent[-1] == "power" and not f.is_on and f.timer == "none" and f.percentage == 0

    hass, f = make("off")
    await f.async_turn_on(5)
    assert hass.switch_turned_on and f.next_speed == 5 and hass.sent == [], "wall switch off: only switch on, no RF yet"
    await f.async_turn_on(f.next_speed)
    assert hass.sent == ["fan_speed5"] and f.speed == 5 and f.is_on
    assert f.next_speed == 3, "next switch-on goes back to the default speed"

    hass.switch = "off"
    f.power_switch_changed("on", "off")
    assert not f.is_on and not f.running

    print("all controller checks passed")


asyncio.run(main())
