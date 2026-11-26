"""Builds the Inverter tab and slims Settings down to the non-inverter controls.

Run on the Wyse with HA stopped (HA rewrites .storage while running):
    python3 gen_inverter_tab.py .storage/core.entity_registry .storage/lovelace.lovelace \\
        .storage/lovelace.inverter_setup .storage/lovelace_dashboards
The Inverter page is its own admin-only dashboard (sidebar entry, url /inverter-setup), registered in lovelace_dashboards
with require_admin. The 3rd/4th arguments are optional; without them nothing is written for that dashboard.
Idempotent: removes any old Inverter tab from the default dashboard and replaces the Inverter dashboard.
    python3 gen_inverter_tab.py REGISTRY DASHBOARD --check STATES.json   # offline self-check, writes nothing

Layout logic (a solar engineer's view): only show what the current configuration makes meaningful.
  * Drivers (always visible): AC charging, Charge first, Forced discharge, Grid export, Peak shaving
    switches and the AC-charge / discharge-control / on-grid-EOD type pickers.
  * Dependents appear only when their driver is on / of the matching type (e.g. SOC fields only when the
    type uses SOC, time windows only when it uses time). Whole sections hide with their driver.
  * "Show advanced settings" (input_boolean.inverter_advanced) reveals generator / smart load / AC coupling,
    legacy and percent-style siblings of kW controls, hysteresis and similar tuning.
  * Never editable here: Working Mode (Standby stops the inverter), Line Mode, Smart Load Enable, grid-code
    protection limits, lead-acid and float/equalization voltages (lithium + BMS), buttons (Reboot inverter),
    LCD password, Mon-Sun schedule clones. They are shown read-only under advanced, or left out.
"""
import json
import re
import sys

args = [a for a in sys.argv[1:] if not a.startswith("--")]
CHECK = "--check" in sys.argv
REG, DASH = args[0], args[1]
INV_DASH = args[2] if len(args) > 2 else None   # .storage/lovelace.inverter_setup (the separate admin-only dashboard)
DASHBOARDS = args[3] if len(args) > 3 else None  # .storage/lovelace_dashboards (sidebar registry)
INV_ID, INV_URL = "inverter_setup", "inverter-setup"  # url_path needs a hyphen
INV_TITLE = "Luxpower SNA PRO 6.5kW"  # page title (dashboard and view)
INV_SHORT = "Solar Inverter Settings"  # sidebar entry; also the label of buttons and notes that point to the page
STATES = json.load(open(sys.argv[sys.argv.index("--check") + 1])) if CHECK else None
P = "luxpower_inverter_"
NONE = {"action": "none"}
ADV_ENTITY = "input_boolean.inverter_advanced"

# Option strings come from the lxp_modbus selectbox_types.py source.
T_TIME = ["According to Time", "According to Voltage and Time", "According to SOC and Time"]
T_SOC = ["According to SOC", "According to SOC and Time"]
T_VOLT = ["According to Voltage", "According to Voltage and Time"]
DC_SOC = ["According to SOC", "According to Both"]
DC_VOLT = ["According to Voltage", "According to Both"]


def st(domain, slug, state):
    return {"condition": "state", "entity": f"{domain}.{P}{slug}", "state": state}


def on(slug):
    return st("switch", slug, "on")


ADV = {"condition": "state", "entity": ADV_ENTITY, "state": "on"}
AC_ON, CF_ON, FD_ON, EXP_ON, PS_ON, ACC_ON = on("ac_charging"), on("charge_first_pv"), on("forced_discharge"), on("grid_grid_export"), on("grid_grid_peak_shaving"), on("ac_coupling")
TLT_ON, CT_ON = on("take_load_together"), on("grid_grid_ct_connection")  # hybrid master switch; CT fitted
AC_T_SOC, AC_T_TIME, AC_T_VOLT = (st("select", "ac_charge_type", T_SOC), st("select", "ac_charge_type", T_TIME), st("select", "ac_charge_type", T_VOLT))
DC_S, DC_V = st("select", "discharge_control_type", DC_SOC), st("select", "discharge_control_type", DC_VOLT)
EOD_S, EOD_V = st("select", "grid_on_grid_eod_type", "According to SOC"), st("select", "grid_on_grid_eod_type", "According to Voltage")
SL_GEN, SL_LOAD = st("select", "smart_load_enable", "Generator"), st("select", "smart_load_enable", "Smart Load")

