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


def toggle_tile(entity, name, icon, color, **kw):
    return {"type": "tile", "entity": entity, "name": name, "icon": icon, "color": color,
            "tap_action": {"action": "toggle"}, "icon_tap_action": MORE, **kw}


def available(entity):
    return [{"condition": "state", "entity": entity, "state_not": "unavailable"}]


FLAT = {"style": "ha-card { background: none; border: none; box-shadow: none; border-radius: 0; }"}
SURFACE = {"style": """:host {
  --vertical-stack-card-gap: 0px;
  --horizontal-stack-card-gap: 0px;
  display: block; overflow: hidden;
  background: var(--ha-card-background, var(--card-background-color));
  border: var(--ha-card-border-width, 1px) solid var(--ha-card-border-color, var(--divider-color));
  border-radius: var(--ha-card-border-radius, 12px);
  box-shadow: var(--ha-card-box-shadow, none);
}"""}


def panel(*cards):
    """One surface for a whole device: the stacked cards lose their own borders."""
    return {"type": "vertical-stack", "grid_options": {"columns": 12}, "card_mod": SURFACE, "cards": list(cards)}


def row(*cards):
    return {"type": "horizontal-stack", "cards": list(cards)}


def key(primary, icon, color, tap, secondary=None, entity=None):
    """A remote-style button: icon on top, label under it."""
    c = {"type": "custom:mushroom-template-card", "layout": "vertical", "fill_container": True,
         "primary": primary, "icon": icon, "icon_color": color, "tap_action": tap, "card_mod": FLAT}
    if secondary:
        c["secondary"] = secondary
    if entity:
        c["entity"] = entity
    return c


def pick(table, source, default):
    """A Jinja lookup such as {'cool': 'Cool'}.get(source, default)."""
    body = ", ".join(f"'{k}': '{v}'" for k, v in table.items())
    return "{{ {%s}.get(%s, '%s') }}" % (body, source, default)


def when(entity, state, yes, no):
    return "{{ '%s' if is_state('%s', '%s') else '%s' }}" % (yes, entity, state, no)


def slider(slug, off):
    return {"type": "conditional",
            "conditions": [{"condition": "state", "entity": f"climate.{slug}", "state": "off"}] if off else
            [{"condition": "state", "entity": f"climate.{slug}", "state_not": "off"}],
            "card": {"type": "custom:mushroom-number-card", "entity": f"number.{slug}_temperature",
                     "name": "Off · slide to cool" if off else "Temperature",
                     "icon": "mdi:thermometer-off" if off else "mdi:thermometer",
                     "icon_color": "disabled" if off else "blue", "display_mode": "slider",
                     "primary_info": "name", "secondary_info": "state", "fill_container": True,
                     "tap_action": press(f"button.{slug}_power") if off else NONE, "card_mod": FLAT}}


kitchen = {"type": "grid", "cards": [
    heading("Kitchen", "mdi:silverware-fork-knife"),
    panel(
        {"type": "custom:mushroom-fan-card", "entity": "fan.kitchen_fan", "name": "Fan", "secondary_info": "state",
         "fill_container": True, "icon_animation": True, "show_percentage_control": True,
         "show_direction_control": True, "collapsible_controls": False, "tap_action": {"action": "toggle"},
         "hold_action": MORE, "card_mod": FLAT},
        row(key(when("fan.kitchen_fan", "on", "On", "Off"), "mdi:power", when("fan.kitchen_fan", "on", "green", "disabled"),
                {"action": "toggle"}, "Power", "fan.kitchen_fan"),
            key("Natural", "mdi:weather-windy", when("input_select.kitchen_fan_preset", "natural", "cyan", "disabled"),
                {"action": "perform-action", "perform_action": "fan.set_preset_mode",
                 "target": {"entity_id": "fan.kitchen_fan"}, "data": {"preset_mode": "natural"}}, "Preset"),
            key("Sleep", "mdi:weather-night", when("input_select.kitchen_fan_preset", "sleep", "indigo", "disabled"),
                {"action": "perform-action", "perform_action": "fan.set_preset_mode",
                 "target": {"entity_id": "fan.kitchen_fan"}, "data": {"preset_mode": "sleep"}}, "Preset")),
        row(key("Light", "mdi:lightbulb", "blue-grey", press("button.1b_fan_light_light_on_off")),
            *[key(h, "mdi:timer-outline", "blue-grey", press(f"button.1b_fan_light_timer_{h}")) for h in ("1h", "2h", "4h")]),
    ),
]}


def aircon(floor, icon, slug):
    cl = f"climate.{slug}"
    return {"type": "grid", "cards": [
        heading(f"{floor} air conditioner", icon),
        panel(
            slider(slug, off=True),
            slider(slug, off=False),
            row(key(pick({"off": "Off"}, f"states('{cl}')", "On"), "mdi:power", when(cl, "off", "disabled", "green"),
                    press(f"button.{slug}_power"), "Power"),
                key(pick({"auto": "Auto", "cool": "Cool", "dry": "Dry", "fan_only": "Fan only"}, f"states('{cl}')", "–"),
                    pick({"auto": "mdi:thermostat-auto", "cool": "mdi:snowflake", "dry": "mdi:water-percent",
                          "fan_only": "mdi:fan"}, f"states('{cl}')", "mdi:thermostat-auto"),
                    pick({"auto": "purple", "cool": "blue", "dry": "amber", "fan_only": "teal"}, f"states('{cl}')", "disabled"),
                    press(f"button.{slug}_next_mode"), "Mode"),
                key("{{ '–' if is_state('%s', 'off') else {'auto': 'Auto', 'low': 'Low', 'medium': 'Medium', "
                    "'high': 'High'}.get(state_attr('%s', 'fan_mode'), '–') }}" % (cl, cl),
                    pick({"auto": "mdi:fan-auto", "low": "mdi:fan-speed-1", "medium": "mdi:fan-speed-2",
                          "high": "mdi:fan-speed-3"}, f"state_attr('{cl}', 'fan_mode')", "mdi:fan"),
                    when(cl, "off", "disabled", "cyan"), press(f"button.{slug}_next_fan"), "Fan")),
            row(key("Eco", "mdi:leaf", when(f"switch.{slug}_eco", "on", "green", "disabled"),
                    {"action": "perform-action", "perform_action": "switch.toggle",
                     "target": {"entity_id": f"switch.{slug}_eco"}}),
                key("Boost", "mdi:flash", when(f"switch.{slug}_boost", "on", "orange", "disabled"),
                    {"action": "perform-action", "perform_action": "switch.toggle",
                     "target": {"entity_id": f"switch.{slug}_boost"}}),
                key("Swing", "mdi:arrow-oscillating", when(f"switch.{slug}_swing", "on", "blue", "disabled"),
                    {"action": "perform-action", "perform_action": "switch.toggle",
                     "target": {"entity_id": f"switch.{slug}_swing"}}),
                key("Sync", "mdi:sync", "blue-grey", press(f"button.{slug}_sync"))),
        ),
    ]}


floor1 = aircon("Floor 1", "mdi:home-floor-1", "floor_1_ac")
floor2 = aircon("Floor 2", "mdi:home-floor-2", "floor_2_ac")

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
