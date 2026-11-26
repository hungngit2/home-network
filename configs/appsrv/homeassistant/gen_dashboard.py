import copy, json, sys

P = sys.argv[1]
ADMIN = "3d0602e4c2514c10b975883aa25b394f"
L = "sensor.luxpower_inverter_"
SOC = L + "battery_battery_soc"
FAULT, WARN = L + "active_fault_text", L + "active_warning_text"
GRID_SW = "switch.grid_protector"
FREEZER = "switch.mosquito_repellent"
BACKUP = "sensor.backup_last_successful_automatic_backup"
ECM = "sensor.ecm_"
NONE = {"action": "none"}
MORE = {"action": "more-info"}
PANEL = {"action": "navigate", "navigation_path": "/energy-cost"}
def monthly(field):
    return (f"return (entity.attributes.by_period || []).filter(p => p.{field} !== null).map(p => "
            f"[new Date(p.start + 'T00:00:00').getTime(), p.{field}]);")


THOUSANDS_JS = "EVAL:function(v) { return v == null ? '' : Math.round(v).toLocaleString('en-US') + ' ₫'; }"


def daily(field):
    return (f"return (entity.attributes.daily || []).map(d => "
            f"[new Date(d.date + 'T00:00:00').getTime(), d.{field}]);")


# Legend entries with the total of each series for the current year, e.g. "Without solar · 1,151,671 ₫"
LEGEND_TOTALS_JS = ("EVAL:function(name, opts) { var d = (opts.w.config.series[opts.seriesIndex] || {}).data || []; "
                    "var y = new Date().getFullYear(); var s = 0; d.forEach(function(p) { "
                    "var x = Array.isArray(p) ? p[0] : (p && p.x); var v = Array.isArray(p) ? p[1] : (p && p.y); "
                    "if (v != null && new Date(x).getFullYear() === y) s += v; }); "
                    "return name + ' · ' + Math.round(s).toLocaleString('en-US') + ' ₫'; }")
OPEN_PANEL_JS = ("EVAL:function() { history.pushState(null, '', '/energy-cost'); "
                 "window.dispatchEvent(new CustomEvent('location-changed')); }")
COLORS = {"solar": "amber", "home": "blue", "grid": "deep-orange", "battery": "green"}

doc = json.load(open(P))
old = doc["data"]["config"]
hub = copy.deepcopy(next(c for v in old["views"] for s in v.get("sections", []) for c in s["cards"]
                         if c.get("type") == "custom:home-energy-hub" and c.get("wide")))
hub["battery_min_soc"] = 10  # the inverter's on-grid / off-grid cut-off setting (was 30, the grid-fallback automation threshold)
# The live "Money" section on the Energy tab was edited by hand after this script last ran; carry it over as is.
old_money = next((sec for v in old["views"] if v.get("path") == "energy" for sec in v.get("sections", [])
                  if any(c.get("heading") in ("Money", "Energy Cost") for c in sec.get("cards", []))), None)


def heading(text, icon=None, style="title", **kw):
    h = {"type": "heading", "heading": text, "heading_style": style}
    if icon:
        h["icon"] = icon
    h.update(kw)
    return h


def tile(entity, name=None, icon=None, color=None, cols=6, **kw):
    t = {"type": "tile", "entity": entity}
    if name: t["name"] = name
    if icon: t["icon"] = icon
    if color: t["color"] = color
    t["grid_options"] = {"columns": cols}
    t.update(kw)
    return t


def status(entity, name, icon, cols=6, color=None, **kw):
    return tile(entity, name, icon, color, cols, tap_action=NONE, icon_tap_action=NONE,
                hold_action=NONE, double_tap_action=NONE, **kw)


def confirm(text):
    return {"action": "toggle", "confirmation": {"text": text}}


def is_(entity, state):
    return {"condition": "state", "entity": entity, "state": state}


def is_not(entity, state):
    return {"condition": "state", "entity": entity, "state_not": state}


PROBLEMS = [
    ("cb_ground_floor", "Ground floor breaker reports a problem"),
    ("cb_floor_1", "Floor 1 breaker reports a problem"),
    ("cb_floor_2", "Floor 2 breaker reports a problem"),
    ("meter_protector", "Main meter reports a problem"),
    ("grid_protector", "Grid protector reports a problem"),
    ("refrigerator", "Fridge plug reports a problem"),
    ("mosquito_repellent", "Freezer plug reports a problem"),
    ("bike_charger", "Bike charger reports a problem"),
    ("mosquito_liquid", "Mosquito repellent plug reports a problem"),
]
FAULT_OK = ["No Faults", "unknown", "unavailable"]
WARN_OK = ["No Warnings", "unknown", "unavailable"]