# slug -> (section, extra conditions). Section visibility is the driver; extra conditions refine a card.
TABLE = {}


def put(section, slugs, *conds):
    for s in slugs:
        assert s not in TABLE, s
        TABLE[s] = (section, list(conds))


# Operating drivers: always visible, one tap + confirm for switches.
put("modes", ["take_load_together", "ac_charging", "charge_first_pv", "forced_discharge", "grid_grid_peak_shaving", "eco_mode",
              "green_mode", "seamless_eps_switching", "grid_power_backup", "buzzer", "battery_charge_last",
              "output_priority_config", "ac_charge_type", "discharge_control_type", "grid_on_grid_eod_type"])
# Protection and cut-offs: which cut-off applies depends on SOC vs voltage control type.
put("protect", ["battery_on_grid_cut_off_soc"], EOD_S)
put("protect", ["grid_on_grid_cut_off_voltage"], EOD_V)
put("protect", ["battery_off_grid_cut_off_soc", "battery_battery_low_soc_alarm", "battery_battery_low_soc_recovery", "battery_discharge_recovery_soc_threshold"], DC_S)
put("protect", ["battery_battery_low_voltage_alarm", "battery_battery_low_voltage_recovery", "discharge_recovery_volt_threshold"], DC_V)
put("protect", ["battery_battery_stop_charging_soc", "battery_charge_current", "battery_discharge_current"])
# Charge from grid.
put("acchg", ["ac_charge_power_kw", "battery_ac_charge_current_from_grid"])
put("acchg", ["battery_ac_charge_start_soc", "battery_ac_charge_end_soc", "battery_ac_charge_soc_limit"], AC_T_SOC)
put("acchg", ["battery_ac_charge_start_voltage", "battery_ac_charge_end_voltage"], AC_T_VOLT)
# Charge first (PV), forced discharge.
put("chargefirst", ["battery_charge_first_power", "battery_charge_first_soc_limit", "battery_charge_first_end_voltage"])
put("forced", ["battery_forced_discharge_power_kw", "battery_forced_discharge_soc_limit", "battery_forced_discharge_end_voltage"])
# Grid, export, peak shaving.
put("grid", ["grid_max_grid_input_power", "grid_grid_ct_connection"])
put("grid", ["grid_grid_export"], TLT_ON)  # export belongs to hybrid mode (manual 3.3)
put("grid", ["max_backflow_power"], TLT_ON, EXP_ON)
put("grid", ["external_ct_power_offset"], TLT_ON, CT_ON)
put("grid", ["grid_grid_peak_shaving_power", "grid_grid_peak_shaving_power_period_1", "grid_grid_peak_shaving_soc_period_1",
             "grid_grid_peak_shaving_voltage", "grid_grid_peak_shaving_voltage_period_1", "battery_grid_peak_shaving_soc"], PS_ON)
# Advanced (behind the toggle): siblings, tuning, generator, smart load, AC coupling.
put("adv_misc", ["quick_charge_start", "half_hour_ac_charge_start", "fast_zero_export", "battery_battery_backup",
                 "battery_battery_maintenance", "micro_grid", "ac_coupling", "smart_load_on_grid_always_on",
                 "ac_charge_power", "battery_ac_charge_power_rate_precise", "battery_charge_first_power_rate_precise",
                 "battery_forced_discharge_power", "battery_forced_discharge_power_rate_precise", "battery_charge_power_rate_legacy",
                 "battery_discharge_power_rate_legacy", "battery_system_charge_power_rate", "battery_system_discharge_power_rate",
                 "battery_battery_stop_charging_voltage", "battery_battery_soc_hysteresis", "battery_battery_voltage_hysteresis",
                 "battery_battery_transfer_to_grid_soc", "battery_battery_transfer_to_grid_voltage", "battery_start_discharge_p_import",
                 "start_charge_p_import_threshold", "battery_battery_calibration_period", "battery_quick_charge_time",
                 "specific_load_compensate", "active_power_percent", "active_power_percent_precise"])
