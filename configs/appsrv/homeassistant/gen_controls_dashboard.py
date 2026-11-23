"""Generate the "Controls" dashboard (sidebar item, url_path house-controls).

Usage: python3 gen_controls_dashboard.py lovelace.house-controls.json
Push it with the websocket commands lovelace/dashboards/create and lovelace/config/save.
Breakers, the grid protector, the network relay and inverter switches are deliberately not here.
"""
import json
import sys

OUT = sys.argv[1]
NONE = {"action": "none"}
MORE = {"action": "more-info"}


def heading(text, icon):
    return {"type": "heading", "heading": text, "heading_style": "title", "icon": icon}


def press(entity):
    return {"action": "perform-action", "perform_action": "button.press", "target": {"entity_id": entity}}


def chip(text, icon, entity):
    return {"type": "template", "content": text, "icon": icon, "tap_action": press(entity)}


def active(preset):
    return "{{ 'cyan' if is_state('input_select.kitchen_fan_preset', '%s') else 'grey' }}" % preset


def preset_chip(text, icon, preset):
    return {"type": "template", "content": text, "icon": icon, "icon_color": active(preset),
            "tap_action": {"action": "perform-action", "perform_action": "fan.set_preset_mode",
                           "target": {"entity_id": "fan.kitchen_fan"}, "data": {"preset_mode": preset}}}


def chips(*items):
    return {"type": "custom:mushroom-chips-card", "alignment": "center", "chips": list(items),
            "grid_options": {"columns": 12}}


def toggle_tile(entity, name, icon, color, **kw):
    return {"type": "tile", "entity": entity, "name": name, "icon": icon, "color": color,
            "tap_action": {"action": "toggle"}, "icon_tap_action": MORE, **kw}


def available(entity):
    return [{"condition": "state", "entity": entity, "state_not": "unavailable"}]


kitchen = {"type": "grid", "cards": [
    heading("Kitchen", "mdi:silverware-fork-knife"),
    {"type": "custom:mushroom-fan-card", "entity": "fan.kitchen_fan", "name": "Fan",
     "secondary_info": "state", "icon_animation": True, "fill_container": True,
     "show_percentage_control": True, "show_direction_control": True, "collapsible_controls": False,
     "tap_action": {"action": "toggle"}, "hold_action": MORE, "grid_options": {"columns": 12}},
    chips(preset_chip("Natural", "mdi:weather-windy", "natural"),
          preset_chip("Sleep", "mdi:weather-night", "sleep"),
          chip("Light", "mdi:lightbulb", "button.1b_fan_light_light_on_off"),
          chip("Off", "mdi:power", "button.1b_fan_light_power_off")),
    chips(chip("1h", "mdi:timer-outline", "button.1b_fan_light_timer_1h"),
          chip("2h", "mdi:timer-outline", "button.1b_fan_light_timer_2h"),
          chip("4h", "mdi:timer-outline", "button.1b_fan_light_timer_4h")),
]}


def toggle(entity, name, icon, color):
    return {"type": "tile", "entity": entity, "name": name, "icon": icon, "color": color, "vertical": True,
            "tap_action": {"action": "toggle"}, "icon_tap_action": {"action": "toggle"},
            "grid_options": {"columns": 4}}


def aircon(floor, icon, entity, slug):
    return {"type": "grid", "cards": [
        heading(f"{floor} air conditioner", icon),
        {"type": "thermostat", "entity": entity,
         "features": [{"type": "climate-hvac-modes", "style": "icons"},
                      {"type": "climate-fan-modes", "style": "icons"}]},
        toggle(f"switch.{slug}_eco", "Eco", "mdi:leaf", "green"),
        toggle(f"switch.{slug}_boost", "Boost", "mdi:flash", "orange"),
        toggle(f"switch.{slug}_swing", "Swing", "mdi:arrow-oscillating", "blue"),
    ]}


floor1 = aircon("Floor 1", "mdi:home-floor-1", "climate.floor_1_ac", "floor_1_ac")
floor2 = aircon("Floor 2", "mdi:home-floor-2", "climate.floor_2_ac", "floor_2_ac")

outside = {"type": "grid", "cards": [
    heading("Outside", "mdi:tree-outline"),
    toggle_tile("switch.sonoff_10014c209d_1", "Front light", "mdi:outdoor-lamp", "amber"),
    toggle_tile("switch.sonoff_1000eff333", "Irrigation", "mdi:sprinkler-variant", "cyan"),
    toggle_tile("switch.sonoff_10014c1f48_1", "Water pump", "mdi:water-pump", "blue",
                visibility=available("switch.sonoff_10014c1f48_1")),
]}

plugs = {"type": "grid", "cards": [
    heading("Plugs", "mdi:power-socket-eu"),
    toggle_tile("switch.sac_xe", "Bike charger", "mdi:bicycle-electric", "green"),
    toggle_tile("switch.mosquito_liquid", "Mosquito repellent", "mdi:spray", "purple"),
]}

note = {"type": "grid", "column_span": 3, "cards": [
    {"type": "markdown", "content":
        "Air conditioners and the fan use infrared or radio remotes, which cannot report back. "
        "They show the last command sent from Home Assistant, so a press on the wall remote is not seen."},
]}

config = {"title": "Controls", "views": [
    {"title": "Controls", "path": "controls", "icon": "mdi:remote", "type": "sections", "max_columns": 3,
     "sections": [kitchen, floor1, floor2, outside, plugs, note]},
]}
json.dump(config, open(OUT, "w"), indent=2, ensure_ascii=False)
json.load(open(OUT))
print("sections:", len(config["views"][0]["sections"]))