alerts = [
    (tile(FAULT, "Inverter fault", "mdi:alert-octagon", "red", 12), [is_not(FAULT, FAULT_OK)], [is_(FAULT, FAULT_OK)]),
    (tile(WARN, "Inverter warning", "mdi:alert", "amber", 12), [is_not(WARN, WARN_OK)], [is_(WARN, WARN_OK)]),
    (status(SOC, "Inverter not reporting", "mdi:lan-disconnect", 12, "red"), [is_(SOC, "unavailable")], [is_not(SOC, "unavailable")]),
    (status(SOC, "Battery low: grid is powering the house", "mdi:battery-alert", 12, "deep-orange"),
     [{"condition": "numeric_state", "entity": SOC, "below": 31}, is_not("input_boolean.grid_outage", "on")],
     [{"condition": "numeric_state", "entity": SOC, "above": 30.9}]),
    (tile(ECM + "evn_amount_due", "EVN invoice unpaid", "mdi:cash-clock", "red", 12,
          tap_action={"action": "navigate", "navigation_path": "/energy-cost"}),
     [is_(ECM + "evn_payment_status", "unpaid")], [is_not(ECM + "evn_payment_status", "unpaid")]),
    (status(FREEZER, "Freezer plug is OFF", "mdi:fridge-industrial-alert", 12, "red", hide_state=True), [is_(FREEZER, "off")], [is_not(FREEZER, "off")]),
    (status("input_boolean.grid_outage", "Grid power outage: running on battery", "mdi:transmission-tower-off", 12, "red", hide_state=True),
     [is_("input_boolean.grid_outage", "on")], [is_not("input_boolean.grid_outage", "on")]),
    (status(BACKUP, "No backup has run yet", "mdi:backup-restore", 12, "deep-orange", hide_state=True), [is_(BACKUP, "unknown")], [is_not(BACKUP, "unknown")]),
] + [
    (status(f"binary_sensor.{e}_problem", n, "mdi:alert-circle", 12, "red"), [is_(f"binary_sensor.{e}_problem", "on")],
     [is_not(f"binary_sensor.{e}_problem", "on")])
    for e, n in PROBLEMS
]
status_section = {"type": "grid", "column_span": 3, "cards": [heading("Status", "mdi:shield-home")]}
for card, show_when, ok_when in alerts:
    card["visibility"] = show_when
    status_section["cards"].append(card)
all_ok = [c for _, _, oks in alerts for c in oks]
status_section["cards"].append(status(SOC, "All systems normal", "mdi:check-circle", 12, "green",
                                      hide_state=True, visibility=[{"condition": "and", "conditions": all_ok}]))
ADMIN_ONLY = {"condition": "user", "users": [ADMIN]}
status_section["cards"] += [
    tile(ECM + "data_quality", "Energy data needs checking", "mdi:database-alert", "amber", 12,
         tap_action={"action": "navigate", "navigation_path": "/energy-cost"},
         visibility=[ADMIN_ONLY, is_(ECM + "data_quality", "poor")]),
    tile(ECM + "evn_reconciliation", "EVN and our meter disagree", "mdi:scale-unbalanced", "amber", 12,
         tap_action={"action": "navigate", "navigation_path": "/energy-cost"},
         visibility=[ADMIN_ONLY, is_(ECM + "evn_reconciliation", "deviation")]),
]

forecast_hub = copy.deepcopy(hub)
for k in ("wide", "show_insights", "periods", "consumers", "inverter_details", "panels_per_string",
          "panels_average", "pv_panels", "grid_options"):
    forecast_hub.pop(k, None)
forecast_hub.update({"sections": ["forecast"], "title": " ", "show_weather": False})
forecast_hub["grid_options"] = {"columns": 12}