put("adv_acc", ["ac_coupling_ac_couple_end_soc", "ac_coupling_ac_couple_end_voltage", "ac_coupling_ac_couple_start_soc", "ac_coupling_ac_couple_start_voltage"], ACC_ON)
put("adv_gen", ["generator_generator_cool_down_time", "generator_generator_rated_power", "battery_generator_charge_end_soc",
                "battery_generator_charge_end_voltage", "battery_generator_charge_start_soc", "battery_generator_charge_start_voltage",
                "battery_max_generator_charge_current"], SL_GEN)
put("adv_load", ["smart_load_smart_load_off_soc", "smart_load_smart_load_off_voltage", "smart_load_smart_load_on_soc", "smart_load_smart_load_on_voltage"], SL_LOAD)
# Selects that stay read-only: Standby would stop the inverter; line mode and smart-load port are installer choices.
READONLY_STATUS = ["working_mode", "line_mode", "smart_load_enable", "generator_generator_charge_type"]
EXCLUDE = {"lcd_password", "flash_size"}

# Time windows: (title, slug prefix, section, extra conditions)
TIMES = [("AC charging window", "ac_charging_", "acchg", [AC_T_TIME]), ("Charge first window", "battery_charge_first_", "chargefirst", []),
         ("Forced discharge window", "forced_discharge_", "forced", []), ("Peak shaving window", "grid_peak_shaving_", "grid", [PS_ON]),
         ("AC priority schedule (T1-T3)", "ac_first_load_", "acfirst", []),
         ("Generator window", "generator_", "adv_gen", [])]

SECTION_VIS = {"acchg": [AC_ON], "chargefirst": [CF_ON], "forced": [FD_ON], "adv_misc": [ADV], "adv_acc": [ADV], "adv_gen": [ADV], "adv_load": [ADV]}
SECTION_META = {
    "modes": ("Operating mode", "mdi:tune", "Tap a switch to change it (you will be asked to confirm). Pickers apply at once."),
    "protect": ("Battery protection and cut-offs", "mdi:battery-heart-variant", "Only the cut-offs that apply to your control types are shown. Use the wizard for a checked change."),
    "acchg": ("Charge from grid", "mdi:transmission-tower-import", "Shown while AC charging is on. Fields follow the charge type: SOC, voltage or time."),
    "chargefirst": ("Charge first (PV)", "mdi:solar-power", "Shown while Charge first is on."),
    "forced": ("Forced discharge", "mdi:battery-arrow-down", "Shown while Forced discharge is on."),
    "acfirst": ("AC priority schedule", "mdi:clock-time-four-outline", "During these windows the grid carries the house and PV charges the battery; outside them PV and battery carry it. All 00:00 means unused."),
    "grid": ("Grid, export and peak shaving", "mdi:transmission-tower", "Export needs hybrid mode (PV & AC load jointly). The export limit shows when export is on, CT offset when a CT is fitted, peak shaving fields when it is on."),
    "adv_misc": ("Advanced: tuning and legacy", "mdi:tools", "Percent, precise and legacy twins of the kW controls, hysteresis and other tuning. Leave alone unless you know why."),
    "adv_acc": ("Advanced: AC coupling", "mdi:sine-wave", "Shown while AC coupling is on."),
    "adv_gen": ("Advanced: generator port", "mdi:engine", "The generator / smart-load port is set to Generator."),
    "adv_load": ("Advanced: smart load port", "mdi:power-plug", "The generator / smart-load port is set to Smart Load."),
}
ORDER = ["modes", "protect", "acchg", "chargefirst", "forced", "acfirst", "grid", "adv_misc", "adv_acc", "adv_gen", "adv_load"]

reg = json.load(open(REG))["data"]["entities"]
ents = {}
for e in reg:
    d, rest = e["entity_id"].split(".", 1)
    if not rest.startswith(P) or e.get("disabled_by") or d not in ("number", "switch", "select", "time"):
        continue
    s = rest[len(P):]
    if s.startswith("schedules_") or s in EXCLUDE:
        continue
    ents[s] = {"id": e["entity_id"], "d": d, "s": s, "name": (e.get("name") or e.get("original_name") or s).strip()}

