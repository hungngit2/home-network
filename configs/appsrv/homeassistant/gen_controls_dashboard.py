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
KEY = {"style": """ha-card {
  background: none; border: none; box-shadow: none; border-radius: 0;
  --icon-size: 28px; --icon-symbol-size: 18px; --spacing: 6px;
  --card-primary-font-size: 11px; --card-primary-line-height: 14px;
}"""}
OVERLAY = {"style": """ha-card { position: relative; background: none; border: none; box-shadow: none; border-radius: 0; }
.actions { margin-top: 8px; }
mushroom-state-item {
  position: absolute; top: 8px; left: calc(var(--spacing, 10px) + 14px);
  height: var(--control-height, 42px); display: flex; align-items: center; z-index: 2; pointer-events: none;
  --icon-size: 0px; --icon-symbol-size: 0px; --card-primary-font-size: 13px; --card-secondary-font-size: 10px;
  --card-primary-line-height: 15px; --card-secondary-line-height: 12px;
}
mushroom-shape-icon { display: none; }"""}
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
    c = {"type": "custom:mushroom-template-card", "layout": "horizontal", "fill_container": True,
         "primary": primary, "icon": icon, "icon_color": color, "tap_action": tap, "card_mod": KEY}
    if entity:
        c["entity"] = entity
    return c


def pick(table, source, default):
    """A Jinja lookup such as {'cool': 'Cool'}.get(source, default)."""
    body = ", ".join(f"'{k}': '{v}'" for k, v in table.items())
    return "{{ {%s}.get(%s, '%s') }}" % (body, source, default)


def when(entity, state, yes, no):
    return "{{ '%s' if is_state('%s', '%s') else '%s' }}" % (yes, entity, state, no)


TEMP_WORDS = [  # state, name shown on the bar, icon, colour
    ("off", "Off · slide to start cooling", "mdi:thermometer-off", "disabled"),
    ("cool", "Cooling to", "mdi:snowflake", "blue"),
    ("dry", "Drying room at", "mdi:water-percent", "amber"),
    ("fan_only", "Fan only · set to", "mdi:fan", "teal"),
    ("auto", "Auto · set to", "mdi:thermostat-auto", "purple"),
]


def temperature_sliders(slug):
    return [{"type": "conditional",
             "conditions": [{"condition": "state", "entity": f"climate.{slug}", "state": mode}],
             "card": {"type": "custom:mushroom-number-card", "entity": f"number.{slug}_temperature", "name": name,
                      "icon": icon, "icon_color": color, "display_mode": "slider", "primary_info": "name",
                      "secondary_info": "state", "fill_container": True,
                      "tap_action": press(f"button.{slug}_power") if mode == "off" else NONE, "card_mod": OVERLAY}}
            for mode, name, icon, color in TEMP_WORDS]


FAN_WORDS = [  # fan percentage attribute (or off), friendly text on the bar
    (None, "Fan off · slide to start"),
    ("17", "Speed 1 · gentle breeze"),
    ("33", "Speed 2 · light"),
    ("50", "Speed 3 · medium"),
    ("67", "Speed 4 · strong"),
    ("83", "Speed 5 · very strong"),
    ("100", "Speed 6 · maximum"),
]


def fan_sliders():
    cards = []
    for percentage, name in FAN_WORDS:
        condition = ({"condition": "state", "entity": "fan.kitchen_fan", "state": "off"} if percentage is None else
                     {"condition": "state", "entity": "fan.kitchen_fan", "attribute": "percentage", "state": percentage})
        cards.append({"type": "conditional", "conditions": [condition],
                      "card": {"type": "custom:mushroom-fan-card", "entity": "fan.kitchen_fan", "name": name,
                               "secondary_info": "none", "fill_container": True, "icon_animation": True,
                               "show_percentage_control": True, "show_direction_control": True,
                               "collapsible_controls": False, "tap_action": {"action": "toggle"},
                               "hold_action": MORE, "card_mod": OVERLAY}})
    return cards


kitchen = {"type": "grid", "cards": [
    heading("Kitchen", "mdi:silverware-fork-knife"),
    panel(
        *fan_sliders(),
        row(key(when("fan.kitchen_fan", "on", "On", "Off"), "mdi:power", when("fan.kitchen_fan", "on", "green", "disabled"),
                {"action": "toggle"}, "Power", "fan.kitchen_fan"),
            key(pick({"natural": "Natural", "sleep": "Sleep"}, "state_attr('fan.kitchen_fan', 'preset_mode')", "Wind"),
                pick({"natural": "mdi:weather-windy", "sleep": "mdi:weather-night"},
                     "state_attr('fan.kitchen_fan', 'preset_mode')", "mdi:weather-windy"),
                pick({"natural": "cyan", "sleep": "indigo"}, "state_attr('fan.kitchen_fan', 'preset_mode')", "blue-grey"),
                press("button.kitchen_fan_wind")),
            key("Light", "mdi:lightbulb", "blue-grey", press("button.kitchen_fan_light"))),
        row(key("{{ 'Timer' if state_attr('fan.kitchen_fan', 'timer') in ('none', None) else "
                "'Timer ' ~ state_attr('fan.kitchen_fan', 'timer') }}", "mdi:timer-outline",
                "{{ 'blue-grey' if state_attr('fan.kitchen_fan', 'timer') in ('none', None) else 'cyan' }}",
                press("button.kitchen_fan_timer"))),
    ),
]}


def aircon(floor, icon, slug):
    cl = f"climate.{slug}"
    return {"type": "grid", "cards": [
        heading(f"{floor} air conditioner", icon),
        panel(
            *temperature_sliders(slug),
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
                     "target": {"entity_id": f"switch.{slug}_swing"}})),
        ),
    ]}


floor1 = aircon("1B", "mdi:home-floor-1", "1b_ac")
floor2 = aircon("2B", "mdi:home-floor-2", "2b_ac")

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