power_now = {"type": "grid", "cards": [
    heading("Power now", "mdi:solar-power-variant"),
    {"type": "gauge", "entity": SOC, "name": "Battery", "min": 0, "max": 100, "needle": True,
     "segments": [{"from": 0, "color": "var(--deep-orange-color)"}, {"from": 31, "color": "var(--amber-color)"},
                  {"from": 50, "color": "var(--green-color)"}],
     "grid_options": {"columns": 6, "rows": 3}},
    tile(L + "pv_pv_power", "Solar", "mdi:solar-power", COLORS["solar"]),
    tile(L + "load_power", "Home using", "mdi:home-lightning-bolt", COLORS["home"]),
    status(GRID_SW, "Grid not needed", "mdi:transmission-tower-off", 6, COLORS["battery"],
           state_content=["last_changed"], visibility=[is_(GRID_SW, "off")]),
    status(GRID_SW, "On grid", "mdi:transmission-tower", 6, COLORS["grid"],
           state_content=["last_changed"], visibility=[is_(GRID_SW, "on")]),
    tile("sensor.battery_energy_left", "Energy left", "mdi:battery-clock", COLORS["battery"], 6, state_content=["state", "summary"]),
    tile("sensor.battery_runtime", "Runtime", "mdi:timer-sand", COLORS["battery"], 6,
         visibility=[{"condition": "state", "entity": "sensor.battery_runtime", "state_not": ["unavailable", "unknown"]}]),
    tile("sensor.battery_status", "Battery now", None, COLORS["battery"], 12),
    forecast_hub,
]}

usage = {"type": "grid", "cards": [
    heading("Where power goes", "mdi:meter-electric"),
    status("sensor.cb_ground_floor_power", "Ground floor", "mdi:home-floor-g", 6, "indigo"),
    status("sensor.cb_floor_1_power", "Floor 1", "mdi:home-floor-1", 6, "indigo"),
    status("sensor.cb_floor_2_power", "Floor 2", "mdi:home-floor-2", 6, "indigo"),
    tile("sensor.refrigerator_power", "Fridge", "mdi:fridge-outline", "light-blue"),
    tile("sensor.mosquito_repellent_power", "Chest freezer", "mdi:fridge-industrial-outline", "light-blue"),
    tile("sensor.bike_charger_power", "Bike charger", "mdi:bicycle-electric", "light-blue"),
]}

MEDIA_ON = [{"condition": "state", "entity": "media_player.mibox3", "state_not": ["unavailable", "off"]}]
TV_ON = [{"condition": "state", "entity": "media_player.l50p8", "state_not": ["unavailable", "off"]}]
media = {"type": "grid", "visibility": [{"condition": "or", "conditions": MEDIA_ON + TV_ON}], "cards": [
    heading("Media", "mdi:television-play"),
    {"type": "media-control", "entity": "media_player.mibox3", "grid_options": {"columns": 12}, "visibility": MEDIA_ON},
    {"type": "media-control", "entity": "media_player.l50p8", "grid_options": {"columns": 12}, "visibility": TV_ON},
]}

home = {"title": "Home", "path": "home", "icon": "mdi:home", "type": "sections", "max_columns": 3,
        "badges": [
            {"type": "entity", "entity": "weather.forecast_home", "show_state": True, "show_icon": True,
             "state_content": ["state", "temperature"]},
            {"type": "entity", "entity": L + "pv_pv_energy_today", "name": "Solar today", "icon": "mdi:solar-power",
             "show_name": True, "show_state": True, "show_icon": True, "color": COLORS["solar"]},
            {"type": "entity", "entity": L + "home_consumption_today_calculated", "name": "Used today",
             "icon": "mdi:home-lightning-bolt", "show_name": True, "show_state": True, "show_icon": True,
             "color": COLORS["home"]},
            {"type": "entity", "entity": ECM + "savings_today", "name": "Saved today", "icon": "mdi:piggy-bank",
             "show_name": True, "show_state": True, "show_icon": True, "color": "green",
             "tap_action": {"action": "navigate", "navigation_path": "/energy-cost"}},
        ],
        "sections": [status_section, power_now, usage, media]}