times_used = {s for s, e in ents.items() if e["d"] == "time" and any(s.startswith(p) for _, p, _, _ in TIMES)}
# time entities not claimed by a window prefix are an error: they would vanish silently
unclaimed_times = [s for s, e in ents.items() if e["d"] == "time" and not any(s.startswith(p) for _, p, _, _ in TIMES)]
assert not unclaimed_times, unclaimed_times
missing = [s for s in TABLE if s not in ents]
assert not missing, f"TABLE slugs not in registry: {missing}"
status_missing = [s for s in READONLY_STATUS if s not in ents]
assert not status_missing, status_missing
placed = set(TABLE) | times_used | set(READONLY_STATUS)
installer = [e for s, e in ents.items() if s not in placed]  # everything else: read-only list


def heading(text, icon=None, style="title"):
    h = {"type": "heading", "heading": text, "heading_style": style}
    if icon:
        h["icon"] = icon
    return h


def note(text, vis=None):
    c = {"type": "custom:section-note-card", "text": text, "grid_options": {"columns": "full"}}
    if vis:
        c["visibility"] = vis
    return c


def tile(e, conds):
    t = {"type": "tile", "entity": e["id"], "name": e["name"], "grid_options": {"columns": 6}}
    if e["d"] == "switch":
        c = {"action": "toggle", "confirmation": {"text": CONFIRM.get(e["s"], f"Change {e['name']}? This changes the inverter directly.")}}
        t.update(tap_action=c, icon_tap_action=c, hold_action=NONE, double_tap_action=NONE)
    else:
        f = {"number": {"type": "numeric-input", "style": "buttons"}, "select": {"type": "select-options"}}[e["d"]]
        t.update(tap_action=NONE, icon_tap_action=NONE, hold_action=NONE, features=[f], features_position="bottom")
    if conds:
        t["visibility"] = conds
    return t


NAMES = {"take_load_together": "PV & AC load jointly (hybrid mode)", "grid_grid_ct_connection": "Grid CT connected",
         "max_backflow_power": "Export power limit (%)", "external_ct_power_offset": "CT power offset (W, +-199)"}
CONFIRM = {"take_load_together": "Change hybrid mode? On: PV, battery and grid share the load and export becomes possible. Off: off-grid behaviour. Only enable where grid-connected operation is allowed.",
           "grid_grid_ct_connection": "Change CT connection? Enable it only if an external CT is actually wired, or power control will be wrong.",
           "grid_grid_export": "Change grid export? Check the EVN agreement first: many rooftop systems must not feed the grid."}
for _s, _n in NAMES.items():
    if _s in ents:
        ents[_s]["name"] = _n
cards = {k: [] for k in ORDER}
for s, (sec, conds) in TABLE.items():
    cards[sec].append((ents[s]["name"].lower(), tile(ents[s], conds)))
for sec in cards:
    cards[sec] = [c for _, c in sorted(cards[sec], key=lambda x: x[0])]

# Warnings that explain an inconsistent state instead of hiding a driver.
cards["acchg"].insert(0, note("AC charging is on but **AC Charge Type** is *Disable*, so nothing will charge. Pick a type in *Operating mode*.", [AC_ON, st("select", "ac_charge_type", "Disable")]))
cards["modes"].append(note("AC charge type is set but the **AC charging** switch is off, so the grid will not charge the battery.", [{"condition": "state", "entity": f"switch.{P}ac_charging", "state": "off"}, {"condition": "state", "entity": f"select.{P}ac_charge_type", "state_not": "Disable"}]))

TIME_HINTS = {"ac_charging_": "Inside a window the battery does not discharge: the house runs on the grid. Start = end means unused.",
              "ac_first_load_": "Inside a window the grid carries the house and PV charges the battery. Start = end means unused.",
              "battery_charge_first_": "Inside a window PV charges the battery before the house. Start = end means unused.",
              "forced_discharge_": "Inside a window the battery is discharged at the forced-discharge power. Start = end means unused."}
