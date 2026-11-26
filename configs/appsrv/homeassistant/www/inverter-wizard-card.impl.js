/*
 * inverter-wizard-card: guided, confirm-before-apply setup for the LuxPower inverter.
 *
 * Four wizards (backup reserve, charge from grid, export, battery protection). Every wizard:
 *   pick values (ranges come from each entity's own min/max/step) -> review "now -> new" -> apply.
 * Writes are sent one at a time and each is read back from the entity before the next, so a value
 * that did not stick is reported instead of silently lost. Nothing is written before "Apply".
 *
 * Config (all optional): type: custom:inverter-wizard-card
 *   rated_kwh: 16.08, prefix: luxpower_inverter_, title: "Inverter setup"
 */
(() => {
  const HOUR = 36e5;
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  class InverterWizardCard extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({ mode: "open" });
      this._wiz = null; // active wizard id
      this._step = 0;
      this._vals = {};
      this._phase = "home"; // home | edit | review | apply | done
      this._log = [];
      this._load = null;
      this.shadowRoot.addEventListener("click", (e) => this._click(e));
      this.shadowRoot.addEventListener("change", (e) => this._change(e));
      this.shadowRoot.addEventListener("input", (e) => this._input(e));
    }

    setConfig(c) {
      this._cfg = { title: "Inverter setup", rated_kwh: 16.08, prefix: "luxpower_inverter_", ...c };
      this._defs = this._buildDefs();
      this._render();
    }

    getCardSize() { return 8; }
    getGridOptions() { return { columns: "full", min_columns: 6 }; }

    set hass(h) {
      const first = !this._hass;
      this._hass = h;
      if (first) this._fetchLoad();
      // Do not re-render while the user is typing or while writes are being applied.
      if (this._phase === "home" && !this._busyRender) this._render();
    }

    // ---- entity helpers -------------------------------------------------------------------
    _id(domain, slug) { return `${domain}.${this._cfg.prefix}${slug}`; }
    _st(id) { return this._hass && this._hass.states[id]; }
    _val(id) {
      const s = this._st(id);
      if (!s) return null;
      if (s.state === "unavailable" || s.state === "unknown") return null;
      if (id.startsWith("number.")) return Number(s.state);
      return s.state;
    }
    _attr(id, a) { const s = this._st(id); return s && s.attributes ? s.attributes[a] : undefined; }

    // ---- wizard definitions ---------------------------------------------------------------
    _buildDefs() {
      const n = (s) => this._id("number", s), sw = (s) => this._id("switch", s), se = (s) => this._id("select", s), ti = (s) => this._id("time", s);
      const SOC_T = ["According to SOC", "According to SOC and Time"];
      const TIME_T = ["According to Time", "According to Voltage and Time", "According to SOC and Time"];
      const VOLT_T = ["According to Voltage", "According to Voltage and Time"];
      const val = (id) => (this._vals[id] !== undefined ? this._vals[id] : this._val(id));
      const rated = this._cfg.rated_kwh;

      return {
        reserve: {
          icon: "mdi:shield-home", title: "Backup reserve", blurb: "How much charge to keep for an outage, and how long it lasts.",
          steps: [{
            title: "Reserve level",
            fields: () => [
              { id: n("battery_on_grid_cut_off_soc"), label: "On-grid cut-off (SOC %)", help: "Below this the inverter stops discharging while the grid is on." },
              { id: n("battery_off_grid_cut_off_soc"), label: "Off-grid cut-off (SOC %)", help: "Below this the inverter stops discharging during an outage." },
              { id: se("grid_on_grid_eod_type"), label: "On-grid cut-off is measured by", help: "The SOC cut-off only acts when this is 'According to SOC'.", show: () => this._val(se("grid_on_grid_eod_type")) !== "According to SOC" || this._vals[se("grid_on_grid_eod_type")] !== undefined },
              { id: se("discharge_control_type"), label: "Discharge control", help: "The off-grid SOC cut-off needs 'According to SOC' or 'According to Both'.", show: () => this._val(se("discharge_control_type")) === "According to Voltage" || this._vals[se("discharge_control_type")] !== undefined },
            ],
            info: () => {
              const on = val(n("battery_on_grid_cut_off_soc")), off = val(n("battery_off_grid_cut_off_soc"));
              const load = this._nightLoad || 1, out = [];
              out.push("While the grid is on, the battery is used down to the <b>on-grid</b> cut-off, then the house moves to the grid. In an outage it may go lower, down to the <b>off-grid</b> cut-off. So the <b>outage reserve is the gap between them</b>.");
              if (on != null && off != null) {
                const gap = Math.max(0, on - off), kwh = (gap * rated) / 100;
                out.push(`Reserve now: ${on}% − ${off}% = <b>${gap}%</b> = <b>${kwh.toFixed(1)} kWh</b>, about <b>${(kwh / load).toFixed(1)} h</b> at your usual night load of ${load.toFixed(2)} kW.`);
              }
              out.push("After a cut-off, discharge resumes only once the charge recovers by about 10% (the inverter's recovery threshold).");
              out.push("The grid-fallback automations bring the grid in at 30% and drop it at 40%, which already keeps a reserve above a low on-grid cut-off.");
              return out;
            },
            warn: () => {
              const w = [], on = val(n("battery_on_grid_cut_off_soc")), off = val(n("battery_off_grid_cut_off_soc"));
              if (on != null && off != null && off > on) w.push("Off-grid cut-off is above the on-grid one. The inverter expects on-grid ≥ off-grid, otherwise an outage gets less battery than normal use.");
              if (on != null && on < 10) w.push("On-grid cut-off below 10% risks deep-discharging the lithium cells.");
              if (off != null && off < 10) w.push("Off-grid cut-off under 10% risks deep discharge and BMS trips.");
              return w;
            },
          }],
        },

        acgrid: {
          icon: "mdi:transmission-tower-import", title: "Charge from grid", blurb: "Top the battery up from EVN in a time window or below a charge level.",
          steps: [
            {
              title: "Turn it on",
              fields: () => [
                { id: sw("ac_charging"), label: "AC charging", help: "Master switch. Off means the battery never charges from the grid." },
                { id: se("ac_charge_type"), label: "Charge when", help: "'According to SOC and Time' charges only inside the time window and only below the SOC you set.", show: () => val(sw("ac_charging")) === "on" },
              ],
              info: () => [`Tariff now: <b>${esc(this._val("sensor.ecm_active_tariff") || "unknown")}</b>. Charging from the grid only saves money if the tariff makes those hours cheaper.`].concat(TIME_T.includes(val(se("ac_charge_type"))) ? ["Types with <b>Time</b> also stop the battery from discharging during the window, so the house runs on the grid then."] : []),
            },
            {
              title: "Charge level",
              skip: () => val(sw("ac_charging")) !== "on" || !(SOC_T.includes(val(se("ac_charge_type"))) || VOLT_T.includes(val(se("ac_charge_type")))),
              fields: () => {
                const t = val(se("ac_charge_type")), f = [];
                if (SOC_T.includes(t)) f.push(
                  { id: n("battery_ac_charge_start_soc"), label: "Start charging below (SOC %)" },
                  { id: n("battery_ac_charge_end_soc"), label: "Stop charging at (SOC %)", also: [n("battery_ac_charge_soc_limit")] });
                if (VOLT_T.includes(t)) f.push(
                  { id: n("battery_ac_charge_start_voltage"), label: "Start charging below (V)" },
                  { id: n("battery_ac_charge_end_voltage"), label: "Stop charging at (V)" });
                return f;
              },
              warn: () => {
                const s = val(n("battery_ac_charge_start_soc")), e = val(n("battery_ac_charge_end_soc"));
                return s != null && e != null && s >= e ? ["Start level must be lower than the stop level."] : [];
              },
            },
            {
              title: "Power",
              skip: () => val(sw("ac_charging")) !== "on",
              fields: () => [
                { id: n("ac_charge_power_kw"), label: "Charge power from grid (kW)", help: "The inverter is rated 6.5 kW; this adds to the house load on the supply." },
                { id: n("battery_ac_charge_current_from_grid"), label: "Max charge current (A)" },
              ],
              info: () => [`BMS accepts up to <b>${this._val(this._id("sensor", "battery_bms_max_charge_current")) ?? "?"} A</b>.`],
            },
            {
              title: "Time window",
              skip: () => val(sw("ac_charging")) !== "on" || !TIME_T.includes(val(se("ac_charge_type"))),
              fields: () => [
                { id: ti("ac_charging_start_time"), label: "Start" },
                { id: ti("ac_charging_end_time"), label: "End" },
              ],
              info: () => ["Window 1 of 3 (the inverter has three). Start equal to end means no window.", "Inside the window the battery does not discharge: the house runs on the grid."],
            },
          ],
        },

        export: {
          icon: "mdi:transmission-tower-export", title: "Hybrid and export", blurb: "Share the load with the grid, allow export, and calibrate the CT.",
          steps: [
            {
              title: "Hybrid mode",
              fields: () => [
                { id: sw("take_load_together"), label: "PV & AC load jointly (hybrid mode)", help: "On: PV, battery and grid share the load and export becomes possible. Off: off-grid behaviour, the grid only takes over below the cut-off." },
                { id: sw("grid_grid_ct_connection"), label: "Grid CT connected", help: "Turn on only if an external CT is wired. Hybrid and zero-export control rely on it." },
              ],
              info: () => ["Use hybrid mode only where grid-connected operation is allowed. The manufacturer says not to enable it where grid-connection rules forbid it."],
              warn: () => (val(sw("take_load_together")) === "on" && val(sw("grid_grid_ct_connection")) !== "on" ? ["Hybrid mode without a CT cannot control grid power accurately."] : []),
            },
            {
              title: "Export",
              skip: () => val(sw("take_load_together")) !== "on",
              fields: () => [
                { id: sw("grid_grid_export"), label: "Allow export to grid", help: "Off keeps every watt in the house." },
                { id: n("max_backflow_power"), label: "Export power limit (%)", help: "Share of power that may be sent to the grid.", show: () => val(sw("grid_grid_export")) === "on" },
                { id: n("external_ct_power_offset"), label: "CT power offset (W)", help: "Calibration, about ±199 W. If a zero-export system still shows small feed-in, use a negative offset (e.g. −50). If it keeps drawing from the grid, use a positive one.", show: () => val(sw("grid_grid_ct_connection")) === "on" },
              ],
              info: () => ["Check the EVN agreement before enabling export: many rooftop systems must not feed the grid, and exported energy may not be paid."],
            },
            {
              title: "Import limit",
              fields: () => [{ id: n("grid_max_grid_input_power"), label: "Max grid input power (kW)", help: "Upper limit on what the inverter draws from the grid. The inverter's maximum is 9 kW." }],
            },
          ],
        },

        protect: {
          icon: "mdi:battery-heart-variant", title: "Battery protection", blurb: "Charge ceiling, low-charge alarm and current limits, checked against the BMS.",
          steps: [
            {
              title: "Charge ceiling and alarm",
              fields: () => [
                { id: n("battery_battery_stop_charging_soc"), label: "Stop charging at (SOC %)", help: "100 or above means no ceiling. 90-95% reduces cell stress if you rarely need the last 10%.", quick: [90, 95, 100] },
                { id: n("battery_battery_low_soc_alarm"), label: "Low-charge alarm (SOC %)", help: "Raises a warning on the inverter at this level." },
              ],
            },
            {
              title: "Current limits",
              fields: () => [
                { id: n("battery_charge_current"), label: "Max charge current (A)" },
                { id: n("battery_discharge_current"), label: "Max discharge current (A)" },
              ],
              info: () => [
                `BMS limits now: charge <b>${this._val(this._id("sensor", "battery_bms_max_charge_current")) ?? "?"} A</b>, discharge <b>${this._val(this._id("sensor", "battery_bms_max_discharge_current")) ?? "?"} A</b>. The BMS enforces its own limit, so a higher value does nothing useful.`,
                "Inverter maximum (SNA PRO 6.5K): 135 A charge, 140 A discharge, 6.5 kW.",
              ],
              warn: () => {
                const w = [];
                const cc = val(n("battery_charge_current")), dc = val(n("battery_discharge_current"));
                const bc = this._val(this._id("sensor", "battery_bms_max_charge_current")), bd = this._val(this._id("sensor", "battery_bms_max_discharge_current"));
                if (cc != null && bc != null && cc > Number(bc)) w.push(`Charge current ${cc} A is above the BMS limit (${bc} A).`);
                if (dc != null && bd != null && dc > Number(bd)) w.push(`Discharge current ${dc} A is above the BMS limit (${bd} A).`);
                return w;
              },
            },
          ],
        },
      };
    }

    // ---- logic ----------------------------------------------------------------------------
    async _fetchLoad() {
      try {
        const c = this._cfg;
        const end = new Date(), start = new Date(end.getTime() - 8 * 24 * HOUR);
        const id = `sensor.${c.prefix}discharge_energy_today`;
        const res = await this._hass.callWS({ type: "recorder/statistics_during_period", start_time: start.toISOString(), end_time: end.toISOString(), statistic_ids: [id], period: "hour", types: ["change"] });
        const rows = res[id] || [];
        let kwh = 0, nights = 0;
        const byDay = {};
        rows.forEach((r) => {
          const t = new Date(typeof r.start === "number" ? r.start : r.start), h = t.getHours();
          if (h >= 18 || h < 6) {
            const d = new Date(t); if (h < 6) d.setDate(d.getDate() - 1);
            const k = d.toDateString(); byDay[k] = (byDay[k] || 0) + Math.max(0, r.change || 0);
          }
        });
        Object.values(byDay).forEach((v) => { if (v > 3) { kwh += v; nights++; } });
        if (nights) this._nightLoad = kwh / nights / 12;
      } catch (e) { /* fall back to 1 kW */ }
    }

    _wizard() { return this._defs[this._wiz]; }

    _activeSteps() { return this._wizard().steps.map((s, i) => [s, i]).filter(([s]) => !(s.skip && s.skip())); }

    _fieldsOf(step) { return (step.fields() || []).filter((f) => !f.show || f.show()); }

    _allFields() {
      const out = [];
      this._activeSteps().forEach(([s]) => this._fieldsOf(s).forEach((f) => out.push(f)));
      return out;
    }

    _changes() {
      const ch = [];
      this._allFields().forEach((f) => {
        const cur = this._val(f.id), nv = this._vals[f.id];
        if (nv === undefined) return;
        if (!this._same(f.id, cur, nv)) ch.push({ ...f, from: cur, to: nv });
        (f.also || []).forEach((id) => { const c2 = this._val(id); if (!this._same(id, c2, nv)) ch.push({ id, label: `${f.label} (mirror)`, from: c2, to: nv }); });
      });
      return ch;
    }

    _same(id, a, b) {
      if (id.startsWith("number.")) return Math.abs(Number(a) - Number(b)) < 1e-6;
      if (id.startsWith("time.")) return String(a).slice(0, 5) === String(b).slice(0, 5);
      return a === b;
    }

    _blockers() {
      const b = [];
      this._allFields().forEach((f) => { const s = this._st(f.id); if (!s || s.state === "unavailable") b.push(`${f.label} is unavailable.`); });
      return b;
    }

    async _apply() {
      const ch = this._changes();
      this._phase = "apply";
      this._log = ch.map((c) => ({ c, status: "waiting" }));
      this._render();
      for (const item of this._log) {
        item.status = "writing"; this._render();
        try {
          await this._write(item.c.id, item.c.to);
          const ok = await this._waitFor(item.c.id, item.c.to);
          item.status = ok ? "ok" : "unconfirmed";
        } catch (e) { item.status = "failed"; item.err = (e && e.message) || String(e); }
        this._render();
      }
      this._phase = "done"; this._render();
    }

    _write(id, v) {
      const d = id.split(".")[0], h = this._hass;
      if (d === "number") return h.callService("number", "set_value", { entity_id: id, value: Number(v) });
      if (d === "switch") return h.callService("switch", v === "on" ? "turn_on" : "turn_off", { entity_id: id });
      if (d === "select") return h.callService("select", "select_option", { entity_id: id, option: v });
      if (d === "time") return h.callService("time", "set_value", { entity_id: id, time: String(v).length === 5 ? v + ":00" : v });
      throw new Error("unsupported entity " + id);
    }

    async _waitFor(id, want) {
      for (let i = 0; i < 40; i++) { // up to ~20 s
        if (this._same(id, this._val(id), want)) return true;
        await sleep(500);
      }
      return false;
    }

    // ---- events ---------------------------------------------------------------------------
    _click(e) {
      const t = e.composedPath().find((n) => n.dataset && n.dataset.act);
      if (!t) return;
      const a = t.dataset.act;
      if (a === "start") { this._wiz = t.dataset.w; this._vals = {}; this._step = 0; this._phase = "edit"; this._gotoValid(0, 1); }
      else if (a === "home") { this._phase = "home"; this._wiz = null; this._vals = {}; }
      else if (a === "next") { this._gotoValid(this._step + 1, 1); }
      else if (a === "back") { this._gotoValid(this._step - 1, -1); }
      else if (a === "apply") { this._apply(); return; }
      else if (a === "edit") { this._phase = "edit"; }
      else if (a === "quick") { this._vals[t.dataset.id] = Number(t.dataset.v); }
      else if (a === "toggle") { const id = t.dataset.id; this._vals[id] = (this._vals[id] ?? this._val(id)) === "on" ? "off" : "on"; }
      this._render();
    }

    // Move to the next non-skipped step in a direction; past the last one go to review.
    _gotoValid(i, dir) {
      const steps = this._wizard().steps;
      while (i >= 0 && i < steps.length && steps[i].skip && steps[i].skip()) i += dir;
      if (i < 0) { this._phase = "home"; this._wiz = null; return; }
      if (i >= steps.length) { this._phase = "review"; return; }
      this._phase = "edit"; this._step = i;
    }

    _change(e) {
      const el = e.target; const id = el.dataset && el.dataset.id; if (!id) return;
      if (el.dataset.kind === "number") this._vals[id] = Number(el.value);
      else if (el.dataset.kind === "select") this._vals[id] = el.value;
      else if (el.dataset.kind === "time") this._vals[id] = el.value;
      this._render();
    }

    _input(e) {
      const el = e.target;
      if (el.dataset && el.dataset.kind === "number" && el.type === "range") {
        const out = this.shadowRoot.querySelector(`[data-out="${el.dataset.id}"]`);
        if (out) out.value = el.value;
      }
    }

    // ---- rendering ------------------------------------------------------------------------
    _fmt(id, v) {
      if (v == null) return "–";
      const u = this._attr(id, "unit_of_measurement");
      return id.startsWith("number.") ? `${v}${u ? " " + u : ""}` : id.startsWith("switch.") ? (v === "on" ? "On" : "Off") : String(v).slice(0, 5 + (id.startsWith("time.") ? 0 : 100));
    }

    _field(f) {
      const id = f.id, cur = this._val(id), v = this._vals[id] !== undefined ? this._vals[id] : cur;
      const head = `<div class="fl"><label>${esc(f.label)}</label>${f.help ? `<div class="help">${esc(f.help)}</div>` : ""}</div>`;
      let ctl = "";
      if (id.startsWith("number.")) {
        const mn = f.min ?? this._attr(id, "min") ?? 0, mx = f.max ?? this._attr(id, "max") ?? 100, st = f.step ?? this._attr(id, "step") ?? 1;
        const u = this._attr(id, "unit_of_measurement") || "";
        ctl = `<div class="num"><input type="range" data-kind="number" data-id="${id}" min="${mn}" max="${mx}" step="${st}" value="${v ?? mn}">
               <input type="number" class="nb" data-kind="number" data-id="${id}" data-out="${id}" min="${mn}" max="${mx}" step="${st}" value="${v ?? ""}"><span class="u">${esc(u)}</span></div>
               ${(f.quick || []).map((q) => `<button class="chip" data-act="quick" data-id="${id}" data-v="${q}">${q}</button>`).join("")}
               <div class="rng">allowed ${mn} to ${mx}${u ? " " + esc(u) : ""}</div>`;
      } else if (id.startsWith("switch.")) {
        ctl = `<button class="sw ${v === "on" ? "on" : ""}" data-act="toggle" data-id="${id}" aria-pressed="${v === "on"}"><span></span></button><span class="swl">${v === "on" ? "On" : "Off"}</span>`;
      } else if (id.startsWith("select.")) {
        const opts = this._attr(id, "options") || [];
        ctl = `<select data-kind="select" data-id="${id}">${opts.map((o) => `<option ${o === v ? "selected" : ""}>${esc(o)}</option>`).join("")}</select>`;
      } else if (id.startsWith("time.")) {
        ctl = `<input type="time" data-kind="time" data-id="${id}" value="${esc(String(v ?? "00:00").slice(0, 5))}">`;
      }
      return `<div class="field">${head}<div class="ctl">${ctl}</div></div>`;
    }

    _render() {
      if (!this._cfg || !this._defs) return;
      let body = "";
      if (!this._hass) body = `<div class="muted">Loading…</div>`;
      else if (this._phase === "home") body = this._renderHome();
      else if (this._phase === "edit") body = this._renderEdit();
      else if (this._phase === "review") body = this._renderReview();
      else body = this._renderApply();
      this.shadowRoot.innerHTML = `${this._style()}<ha-card><div class="hd">${esc(this._cfg.title)}</div>${body}</ha-card>`;
    }

    _renderHome() {
      const sv = (s) => this._val(this._id("select", s)), sh = (s) => this._val(this._id("switch", s)), nv = (s) => this._val(this._id("number", s));
      const rows = [
        ["Output priority", sv("output_priority_config")], ["Grid charging", sh("ac_charging") === "on" ? `On (${sv("ac_charge_type")})` : "Off"],
        ["Grid export", sh("grid_grid_export") === "on" ? "On" : "Off"], ["Cut-off (grid / outage)", `${nv("battery_on_grid_cut_off_soc") ?? "–"}% / ${nv("battery_off_grid_cut_off_soc") ?? "–"}%`],
        ["Stop charging at", `${nv("battery_battery_stop_charging_soc") ?? "–"}%`],
      ];
      return `<div class="sum">${rows.map(([k, v]) => `<div><span>${esc(k)}</span><b>${esc(v ?? "–")}</b></div>`).join("")}</div>
        <div class="grid">${Object.entries(this._defs).map(([k, w]) => `<button class="wz" data-act="start" data-w="${k}"><ha-icon icon="${w.icon}"></ha-icon><b>${esc(w.title)}</b><span>${esc(w.blurb)}</span></button>`).join("")}</div>`;
    }

    _progress() {
      const steps = this._activeSteps(); const idx = steps.findIndex(([, i]) => i === this._step);
      return `<div class="prog">${steps.map(([s], i) => `<span class="${i === idx ? "cur" : i < idx ? "done" : ""}">${i + 1}. ${esc(s.title)}</span>`).join("")}<span>${steps.length + 1}. Review</span></div>`;
    }

    _renderEdit() {
      const w = this._wizard(), step = w.steps[this._step];
      const fields = this._fieldsOf(step);
      const info = (step.info ? step.info() : []).map((t) => `<div class="info">${t}</div>`).join("");
      const warn = (step.warn ? step.warn() : []).map((t) => `<div class="warn">${esc(t)}</div>`).join("");
      return `<div class="wt"><button class="lnk" data-act="home">‹ All wizards</button><b>${esc(w.title)}</b></div>${this._progress()}
        <div class="st">${esc(step.title)}</div>${fields.map((f) => this._field(f)).join("")}${info}${warn}
        <div class="nav"><button class="btn" data-act="back">Back</button><button class="btn pri" data-act="next">Next</button></div>`;
    }

    _renderReview() {
      const w = this._wizard(), ch = this._changes(), blockers = this._blockers();
      const warns = [];
      this._activeSteps().forEach(([s]) => (s.warn ? s.warn() : []).forEach((x) => warns.push(x)));
      const rows = ch.length ? `<table><tr><th>Setting</th><th>Now</th><th>New</th></tr>${ch.map((c) => `<tr><td>${esc(c.label)}</td><td>${esc(this._fmt(c.id, c.from))}</td><td><b>${esc(this._fmt(c.id, c.to))}</b></td></tr>`).join("")}</table>`
        : `<div class="muted">Nothing to change: every value already matches.</div>`;
      const stop = blockers.length || warns.some((x) => /above the BMS|must be lower/.test(x));
      return `<div class="wt"><button class="lnk" data-act="home">‹ All wizards</button><b>${esc(w.title)}: review</b></div>${this._progress()}
        ${rows}${blockers.map((b) => `<div class="warn">${esc(b)}</div>`).join("")}${warns.map((x) => `<div class="warn">${esc(x)}</div>`).join("")}
        <div class="note">The inverter changes only when you press Apply. Values are written one by one and read back.</div>
        <div class="nav"><button class="btn" data-act="edit">Back</button><button class="btn pri" data-act="apply" ${ch.length && !stop ? "" : "disabled"}>Apply ${ch.length} change${ch.length === 1 ? "" : "s"}</button></div>`;
    }

    _renderApply() {
      const icon = { waiting: "·", writing: "…", ok: "✓", unconfirmed: "?", failed: "✕" };
      const label = { waiting: "waiting", writing: "writing", ok: "confirmed", unconfirmed: "sent, not confirmed in 20 s", failed: "failed" };
      const done = this._phase === "done";
      const bad = this._log.some((l) => l.status === "failed" || l.status === "unconfirmed");
      return `<div class="wt"><b>${esc(this._wizard().title)}: ${done ? (bad ? "finished with problems" : "done") : "applying…"}</b></div>
        <table>${this._log.map((l) => `<tr class="${l.status}"><td>${icon[l.status]}</td><td>${esc(l.c.label)}</td><td>${esc(this._fmt(l.c.id, l.c.to))}</td><td>${label[l.status]}${l.err ? ": " + esc(l.err) : ""}</td></tr>`).join("")}</table>
        ${done ? `<div class="nav"><button class="btn pri" data-act="home">Finish</button></div>` : ""}`;
    }

    _style() {
      return `<style>
        :host{display:block} ha-card{padding:14px 16px 16px}
        .hd{font-size:1.05rem;font-weight:500;color:var(--primary-text-color);margin-bottom:8px}
        .sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;margin-bottom:12px}
        .sum div{background:var(--secondary-background-color);border-radius:8px;padding:6px 10px;font-size:.78rem;color:var(--secondary-text-color);display:flex;flex-direction:column}
        .sum b{color:var(--primary-text-color);font-size:.85rem;font-weight:500}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
        .wz{font:inherit;text-align:left;border:1px solid var(--divider-color);background:var(--card-background-color);color:var(--primary-text-color);border-radius:12px;padding:12px;cursor:pointer;display:flex;flex-direction:column;gap:4px}
        .wz:hover{border-color:var(--primary-color)} .wz ha-icon{color:var(--primary-color);--mdc-icon-size:26px}
        .wz b{font-size:.875rem}
        .wz span{font-size:.78rem;color:var(--secondary-text-color)}
        .wt{font-size:.875rem;display:flex;align-items:center;gap:10px;margin-bottom:6px;color:var(--primary-text-color)}
        .lnk{font:inherit;font-size:.85rem;border:0;background:none;color:var(--primary-color);cursor:pointer;padding:0}
        .prog{display:flex;flex-wrap:wrap;gap:6px;font-size:.78rem;color:var(--secondary-text-color);margin:4px 0 10px}
        .prog span{padding:2px 8px;border-radius:10px;background:var(--secondary-background-color)}
        .prog .cur{background:var(--primary-color);color:var(--text-primary-color,#fff)} .prog .done{opacity:.6}
        .st{font-size:.875rem;font-weight:500;color:var(--primary-text-color);margin-bottom:6px}
        .field{display:grid;grid-template-columns:minmax(150px,1fr) minmax(180px,1.3fr);gap:8px 14px;align-items:center;padding:8px 0;border-top:1px solid var(--divider-color)}
        @media(max-width:560px){.field{grid-template-columns:1fr}}
        .fl label{color:var(--primary-text-color);font-size:.85rem} .help{font-size:.78rem;color:var(--secondary-text-color);margin-top:2px}
        .num{display:flex;align-items:center;gap:8px} .num input[type=range]{flex:1;min-width:80px}
        .nb{width:70px;font:inherit;font-size:.85rem;padding:4px 6px;border:1px solid var(--divider-color);border-radius:6px;background:var(--card-background-color);color:var(--primary-text-color)}
        .u{font-size:.85rem;color:var(--secondary-text-color)} .rng{font-size:.78rem;color:var(--secondary-text-color);margin-top:2px}
        select,input[type=time]{font:inherit;font-size:.85rem;padding:6px;border:1px solid var(--divider-color);border-radius:6px;background:var(--card-background-color);color:var(--primary-text-color);max-width:100%}
        .chip{font:inherit;font-size:.78rem;margin:4px 4px 0 0;padding:2px 10px;border:1px solid var(--divider-color);border-radius:12px;background:none;color:var(--primary-text-color);cursor:pointer}
        .sw{width:42px;height:24px;border-radius:12px;border:0;background:var(--disabled-text-color,#999);position:relative;cursor:pointer;vertical-align:middle}
        .sw span{position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:9px;background:#fff;transition:left .15s}
        .sw.on{background:var(--primary-color)} .sw.on span{left:21px} .swl{margin-left:8px;font-size:.85rem;color:var(--primary-text-color)}
        .info{font-size:.78rem;color:var(--primary-text-color);background:var(--secondary-background-color);border-radius:8px;padding:8px 10px;margin-top:8px}
        .warn{font-size:.78rem;color:var(--error-color,#c62828);border:1px solid var(--error-color,#c62828);border-radius:8px;padding:6px 10px;margin-top:8px}
        .note,.muted{font-size:.78rem;color:var(--secondary-text-color);margin-top:8px}
        table{width:100%;border-collapse:collapse;font-size:.85rem;color:var(--primary-text-color);margin-top:6px} th{text-align:left;font-weight:500;color:var(--secondary-text-color);font-size:.78rem}
        td,th{padding:5px 6px;border-top:1px solid var(--divider-color)} tr.ok td:first-child{color:var(--success-color,#2e7d32)} tr.failed td,tr.unconfirmed td:first-child{color:var(--error-color,#c62828)}
        .nav{display:flex;justify-content:space-between;margin-top:14px}
        .btn{font:inherit;font-size:.85rem;padding:8px 18px;border-radius:8px;border:1px solid var(--divider-color);background:none;color:var(--primary-text-color);cursor:pointer}
        .btn.pri{background:var(--primary-color);color:var(--text-primary-color,#fff);border-color:var(--primary-color)} .btn:disabled{opacity:.4;cursor:default}
      </style>`;
    }
  }

  if (!customElements.get("inverter-wizard-card")) customElements.define("inverter-wizard-card", InverterWizardCard);
  window.customCards = window.customCards || [];
  window.customCards.push({ type: "inverter-wizard-card", name: "Inverter setup wizard", description: "Guided, confirm-before-apply LuxPower inverter setup" });
})();