hub.update({
    "title": "House 39",
    "colors": {"solar": "#ffc107", "load": "#2196f3", "grid": "#ff5722", "battery": "#4caf50"},
    "sections": ["flow", "today", "sources", "inverter", "forecast", "pv_forecast", "energy", "consumers", "insights",
                 "comparison", "panels"],
    "collapsible": True,
    "collapsed": ["comparison", "panels"],
    "grid_options": {"columns": "full"},
})
energy = {"title": "Energy", "path": "energy", "icon": "mdi:lightning-bolt", "type": "sections", "max_columns": 3, "sections": [
    {"type": "grid", "column_span": 3, "cards": [hub]},
] + [
    old_money or {"type": "grid", "column_span": 3, "cards": [
        heading("Money", "mdi:cash-multiple", tap_action=PANEL),
        tile(ECM + "savings_today", "Saved today", "mdi:piggy-bank", "green", tap_action=PANEL),
        tile(ECM + "savings_period", "Saved this period", "mdi:piggy-bank-outline", "green", tap_action=PANEL),
        tile(ECM + "actual_bill_period", "Bill so far", "mdi:receipt-text", COLORS["grid"], tap_action=PANEL),
        tile(ECM + "projected_bill", "Expected bill", "mdi:chart-timeline-variant", COLORS["home"], tap_action=PANEL),
        tile(ECM + "evn_payment_status", "Previous invoice", "mdi:check-decagram", "red", 12, tap_action=PANEL,
             visibility=[is_(ECM + "evn_payment_status", "unpaid")]),
        {"type": "custom:apexcharts-card", "grid_options": {"columns": 18},
         "header": {"show": True, "title": "Cost per month this year (₫)", "show_states": False},
         "graph_span": "1y", "span": {"start": "year"},
         "apex_config": {"chart": {"height": 220, "events": {"click": OPEN_PANEL_JS}}, "legend": {"position": "bottom", "formatter": LEGEND_TOTALS_JS},
                         "tooltip": {"y": {"formatter": THOUSANDS_JS}}},
         "yaxis": [{"min": 0, "decimals": 0, "apex_config": {"labels": {"formatter": THOUSANDS_JS}}}],
         "series": [
             {"entity": ECM + "savings_lifetime", "name": "Without solar", "type": "column", "color": "#9e9e9e",
              "show": {"legend_value": False, "in_header": False}, "data_generator": monthly("no_solar_cost")},
             {"entity": ECM + "savings_lifetime", "name": "With solar", "type": "column", "color": "#ff5722",
              "show": {"legend_value": False, "in_header": False}, "data_generator": monthly("actual_cost")},
             {"entity": ECM + "savings_lifetime", "name": "EVN bill", "type": "column", "color": "#1e88e5",
              "show": {"legend_value": False, "in_header": False}, "data_generator": monthly("evn_cost")},
         ]},
    ]},
]}

equipment = {"title": "Equipment", "path": "equipment", "icon": "mdi:solar-power-variant", "type": "sections", "max_columns": 3, "sections": [
    {"type": "grid", "cards": [
        heading("Battery", "mdi:home-battery"),
        tile(SOC, "Charge", "mdi:battery-high", COLORS["battery"], 6),
        tile("sensor.battery_energy_left", "Energy left", "mdi:battery-clock", COLORS["battery"], 6, state_content=["state", "summary"]),
        # www/battery-report-card.js: Day/Week/Month/Year discharge, overnight discharge, measured capacity
        {"type": "custom:battery-report-card", "title": "Battery report", "rated_kwh": 16.08,
         "night_start": 18, "night_end": 6, "grid_options": {"columns": "full"}},
        heading("Details", style="subtitle"),  # order as arranged in the live dashboard
        tile(L + "battery_battery_voltage", "Voltage", "mdi:current-dc"),
        tile(L + "battery_bms_cell_difference", "Cell imbalance", "mdi:scale-unbalanced"),
        tile(L + "battery_bms_min_cell_voltage", "Cell voltage (min)", "mdi:battery-minus-variant"),
        tile(L + "battery_bms_max_cell_voltage", "Cell voltage (max)", "mdi:battery-plus-variant"),
        tile(L + "battery_bms_min_cell_temperature", "Cell temp (min)", "mdi:thermometer-low"),
        tile(L + "battery_bms_max_cell_temperature", "Cell temp (max)", "mdi:thermometer-high"),
        tile(L + "battery_bms_battery_current", "Current (−: out)", "mdi:current-dc"),
        tile(L + "battery_bms_cycle_count", "Cycles", "mdi:counter"),
    ]},
    {"type": "grid", "cards": [
        heading("Inverter", "mdi:solar-power-variant"),
        tile(L + "inverter_state", "Mode", "mdi:state-machine", None, 12),
        tile(L + "radiator_temperature", "Temperature", "mdi:thermometer", "orange"),
        tile(L + "load_percentage", "Load", "mdi:gauge", COLORS["home"]),
        tile(L + "pv_pv1_power", "PV string 1 (6 panels)", "mdi:solar-panel", COLORS["solar"]),
        tile(L + "pv_pv2_power", "PV string 2 (3 panels)", "mdi:solar-panel", COLORS["solar"]),
        tile(L + "pv_pv1_energy_today", "String 1 today", "mdi:solar-panel", COLORS["solar"]),
        tile(L + "pv_pv2_energy_today", "String 2 today", "mdi:solar-panel", COLORS["solar"]),
        tile(L + "eps_eps_voltage", "Output voltage", "mdi:sine-wave"),
        tile("binary_sensor.luxpower_inverter_grid_grid_connected", "Grid at inverter", "mdi:transmission-tower"),
        tile(FAULT, "Faults", "mdi:alert-octagon-outline"),
        tile(WARN, "Warnings", "mdi:alert-outline"),
        tile("sensor.luxpower_inverter_inverter_state", "Inverter settings", "mdi:cog-transfer", None, 12,
             tap_action={"action": "navigate", "navigation_path": "/inverter-setup"}, icon_tap_action={"action": "navigate", "navigation_path": "/inverter-setup"},
             visibility=[ADMIN_ONLY], state_content=["state"]),
    ]},
    {"type": "grid", "cards": [
        heading("Breakers & plugs", "mdi:electric-switch"),
        {"type": "markdown", "grid_options": {"columns": "full"},
         "content": "**Floor breakers are status only.** They feed the router, Wi-Fi and this server; "
                    "switching one off from here could take the network down with no remote way back."},
        status("sensor.cb_ground_floor_power", "Ground floor", "mdi:home-floor-g"),
        status("sensor.cb_floor_1_power", "Floor 1 (network)", "mdi:home-floor-1"),
        status("sensor.cb_floor_2_power", "Floor 2", "mdi:home-floor-2"),
        status("switch.sonoff_1000a8b00e", "Network relay", "mdi:router-network"),
        status(GRID_SW, "Grid fallback", "mdi:transmission-tower", 6, COLORS["grid"]),
        status(FREEZER, "Chest freezer plug", "mdi:fridge-industrial", 6, "light-blue"),
        heading("Electrical health", style="subtitle"),
        tile("sensor.meter_protector_temperature", "Meter box temp", "mdi:thermometer", "orange"),
        tile("sensor.grid_protector_leakage_current", "Leakage current", "mdi:flash-alert"),
        tile("sensor.meter_protector_voltage", "House voltage", "mdi:sine-wave"),
        tile("sensor.grid_protector_voltage_a", "Grid voltage", "mdi:transmission-tower"),
    ]},
]}