for title, pre, sec, conds in TIMES:
    # windows are numbered 1..n in register order: the unsuffixed pair, then _1, then _2
    found = {}
    for slug, e in ents.items():
        m = re.match(r"^(.*)start_time(?:_(\d))?$", slug)
        if e["d"] == "time" and m and slug.startswith(pre):
            end = f"{m.group(1)}end_time" + (f"_{m.group(2)}" if m.group(2) else "")
            assert end in ents, end
            found[int(m.group(2) or 0)] = {"start": e["id"], "end": ents[end]["id"]}
    if found:
        c = {"type": "custom:time-window-card", "title": title, "windows": [found[k] for k in sorted(found)], "grid_options": {"columns": "full"}}
        if pre in TIME_HINTS:
            c["hint"] = TIME_HINTS[pre]
        if conds:
            c["visibility"] = conds
        cards[sec].append(c)

sections = []
# Quick setup: wizard + advanced toggle
sections.append({"type": "grid", "cards": [
    {"type": "custom:inverter-wizard-card", "title": "Inverter setup", "rated_kwh": 16.08, "grid_options": {"columns": "full"}},
    {"type": "tile", "entity": ADV_ENTITY, "name": "Show advanced settings", "icon": "mdi:tools", "grid_options": {"columns": 12},
     "card_mod": {"style": "ha-card {\n  --ha-tile-info-primary-font-size: .85rem;\n  --ha-tile-info-secondary-font-size: .78rem;\n}\n"},
     "tap_action": {"action": "toggle"}, "icon_tap_action": {"action": "toggle"}, "hold_action": NONE},
]})
status = {"type": "glance", "title": "Fixed by the installer", "columns": 3, "show_icon": False, "show_state": True, "grid_options": {"columns": "full"},
          "entities": [{"entity": ents[s]["id"], "name": ents[s]["name"], "tap_action": NONE, "hold_action": NONE} for s in READONLY_STATUS if s not in ("smart_load_enable", "generator_generator_charge_type")]}
# Each group is a collapsible card (www/collapsible-section-card.js). Children render lazily, so a collapsed
# group costs nothing. Core groups start open, advanced / rarely-used ones start collapsed.
OPEN = {"modes": True, "protect": True, "acchg": True, "chargefirst": True, "forced": True, "acfirst": False, "grid": True,
        "adv_misc": False, "adv_acc": False, "adv_gen": False, "adv_load": False}


def collapsible(cid, title, icon, intro, children, open_):
    return {"type": "custom:collapsible-section-card", "id": cid, "title": title, "icon": icon, "intro": intro, "open": open_,
            "cards": children, "grid_options": {"columns": "full"}}


for sec in ORDER:
    title, icon, intro = SECTION_META[sec]
    children = ([status] if sec == "modes" else []) + cards[sec]
    sect = {"type": "grid", "cards": [collapsible(sec, title, icon, intro, children, OPEN[sec])]}
    if sec in SECTION_VIS:
        sect["visibility"] = SECTION_VIS[sec]
    sections.append(sect)

by_name = lambda lst: sorted(lst, key=lambda e: e["name"].lower())
RO = [("Grid protection limits", r"^grid_(grid_|over_frequency|under_frequency)"), ("Reactive power and curves", r"^(grid_|afci|npe|nec_|bus_|over_temperature)"),
      ("Battery hardware", r"^battery_"), ]
ro_left, ro_cards = list(installer), []
for title, rx in RO:
    hit = [e for e in ro_left if re.search(rx, e["s"])]
    ro_left = [e for e in ro_left if e not in hit]
    if hit:
        ro_cards.append((title, hit))
if ro_left:
    ro_cards.append(("System and installer", ro_left))
ro_cards.append(("Smart-load / generator port and line mode", [ents[s] for s in READONLY_STATUS if s in ("working_mode", "line_mode", "smart_load_enable", "generator_generator_charge_type")]))
sections.append({"type": "grid", "visibility": [ADV], "cards": [collapsible(
    "installer", "Installer settings (read-only)", "mdi:lock",
    "Grid-code limits, reactive-power curves, lithium battery voltages (float, equalization, lead-acid), system and grid type, Working Mode. "
    "Shown only: a wrong value can trip the inverter, stop it (Standby) or breach the grid code. Change these in the LuxPower app.",
    [{"type": "glance", "title": t, "columns": 3, "show_icon": False, "show_state": True, "grid_options": {"columns": "full"},
      "entities": [{"entity": e["id"], "name": e["name"], "tap_action": NONE, "hold_action": NONE} for e in by_name(h)]} for t, h in ro_cards],
    False)]})

