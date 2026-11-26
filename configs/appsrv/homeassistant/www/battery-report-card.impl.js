/*
 * battery-report-card: battery discharge report with a Day / Week / Month / Year picker.
 *
 * Shows, for the selected period:
 *   - total discharge energy (kWh) and the overnight part of it
 *   - measured real capacity (kWh) and health (% of the rated capacity)
 *
 * The inverter's own SoH sensor is a constant 100 %, so capacity is measured instead:
 *   capacity = (kWh discharged - kWh charged) / SoC drop, over each night (no PV).
 * Only nights with a SoC drop of at least `min_drop` points count. The discharge counter is
 * the inverter's, so conversion loss is included and the figure reads slightly high.
 *
 * Data comes from the recorder's hourly long-term statistics (recorder/statistics_during_period).
 *
 * Config (all optional):
 *   type: custom:battery-report-card
 *   title: Battery report
 *   rated_kwh: 16.08       # nameplate capacity
 *   night_start: 18        # overnight window, local hours
 *   night_end: 6
 *   min_drop: 15           # minimum SoC drop (points) for a night to count for capacity
 *   discharge_entity / charge_entity / soc_entity
 */
(() => {
  const HOUR = 36e5;
  const MODES = ["day", "week", "month", "year"];
  const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  const WDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const C_NIGHT = "#3f51b5";
  const C_DAY = "#ffb300";
  const C_CAP = "#43a047";
  const C_PV = "#26a69a";   // charged from PV
  const C_GRID = "#e53935"; // charged from the grid

  const num = (v, d = 1) => (v == null || !isFinite(v) ? "–" : Number(v).toFixed(d));
  const sod = (d) => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; };
  const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const sum = (a) => a.reduce((s, v) => s + (v || 0), 0);

  function rangeFor(mode, anchor) {
    const a = sod(anchor);
    if (mode === "day") return [a, addDays(a, 1)];
    if (mode === "week") { const s = addDays(a, -((a.getDay() + 6) % 7)); return [s, addDays(s, 7)]; }
    if (mode === "month") return [new Date(a.getFullYear(), a.getMonth(), 1), new Date(a.getFullYear(), a.getMonth() + 1, 1)];
    return [new Date(a.getFullYear(), 0, 1), new Date(a.getFullYear() + 1, 0, 1)];
  }

  function shiftAnchor(mode, anchor, dir) {
    const d = new Date(anchor);
    if (mode === "day") d.setDate(d.getDate() + dir);
    else if (mode === "week") d.setDate(d.getDate() + 7 * dir);
    else if (mode === "month") { d.setDate(1); d.setMonth(d.getMonth() + dir); }
    else { d.setMonth(0, 1); d.setFullYear(d.getFullYear() + dir); }
    return d;
  }

  function rangeLabel(mode, s, e) {
    if (mode === "day") return `${WDAYS[(s.getDay() + 6) % 7]} ${s.getDate()} ${MONTHS[s.getMonth()]} ${s.getFullYear()}`;
    if (mode === "week") { const l = addDays(e, -1); return `${s.getDate()} ${MONTHS[s.getMonth()]} – ${l.getDate()} ${MONTHS[l.getMonth()]} ${l.getFullYear()}`; }
    if (mode === "month") return `${MONTHS[s.getMonth()]} ${s.getFullYear()}`;
    return `${s.getFullYear()}`;
  }

  // Buckets shown on the chart: hours (day), days (week/month), months (year).
  function bucketsFor(mode, s, e) {
    const out = [];
    if (mode === "day") {
      for (let i = 0; i < 24; i++) { const b = new Date(s); b.setHours(i); out.push([b, new Date(b.getTime() + HOUR), String(i)]); }
    } else if (mode === "year") {
      for (let m = 0; m < 12; m++) out.push([new Date(s.getFullYear(), m, 1), new Date(s.getFullYear(), m + 1, 1), MONTHS[m]]);
    } else {
      for (let d = new Date(s); d < e; d = addDays(d, 1)) {
        out.push([d, addDays(d, 1), mode === "week" ? WDAYS[(d.getDay() + 6) % 7] : String(d.getDate())]);
      }
    }
    return out;
  }

  class BatteryReportCard extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({ mode: "open" });
      this._anchor = new Date();
      this._mode = "week";
      try { const m = localStorage.getItem("battery-report-mode"); if (MODES.includes(m)) this._mode = m; } catch (e) { /* ignore */ }
      this._seq = 0;
      this._data = null;
      this._error = null;
      this._fetchedAt = 0;
      this._detail = "";
      this.shadowRoot.addEventListener("click", (ev) => this._onClick(ev));
    }

    setConfig(config) {
      const L = "sensor.luxpower_inverter_";
      this._cfg = {
        title: "Battery report",
        rated_kwh: 16.08,
        night_start: 18,
        night_end: 6,
        min_drop: 15,
        discharge_entity: L + "discharge_energy_today",
        charge_entity: L + "charge_energy_today",
        ac_charge_entity: L + "ac_charge_energy_today",
        soc_entity: L + "battery_battery_soc",
        ...config,
      };
      this._data = null;
      this._render();
    }

    getCardSize() { return 9; }
    getGridOptions() { return { columns: "full", min_columns: 6 }; }

    set hass(hass) {
      this._hassObj = hass;
      if (!this._cfg) return;
      const [, e] = rangeFor(this._mode, this._anchor);
      const live = e.getTime() > Date.now();
      if (!this._loading && (!this._data || (live && Date.now() - this._fetchedAt > 300000))) this._load();
    }

    _onClick(ev) {
      const t = ev.composedPath().find((n) => n.dataset && (n.dataset.mode || n.dataset.nav || n.dataset.detail != null));
      if (!t) return;
      if (t.dataset.mode) {
        this._mode = t.dataset.mode;
        try { localStorage.setItem("battery-report-mode", this._mode); } catch (e) { /* ignore */ }
        this._detail = "";
        this._load();
      } else if (t.dataset.nav) {
        const dir = t.dataset.nav === "prev" ? -1 : t.dataset.nav === "next" ? 1 : 0;
        this._anchor = dir ? shiftAnchor(this._mode, this._anchor, dir) : new Date();
        this._detail = "";
        this._load();
      } else if (t.dataset.detail != null) {
        this._detail = t.dataset.detail;
        this._render();
      }
    }

    async _load() {
      if (!this._hassObj || !this._cfg) return;
      const seq = ++this._seq;
      this._loading = true;
      const c = this._cfg;
      const [s, e] = rangeFor(this._mode, this._anchor);
      const start = new Date(s.getTime() - 2 * HOUR);
      const end = new Date(Math.min(addDays(e, 1).getTime(), Date.now() + HOUR));
      try {
        const res = await this._hassObj.callWS({
          type: "recorder/statistics_during_period",
          start_time: start.toISOString(),
          end_time: end.toISOString(),
          statistic_ids: [c.discharge_entity, c.charge_entity, c.ac_charge_entity, c.soc_entity],
          period: "hour",
          types: ["change", "mean"],
        });
        if (seq !== this._seq) return;
        this._data = this._index(res);
        this._error = null;
        this._fetchedAt = Date.now();
      } catch (err) {
        if (seq !== this._seq) return;
        this._error = (err && err.message) || String(err);
      } finally {
        if (seq === this._seq) { this._loading = false; this._render(); }
      }
    }

    _index(res) {
      const c = this._cfg;
      const toMs = (v) => (typeof v === "number" ? v : new Date(v).getTime());
      const idx = (id, key) => {
        const m = new Map();
        (res[id] || []).forEach((r) => { if (r[key] != null) m.set(toMs(r.start), r[key]); });
        return m;
      };
      const dis = idx(c.discharge_entity, "change");
      const chg = idx(c.charge_entity, "change");
      const ac = idx(c.ac_charge_entity, "change");
      const soc = idx(c.soc_entity, "mean");
      let last = 0;
      dis.forEach((_, t) => { if (t + HOUR > last) last = t + HOUR; });
      return { dis, chg, ac, soc, last };
    }

    _socAt(t) {
      const { soc } = this._data;
      const v = [soc.get(t - HOUR), soc.get(t)].filter((x) => x != null);
      return v.length ? sum(v) / v.length : null;
    }

    // One overnight window, starting on the evening of calendar day d.
    _night(d) {
      const c = this._cfg;
      const ns = new Date(d); ns.setHours(c.night_start, 0, 0, 0);
      const ne = addDays(d, c.night_end <= c.night_start ? 1 : 0); ne.setHours(c.night_end, 0, 0, 0);
      let dis = 0, chg = 0, n = 0, hrs = 0;
      for (let t = ns.getTime(); t < ne.getTime(); t += HOUR) {
        hrs++;
        if (this._data.dis.has(t)) { dis += Math.max(0, this._data.dis.get(t)); n++; }
        if (this._data.chg.has(t)) chg += Math.max(0, this._data.chg.get(t));
      }
      const complete = ne.getTime() <= this._data.last && n >= hrs - 1;
      const s0 = this._socAt(ns.getTime());
      const s1 = this._socAt(ne.getTime());
      const drop = s0 != null && s1 != null ? s0 - s1 : null;
      const net = dis - chg;
      const ok = complete && drop != null && drop >= c.min_drop && net > 0;
      return { start: ns, dis, net, drop, ok, cap: ok ? (net / drop) * 100 : null };
    }

    _compute() {
      const [rs, re] = rangeFor(this._mode, this._anchor);
      const buckets = bucketsFor(this._mode, rs, re);
      const days = [];
      for (let d = new Date(rs); d < re; d = addDays(d, 1)) days.push(this._night(d));
      const hourVal = (map, t) => Math.max(0, map.get(t) || 0);

      const allRows = buckets.map(([bs, be, label]) => {
        let total = 0, charged = 0, grid = 0;
        for (let t = bs.getTime(); t < be.getTime(); t += HOUR) {
          total += hourVal(this._data.dis, t);
          charged += hourVal(this._data.chg, t);
          grid += hourVal(this._data.ac, t);
        }
        grid = Math.min(grid, charged); // grid-charged is a part of all charging
        let night = 0, net = 0, drop = 0, nights = 0;
        if (this._mode === "day") {
          const h = bs.getHours();
          const c = this._cfg;
          const inNight = c.night_end <= c.night_start ? (h >= c.night_start || h < c.night_end) : (h >= c.night_start && h < c.night_end);
          night = inNight ? total : 0;
        } else {
          days.filter((n) => n.start >= bs && n.start < be).forEach((n) => {
            night += n.dis;
            if (n.ok) { net += n.net; drop += n.drop; nights++; }
          });
        }
        let soc = null;
        if (this._mode === "day") soc = this._socAt(bs.getTime());
        else {
          const vs = [];
          for (let t = bs.getTime(); t < be.getTime(); t += HOUR) if (this._data.soc.has(t)) vs.push(this._data.soc.get(t));
          soc = vs.length ? sum(vs) / vs.length : null;
        }
        return { bs, label, total, charged, grid, pv: Math.max(0, charged - grid), night, day: Math.max(0, total - night), cap: drop > 0 ? (net / drop) * 100 : null, nights, soc };
      });

      // Month: drop empty days before the first and after the last day with data, so the chart is not mostly blank.
      const has = (r) => r.total > 0 || r.charged > 0 || r.soc != null;
      let rows = allRows;
      if (this._mode === "month") {
        const f = allRows.findIndex(has);
        let l = -1;
        allRows.forEach((r, i) => { if (has(r)) l = i; });
        if (f >= 0) rows = allRows.slice(f, l + 1);
      }

      const good = days.filter((n) => n.ok);
      const dropSum = sum(good.map((n) => n.drop));
      const cap = dropSum > 0 ? (sum(good.map((n) => n.net)) / dropSum) * 100 : null;
      return {
        rows, nightsUsed: good.length,
        total: sum(rows.map((r) => r.total)),
        charged: sum(rows.map((r) => r.charged)), gridCharged: sum(rows.map((r) => r.grid)),
        overnight: this._mode === "day" ? this._night(rs).dis : sum(days.map((n) => n.dis)),
        cap, health: cap != null ? (cap / this._cfg.rated_kwh) * 100 : null,
        hasData: rows.some((r) => r.total > 0),
      };
    }

    // One chart: bars up from the zero line = charging (PV, grid), bars down = discharging (overnight, daytime), both in kWh
    // (left axis); the line is the charge level in % (right axis). A tap on a column or its dot shows one combined reading.
    _flow(rows, capKwh) {
      const W = 640, H = 380, L = 40, R = 40, T = 10, B = 22; // H sets the chart height: the svg scales with card width, so a phone shows about 0.6 x H px
      const day = this._mode === "day";
      const up = [{ key: "pv", color: C_PV }, { key: "grid", color: C_GRID }];
      const down = [{ key: "night", color: C_NIGHT }, { key: "day", color: C_DAY }];
      const mx = (layers) => Math.max(0, ...rows.map((r) => sum(layers.map((l) => r[l.key]))));
      const nice = (m) => { const st = [0.5, 1, 2, 5, 10, 20, 50].find((x) => m / x <= 3) || 100; return [st, Math.max(st, Math.ceil(m / st) * st)]; };
      const [stU, topU] = nice(mx(up)), [stD, topD] = nice(mx(down));
      const step = Math.max(stU, stD), hiU = Math.ceil(topU / step) * step, hiD = Math.ceil(topD / step) * step;
      const plotH = H - T - B;
      const y = (v) => T + plotH * (1 - (v + hiD) / (hiU + hiD)); // kWh, from -hiD to +hiU
      const ys = (p) => T + plotH * (1 - p / 100);                 // charge level %, 0..100 over the whole plot height
      const bw = (W - L - R) / rows.length;
      let g = "";
      for (let v = -hiD; v <= hiU + 1e-9; v += step) {
        const lab = v === 0 ? "0" : (v < 0 ? "−" : "") + (Math.abs(v) % 1 ? Math.abs(v).toFixed(1) : Math.abs(v));
        g += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" class="${v === 0 ? "zero" : "grid"}"/><text x="${L - 4}" y="${y(v) + 3}" class="ax" text-anchor="end">${lab}</text>`;
      }
      [0, 25, 50, 75, 100].forEach((p) => { g += `<text x="${W - R + 4}" y="${ys(p) + 3}" class="ax sc" text-anchor="start">${p}%</text>`; });
      g += `<text x="${L - 4}" y="${T - 1}" class="ax" text-anchor="end">kWh</text>`;
      const every = rows.length > 24 ? 5 : rows.length > 12 ? 2 : day ? 3 : 1;
      const px = (i) => L + i * bw + bw / 2;
      const k1 = (v) => num(v, 1);
      const split = (parts) => { const p = parts.filter(([, v]) => v > 0.049).map(([n, v]) => `${n} ${k1(v)}`); return p.length > 1 ? ` (${p.join(", ")})` : ""; };
      const tips = rows.map((r) => {
        const dn = day ? `${r.bs.getHours()}:00` : r.label;
        const lvl = r.soc != null ? `● ${day ? "" : "avg "}${num(r.soc, 0)}% ≈ ${k1((r.soc * capKwh) / 100)} kWh · ` : "";
        return `${dn} – ${lvl}▲ ${k1(r.charged)} kWh${split([["PV", r.pv], ["grid", r.grid]])} · ▼ ${k1(r.total)} kWh${split([["night", r.night], ["day", r.day]])}`;
      });
      rows.forEach((r, i) => {
        const x = L + i * bw + bw * 0.12, w = bw * 0.76;
        g += `<g data-detail="${tips[i]}"><title>${tips[i]}</title><rect x="${L + i * bw}" y="${T}" width="${bw}" height="${plotH}" fill="transparent"/>`;
        let acc = 0;
        up.forEach((l) => { const v = r[l.key]; if (v > 0) g += `<rect x="${x}" y="${y(acc + v)}" width="${w}" height="${y(acc) - y(acc + v)}" fill="${l.color}" rx="1"/>`; acc += v; });
        acc = 0;
        down.forEach((l) => { const v = r[l.key]; if (v > 0) g += `<rect x="${x}" y="${y(0 - acc)}" width="${w}" height="${y(0 - acc - v) - y(0 - acc)}" fill="${l.color}" rx="1"/>`; acc += v; });
        g += "</g>";
        if (i % every === 0) g += `<text x="${px(i)}" y="${H - 6}" class="ax" text-anchor="middle">${r.label}</text>`;
      });
      // charge-level line and big dots on top; each dot belongs to its column's reading
      let path = "", pen = false;
      rows.forEach((r, i) => { if (r.soc == null) { pen = false; return; } path += `${pen ? "L" : "M"}${px(i)},${ys(r.soc)}`; pen = true; });
      if (path) g += `<path d="${path}" fill="none" stroke="${C_CAP}" stroke-width="2.5" stroke-linejoin="round" pointer-events="none"/>`;
      rows.forEach((r, i) => {
        if (r.soc == null) return;
        g += `<g data-detail="${tips[i]}"><circle cx="${px(i)}" cy="${ys(r.soc)}" r="${Math.min(7, Math.max(4, bw * 0.32))}" class="dot"/><circle cx="${px(i)}" cy="${ys(r.soc)}" r="${Math.min(15, Math.max(9, bw * 0.7))}" fill="transparent"/></g>`;
      });
      return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Charge and discharge">${g}</svg>`;
    }

    // Measured capacity per night (Week / Month) or per month (Year). Dots on a zoomed axis, not bars: bars from zero
    // make 16.0 and 17.1 kWh look identical. The axis range is printed so the zoom is visible. Every column is a tap target.
    _cap(rows) {
      const W = 640, H = 230, L = 40, R = 10, T = 18, B = 22;
      const rated = this._cfg.rated_kwh;
      const vals = rows.map((r) => r.cap).filter((v) => v != null);
      if (!vals.length) return `<div class="empty">No night with a level drop of ${this._cfg.min_drop}%+ in this period.</div>`;
      let lo = Math.floor((Math.min(...vals, rated) - 0.4) * 2) / 2, hi = Math.ceil((Math.max(...vals, rated) + 0.4) * 2) / 2;
      if (hi - lo < 2) { const mid = (hi + lo) / 2; lo = mid - 1; hi = mid + 1; }
      const y = (v) => T + (H - T - B) * (1 - (v - lo) / (hi - lo));
      const bw = (W - L - R) / rows.length;
      const px = (i) => L + i * bw + bw / 2;
      let g = "";
      for (let i = 0; i <= 4; i++) {
        const v = lo + ((hi - lo) * i) / 4;
        g += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" class="grid"/><text x="${L - 4}" y="${y(v) + 3}" class="ax" text-anchor="end">${num(v, 1)}</text>`;
      }
      g += `<line x1="${L}" x2="${W - R}" y1="${y(rated)}" y2="${y(rated)}" stroke="${C_CAP}" stroke-dasharray="4 3" opacity=".7"/><text x="${W - R}" y="${y(rated) - 3}" class="ax" text-anchor="end">rated ${rated}</text>`;
      g += `<text x="${L}" y="${T - 6}" class="ax" text-anchor="start">kWh · axis ${num(lo, 1)}–${num(hi, 1)}, not from 0</text>`;
      let path = "", pen = false;
      rows.forEach((r, i) => { if (r.cap == null) { pen = false; return; } path += `${pen ? "L" : "M"}${px(i)},${y(r.cap)}`; pen = true; });
      if (path) g += `<path d="${path}" fill="none" stroke="${C_CAP}" stroke-width="2" stroke-linejoin="round" opacity=".6" pointer-events="none"/>`;
      const every = rows.length > 24 ? 5 : rows.length > 12 ? 2 : 1;
      const labels = vals.length <= 14;
      const dotR = Math.min(8, Math.max(4.5, bw * 0.3));
      rows.forEach((r, i) => {
        const v = r.cap;
        const txt = v == null ? `${r.label} – no valid night` : `${r.label} – capacity ${num(v, 2)} kWh (${num((v / rated) * 100, 0)}% of rated)${r.nights ? ` · ${r.nights} night${r.nights === 1 ? "" : "s"}` : ""}`;
        g += `<g data-detail="${txt}"><title>${txt}</title><rect x="${L + i * bw}" y="${T}" width="${bw}" height="${H - T - B}" fill="transparent"/>`;
        if (v != null) {
          g += `<circle cx="${px(i)}" cy="${y(v)}" r="${dotR}" class="dot"/>`;
          if (labels) g += `<text x="${px(i)}" y="${y(v) - dotR - 3}" class="ax" text-anchor="middle" pointer-events="none">${num(v, 1)}</text>`;
        }
        g += "</g>";
        if (i % every === 0) g += `<text x="${px(i)}" y="${H - 6}" class="ax" text-anchor="middle">${r.label}</text>`;
      });
      return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Measured capacity">${g}</svg>`;
    }

    _render() {
      if (!this._cfg) return;
      const c = this._cfg;
      const [rs, re] = rangeFor(this._mode, this._anchor);
      const canNext = re.getTime() <= Date.now();
      let body;
      if (this._error) body = `<div class="empty">Could not load statistics: ${this._error}</div>`;
      else if (!this._data) body = `<div class="empty">Loading…</div>`;
      else {
        const m = this._compute();
        const day = this._mode === "day";
        const capSub = m.cap == null ? `needs a ≥${c.min_drop}% night` : `${m.nightsUsed} night${m.nightsUsed === 1 ? "" : "s"}`;
        body = `
          <div class="tiles">
            <div class="tile"><div class="k">▼ Discharge</div><div class="v">${num(m.total)}<small> kWh</small></div></div>
            <div class="tile"><div class="k"><i style="background:${C_NIGHT}"></i>☾ Night ${c.night_start}–${String(c.night_end).padStart(2, "0")}</div><div class="v">${num(m.overnight)}<small> kWh</small></div></div>
            <div class="tile"><div class="k">▲ Charge</div><div class="v">${num(m.charged)}<small> kWh</small></div><div class="s">PV ${num(m.charged - m.gridCharged)} · grid ${num(m.gridCharged)}</div></div>
            <div class="tile"><div class="k">▮ Capacity</div><div class="v">${num(m.cap, 2)}<small> kWh</small></div><div class="s">${capSub}</div></div>
            <div class="tile"><div class="k">♥ Health</div><div class="v">${num(m.health, 0)}<small> %</small></div><div class="s">of ${c.rated_kwh} kWh</div></div>
          </div>
          <div class="h">▲ Charge / ▼ Discharge<span class="lg"><span style="color:${C_PV}">▲</span>PV<span style="color:${C_GRID}">▲</span>Grid<span style="color:${C_NIGHT}">▼</span>Night<span style="color:${C_DAY}">▼</span>Day<span style="color:${C_CAP}">●</span>Level</span></div>
          ${m.hasData || m.charged > 0 ? this._flow(m.rows, m.cap != null ? m.cap : c.rated_kwh) : `<div class="empty">No battery activity in this period.</div>`}
          <div class="flowkey">▲▼ bars: kWh (left). ● line: level % (right), 100% = ${num(m.cap != null ? m.cap : c.rated_kwh, 2)} kWh${m.cap != null ? " measured" : " rated"}${day ? "" : ", avg per bar"}.</div>
          ${day ? "" : `<div class="h">Capacity (kWh)</div>
          ${this._cap(m.rows)}
          ${this._mode === "year" && m.rows.filter((r) => r.cap != null).length < 3 ? `<div class="flowkey">Yearly trend needs history: ${m.rows.filter((r) => r.cap != null).length} of 3 months so far. It fills in as the months pass.</div>` : ""}`}
          <div class="detail">${this._detail || "Tap a column for details."}</div>
          <div class="note">Charge and discharge are the inverter's counters; "grid" is its AC-charge counter, the rest is PV. Capacity = (discharge − charge) ÷ level drop over each night, so it reads slightly high; the inverter's own health value is always 100%.</div>`;
      }
      this.shadowRoot.innerHTML = `
        <style>
          :host { display: block; }
          ha-card { padding: 12px 16px 14px; }
          .top { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
          .title { font-size: 1.1rem; font-weight: 500; color: var(--primary-text-color); }
          .modes { display: inline-flex; border: 1px solid var(--divider-color); border-radius: 8px; overflow: hidden; }
          .modes button, .nav button { font: inherit; font-size: .85rem; border: 0; background: transparent; color: var(--primary-text-color); padding: 6px 12px; cursor: pointer; }
          .modes button.on { background: var(--primary-color); color: var(--text-primary-color, #fff); }
          .nav { display: flex; align-items: center; justify-content: center; gap: 4px; margin: 8px 0 4px; color: var(--primary-text-color); }
          .nav button { border: 1px solid var(--divider-color); border-radius: 8px; min-width: 36px; }
          .nav button:disabled { opacity: .35; cursor: default; }
          .nav .lbl { min-width: 170px; text-align: center; font-weight: 500; }
          .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(88px, 1fr)); gap: 8px; margin: 8px 0 10px; }
          .tile { background: var(--secondary-background-color); border-radius: 10px; padding: 8px 10px; }
          .k { font-size: .75rem; color: var(--secondary-text-color); display: flex; align-items: center; gap: 5px; }
          .v { font-size: 1.5rem; font-weight: 500; color: var(--primary-text-color); }
          .v small { font-size: .8rem; font-weight: 400; color: var(--secondary-text-color); }
          .s { font-size: .7rem; color: var(--secondary-text-color); }
          i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; }
          .h { font-size: .85rem; font-weight: 500; color: var(--primary-text-color); margin: 10px 0 2px; display: flex; justify-content: space-between; align-items: center; }
          .lg { font-weight: 400; font-size: .75rem; color: var(--secondary-text-color); display: inline-flex; align-items: center; gap: 4px; }
          .lg i { margin-left: 8px; }
          svg { width: 100%; height: auto; display: block; }
          .grid { stroke: var(--divider-color); stroke-width: 1; }
          .zero { stroke: var(--secondary-text-color); stroke-width: 1.2; }
          .dot { fill: ${C_CAP}; stroke: var(--card-background-color, #fff); stroke-width: 2; }
          .sc { fill: ${C_CAP}; }
          .lg b { font-weight: 500; margin-left: 10px; color: var(--primary-text-color); }
          .lg > span { margin-left: 8px; margin-right: 3px; font-size: .8rem; }
          .lg i.ln { height: 3px; width: 14px; border-radius: 2px; vertical-align: middle; }
          .flowkey { font-size: .7rem; color: var(--secondary-text-color); margin: 0 0 4px; }
          .ax { fill: var(--secondary-text-color); font-size: 10px; }
          g[data-detail], [data-detail] { cursor: pointer; }
          .empty { color: var(--secondary-text-color); font-size: .85rem; padding: 18px 0; text-align: center; }
          .detail { min-height: 1.2em; margin-top: 6px; font-size: .8rem; color: var(--primary-text-color); text-align: center; }
          .note { margin-top: 6px; font-size: .68rem; color: var(--secondary-text-color); line-height: 1.35; }
        </style>
        <ha-card>
          <div class="top">
            <div class="title">${c.title}</div>
            <div class="modes">${MODES.map((m) => `<button data-mode="${m}" class="${m === this._mode ? "on" : ""}">${m[0].toUpperCase() + m.slice(1)}</button>`).join("")}</div>
          </div>
          <div class="nav">
            <button data-nav="prev" aria-label="Previous">‹</button>
            <span class="lbl">${rangeLabel(this._mode, rs, re)}</span>
            <button data-nav="next" aria-label="Next" ${canNext ? "" : "disabled"}>›</button>
            <button data-nav="today">Today</button>
          </div>
          ${body}
        </ha-card>`;
    }
  }

  if (!customElements.get("battery-report-card")) customElements.define("battery-report-card", BatteryReportCard);
  window.customCards = window.customCards || [];
  window.customCards.push({ type: "battery-report-card", name: "Battery report", description: "Discharge, overnight discharge and measured capacity with Day/Week/Month/Year selection" });
})();