# Devices whose loss matters (real devices only: the OwnTone / Phicomm / TV leftovers are deliberately not counted).
# The same list is in configuration.yaml (sensor.devices_offline); keep the two in step.
SAFETY_AUTOMATIONS = [
    ("automation.alert_grid_power_outage", "Alert: grid outage"), ("automation.alert_grid_power_restored", "Alert: grid restored"),
    ("automation.alert_grid_protector_engaged", "Alert: grid fallback engaged"), ("automation.alert_inverter_fault_or_warning", "Alert: inverter fault / warning"),
    ("automation.alert_inverter_offline", "Alert: inverter offline"), ("automation.alert_low_battery_soc", "Alert: low battery"),
    ("automation.solar_off_grid_close_grid_protector_at_30_soc", "Grid fallback: close at 30%"),
    ("automation.solar_off_grid_open_grid_protector_at_40_soc", "Grid fallback: open at 40%"),
    ("automation.solar_off_grid_close_grid_protector_on_high_load", "Grid fallback: close on high load"),
    ("automation.solar_off_grid_open_grid_protector_after_high_load", "Grid fallback: open after high load"),
    ("automation.bike_overcharge_cutoff", "Bike charger cut-off"),
]


def counter_tiles(sensor, ok_name, bad_name, ok_icon, bad_icon, bad_color, list_label):
    """A green tile at 0, a coloured tile above 0, and a one-line list of what is behind the count."""
    above = [{"condition": "numeric_state", "entity": sensor, "above": 0}]
    zero = [{"condition": "numeric_state", "entity": sensor, "below": 1}]
    return [
        tile(sensor, ok_name, ok_icon, "green", 6, visibility=zero),
        tile(sensor, bad_name, bad_icon, bad_color, 6, visibility=above),
        {"type": "markdown", "grid_options": {"columns": "full"}, "visibility": above,
         "content": f"**{list_label}:** {{{{ state_attr('{sensor}', 'names') }}}}"},
    ]