# ---------------------------------------------------------------------------------------------- self-check
def holds(cond, states):
    k = cond["condition"]
    if k == "and":
        return all(holds(c, states) for c in cond["conditions"])
    if k == "or":
        return any(holds(c, states) for c in cond["conditions"])
    cur = states.get(cond["entity"])
    if "state" in cond:
        want = cond["state"]
        return cur in (want if isinstance(want, list) else [want])
    return cur not in (cond["state_not"] if isinstance(cond["state_not"], list) else [cond["state_not"]])


def shown(node, states):
    return all(holds(c, states) for c in node.get("visibility", []))


def visible_entities(states):
    out = set()
    def visit(c):
        if not shown(c, states):
            return
        if c.get("entity"):
            out.add(c["entity"])
        for r in c.get("entities", []):
            out.add(r["entity"] if isinstance(r, dict) else r)
        for w in c.get("windows", []):  # time-window-card
            out.update((w["start"], w["end"]))
        for child in c.get("cards", []):  # collapsible-section-card children
            visit(child)

    for s in sections:
        if shown(s, states):
            for c in s["cards"]:
                visit(c)
    return out


def self_check(states):
    """Every condition must reference a known entity and a valid state; flip each driver and print the diff."""
    base = visible_entities(states)
    problems = []
    option_sets = {f"select.{P}ac_charge_type": ["Disable", "According to Time", "According to Voltage", "According to SOC", "According to Voltage and Time", "According to SOC and Time"],
                   f"select.{P}discharge_control_type": ["According to Voltage", "According to SOC", "According to Both"],
                   f"select.{P}grid_on_grid_eod_type": ["According to Voltage", "According to SOC"],
                   f"select.{P}smart_load_enable": ["Generator", "Smart Load"],
                   f"select.{P}output_priority_config": ["Battery First", "PV First", "AC First"]}

    def walk(node):
        for c in node.get("visibility", []):
            for leaf in ([c] if c["condition"] == "state" else c.get("conditions", [])):
                ent = leaf["entity"]
                if ent not in states and ent != ADV_ENTITY:
                    problems.append(f"unknown entity {ent}")
                for w in (leaf.get("state") if isinstance(leaf.get("state"), list) else [leaf.get("state", leaf.get("state_not"))]):
                    if ent in option_sets and w not in option_sets[ent]:
                        problems.append(f"{ent}: state {w!r} not an option")
                    if ent.startswith("switch.") and w not in ("on", "off"):
                        problems.append(f"{ent}: bad state {w!r}")
    def walk_all(c):
        walk(c)
        for child in c.get("cards", []):
            walk_all(child)

    for s in sections:
        walk_all(s)
    print(f"entities shown with current states: {len(base)}")
    flips = [(f"switch.{P}{k}", ["on", "off"]) for k in ("ac_charging", "charge_first_pv", "forced_discharge", "grid_grid_export", "grid_grid_peak_shaving", "ac_coupling", "take_load_together", "grid_grid_ct_connection")]
    flips += [(k, v) for k, v in option_sets.items()] + [(ADV_ENTITY, ["on", "off"])]
    for ent, vals in flips:
        for v in vals:
            s2 = dict(states); s2[ent] = v
            now = visible_entities(s2)
            add, rem = sorted(now - base), sorted(base - now)
            short = lambda L: [x.split(".", 1)[1].replace(P, "") for x in L]
            if add or rem:
                print(f"  {ent.split('.',1)[1].replace(P,'')} = {v}: +{len(add)} {short(add)[:6]}  -{len(rem)} {short(rem)[:6]}")
    # drivers must stay visible whatever their own state is
    for ent, vals in flips[:-1]:
        for v in vals:
            s2 = dict(states); s2[ent] = v
            s2[ADV_ENTITY] = "on"  # advanced drivers (AC coupling, smart-load port) live behind the toggle by design
            assert ent in visible_entities(s2), f"driver {ent} hides itself at {v}"
    all_ids = {e["id"] for e in ents.values()}
    every = visible_entities({**states, ADV_ENTITY: "on", **{f"switch.{P}{k}": "on" for k in ("ac_charging", "charge_first_pv", "forced_discharge", "grid_grid_export", "grid_grid_peak_shaving", "ac_coupling", "take_load_together", "grid_grid_ct_connection")},
                              f"select.{P}ac_charge_type": "According to SOC and Time", f"select.{P}discharge_control_type": "According to Both",
                              f"select.{P}grid_on_grid_eod_type": "According to SOC", f"select.{P}output_priority_config": "AC First"})
    # With Voltage-type branches, smart-load Generator vs Smart Load, EOD voltage left out of that one pass, union the rest:
    for extra in ({f"select.{P}smart_load_enable": "Smart Load"}, {f"select.{P}grid_on_grid_eod_type": "According to Voltage"}, {f"select.{P}ac_charge_type": "According to Voltage and Time"},
                  {f"select.{P}discharge_control_type": "According to Voltage"}):
        every |= visible_entities({**states, ADV_ENTITY: "on", **{f"switch.{P}{k}": "on" for k in ("ac_charging", "charge_first_pv", "forced_discharge", "grid_grid_export", "grid_grid_peak_shaving", "ac_coupling", "take_load_together", "grid_grid_ct_connection")}, **extra})
    never = sorted(e.split(".", 1)[1].replace(P, "") for e in all_ids - every)
    print(f"reachable by some combination: {len(every & all_ids)} / {len(all_ids)}; never shown: {never}")
    print("PROBLEMS:", problems or "none")
    return not problems