settings = {"title": "Settings", "path": "settings", "icon": "mdi:cog", "type": "sections", "max_columns": 3,
            "visible": [{"user": ADMIN}], "sections": [
    {"type": "grid", "cards": [
        heading("Health check", "mdi:heart-pulse"),
        tile(BACKUP, "Last backup", "mdi:backup-restore", None, 6),
        tile("sensor.backup_next_scheduled_automatic_backup", "Next backup", "mdi:calendar-clock", None, 6),
        *counter_tiles("sensor.devices_offline", "Devices online", "Devices offline", "mdi:lan-check", "mdi:lan-disconnect", "red", "Offline"),
        *counter_tiles("sensor.updates_pending", "Up to date", "Updates pending", "mdi:check-circle-outline", "mdi:update", "amber", "Updates"),
        *counter_tiles("sensor.safety_automations_off", "Safety automations on", "Safety automations off", "mdi:shield-check", "mdi:shield-alert", "red", "Off"),
    ]},
    {"type": "grid", "cards": [
        heading("Controls", "mdi:toggle-switch-outline"),
        {"type": "markdown", "grid_options": {"columns": "full"},
         "content": "Automations close the grid fallback at **30%** battery or when the house draws more than "
                    "**5.5 kW**, and open it again at **40%** once the load is under **4 kW**. "
                    "Inverter settings are on the **Solar Inverter Settings** page in the sidebar."},
        tile(GRID_SW, "Grid fallback (manual)", "mdi:transmission-tower", COLORS["grid"], 6,
             tap_action=confirm("Switch grid fallback by hand? The battery automations may switch it back."),
             icon_tap_action=confirm("Switch grid fallback by hand? The battery automations may switch it back."),
             hold_action=NONE, double_tap_action=NONE),
        tile(FREEZER, "Chest freezer plug", "mdi:fridge-industrial", "light-blue", 6,
             tap_action=confirm("Switch the CHEST FREEZER plug? Food may spoil if left off."),
             icon_tap_action=confirm("Switch the CHEST FREEZER plug? Food may spoil if left off."),
             hold_action=NONE, double_tap_action=NONE),
    ]},
    {"type": "grid", "cards": [
        heading("Safety automations", "mdi:shield-check"),
        {"type": "entities", "grid_options": {"columns": "full"}, "show_header_toggle": False,
         "entities": [{"type": "attribute", "entity": e, "attribute": "last_triggered", "name": n, "format": "relative", "icon": "mdi:history"}
                      for e, n in SAFETY_AUTOMATIONS]},
        {"type": "markdown", "grid_options": {"columns": "full"},
         "content": "Last time each one fired. Read-only here, so none can be switched off by a stray tap; manage them in "
                    "[Automations](/config/automation/dashboard)."},
    ]},
    {"type": "grid", "cards": [
        heading("Energy data", "mdi:database-check"),
        tile(ECM + "data_quality", "Data quality", "mdi:database-check", None, 12, state_content=["state", "reasons"]),
        tile(ECM + "evn_reconciliation", "EVN meter check", "mdi:scale-balance", None, 6, state_content=["state", "data_age_days"]),
        tile(ECM + "evn_difference", "EVN minus our meter", "mdi:scale-unbalanced", None, 6),
        tile(ECM + "active_tariff", "Tariff", "mdi:tag-text", None, 6, state_content=["state", "verified"]),
        tile(ECM + "evn_meter_reading", "EVN meter reading", "mdi:counter", None, 6),
    ]},
    {"type": "grid", "cards": [
        heading("Network", "mdi:wifi"),
        tile("sensor.sonoff_1000a8b00e_rssi", "Network relay Wi-Fi", "mdi:wifi"),
        tile("sensor.sonoff_10014c209d_rssi", "Front light Wi-Fi", "mdi:wifi"),
        tile("sensor.sonoff_1000eff333_rssi", "Irrigation Wi-Fi", "mdi:wifi"),
        {"type": "markdown", "grid_options": {"columns": "full"},
         "content": "[Automations](/config/automation/dashboard) · [Devices](/config/devices/dashboard) · "
                    "[Logs](/config/logs) · [Backups](/config/backup) · [Energy setup](/config/energy) · "
                    "[Energy Cost](/energy-cost) · [Energy Cost Manager](/config/integrations/integration/energy_cost_manager)"},
    ]},
]}

doc["data"]["config"] = {"title": "Home", "views": [home, energy, equipment, settings]}
json.dump(doc, open(P, "w"), indent=2, ensure_ascii=False)
json.load(open(P))
print("views:", [v["title"] for v in doc["data"]["config"]["views"]])