if CHECK:
    sys.exit(0 if self_check(STATES) else 1)

doc = json.load(open(DASH))
views = doc["data"]["config"]["views"]
settings = next(v for v in views if v.get("path") == "settings")
inv = {"title": INV_TITLE, "path": "inverter", "icon": "mdi:solar-power", "type": "sections", "max_columns": 3, "sections": sections}
# The Inverter page is its own admin-only dashboard in the sidebar, not a tab of the default dashboard.
views[:] = [v for v in views if v.get("path") != "inverter"]
if INV_DASH:
    json.dump({"version": 1, "minor_version": 1, "key": f"lovelace.{INV_ID}", "data": {"config": {"title": INV_TITLE, "views": [inv]}}},
              open(INV_DASH, "w"), indent=2, ensure_ascii=False)
if DASHBOARDS:
    reg_doc = json.load(open(DASHBOARDS))
    items = reg_doc["data"]["items"]
    item = {"id": INV_ID, "url_path": INV_URL, "title": INV_SHORT, "icon": "mdi:solar-power", "show_in_sidebar": True, "require_admin": True, "mode": "storage"}
    items[:] = [i for i in items if i.get("id") != INV_ID] + [item]
    json.dump(reg_doc, open(DASHBOARDS, "w"), indent=2, ensure_ascii=False)
for c in (c for sec in settings["sections"] for c in sec["cards"]):
    if c.get("type") == "markdown" and "Inverter settings are on the" in c.get("content", ""):
        c["content"] = re.sub(r"Inverter settings are on the \*\*[^*]+\*\*( tab| page in the sidebar)", f"Inverter settings are on the **{INV_SHORT}** page in the sidebar", c["content"])

first = settings["sections"][0]["cards"][0] if settings["sections"] else {}
if first.get("heading") == "Inverter settings":
    limits = settings["sections"][1]["cards"]
    keep = [c for c in limits if c.get("type") == "markdown" or c.get("entity") in ("switch.grid_protector", "switch.mosquito_repellent")]
    for c in keep:
        if c.get("type") == "markdown":
            c["content"] = ("Automations close the grid fallback at **30%** battery or when the house draws more than **5.5 kW**, "
                            "and open it again at **40%** once the load is under **4 kW**. Inverter settings are on the **Solar Inverter Settings** page in the sidebar.")
    settings["sections"][0:2] = [{"type": "grid", "cards": [heading("Controls", "mdi:toggle-switch-outline")] + keep}]

raw = open(DASH).read()
open(DASH, "w").write(json.dumps(doc, indent=2, ensure_ascii=False) + ("\n" if raw.endswith("\n") else ""))
print("sections:", [(s["cards"][0].get("title") or s["cards"][0].get("heading"), len(s["cards"])) for s in sections], "read-only lists:", [(t, len(h)) for t, h in ro_cards])
