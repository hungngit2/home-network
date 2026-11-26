/*
 * time-window-card: compact Start / End editor for inverter time windows.
 *
 *   type: custom:time-window-card
 *   title: AC charging window
 *   hint: optional line under the title
 *   windows:
 *     - { start: time.xxx_start_time,   end: time.xxx_end_time }     # window 1
 *     - { start: time.xxx_start_time_1, end: time.xxx_end_time_1 }   # window 2
 *
 * One row per window, numbered 1..n, with small time inputs. A window whose start equals its end
 * (00:00 to 00:00) is shown as "off". Each change is sent at once with time.set_value and the row
 * shows a tick when the inverter has read it back. "Clear" sets both ends to 00:00.
 */
(() => {
  const hhmm = (s) => (s && /^\d\d:\d\d/.test(s) ? s.slice(0, 5) : "");

  class TimeWindowCard extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({ mode: "open" });
      this._pending = {}; // entity -> wanted HH:MM while a write is in flight
      this._status = {};  // entity -> "ok" | "fail"
      this.shadowRoot.addEventListener("change", (e) => this._onChange(e));
      this.shadowRoot.addEventListener("click", (e) => this._onClick(e));
    }

    setConfig(c) {
      if (!c.windows || !c.windows.length) throw new Error("windows is required");
      this._cfg = c;
      this._rendered = false;
      this._render();
    }

    getCardSize() { return 1 + this._cfg.windows.length; }
    getGridOptions() { return { columns: "full" }; }

    set hass(h) {
      this._hass = h;
      // Re-render only when a watched value changed, so a time input being edited is not reset.
      const sig = this._cfg.windows.map((w) => [w.start, w.end].map((id) => (h.states[id] ? h.states[id].state : "-")).join(">")).join("|");
      if (sig !== this._sig) { this._sig = sig; this._render(); }
    }

    _val(id) {
      if (this._pending[id] !== undefined) return this._pending[id];
      const s = this._hass && this._hass.states[id];
      return s && s.state !== "unavailable" && s.state !== "unknown" ? hhmm(s.state) : "";
    }

    async _write(id, v) {
      this._pending[id] = v; this._status[id] = "";
      this._render();
      try {
        await this._hass.callService("time", "set_value", { entity_id: id, time: v.length === 5 ? v + ":00" : v });
        for (let i = 0; i < 30; i++) { // wait up to ~15 s for the read-back
          const s = this._hass.states[id];
          if (s && hhmm(s.state) === v) { this._status[id] = "ok"; break; }
          await new Promise((r) => setTimeout(r, 500));
        }
        if (this._status[id] !== "ok") this._status[id] = "fail";
      } catch (err) { this._status[id] = "fail"; }
      delete this._pending[id];
      this._sig = null; this._render();
    }

    _onChange(e) {
      const el = e.target;
      if (!el.dataset || !el.dataset.id || !el.value) return;
      if (hhmm(el.value) !== this._val(el.dataset.id)) this._write(el.dataset.id, hhmm(el.value));
    }

    _onClick(e) {
      const b = e.composedPath().find((n) => n.dataset && n.dataset.clear !== undefined);
      if (!b) return;
      const w = this._cfg.windows[Number(b.dataset.clear)];
      this._write(w.start, "00:00").then(() => this._write(w.end, "00:00"));
    }

    _cell(id) {
      const st = this._status[id];
      const mark = this._pending[id] !== undefined ? '<span class="mk">…</span>' : st === "ok" ? '<span class="mk ok">✓</span>' : st === "fail" ? '<span class="mk bad" title="not confirmed">!</span>' : "";
      const bad = !this._hass || !this._hass.states[id] || this._hass.states[id].state === "unavailable";
      return `<td><input type="time" data-id="${id}" value="${this._val(id)}" ${bad ? "disabled" : ""}>${mark}</td>`;
    }

    _render() {
      const c = this._cfg; if (!c) return;
      const rows = c.windows.map((w, i) => {
        const s = this._val(w.start), e = this._val(w.end);
        const off = s === e;
        return `<tr class="${off ? "off" : ""}"><th>${i + 1}</th>${this._cell(w.start)}${this._cell(w.end)}
          <td class="st">${off ? "off" : ""}</td><td>${off ? "" : `<button data-clear="${i}" title="Set both to 00:00">Clear</button>`}</td></tr>`;
      }).join("");
      this.shadowRoot.innerHTML = `<style>
        :host{display:block} ha-card{padding:10px 14px 12px}
        .t{font-size:.875rem;font-weight:500;color:var(--primary-text-color);margin-bottom:4px} .h{font-size:.78rem;color:var(--secondary-text-color);margin-bottom:6px}
        table{border-collapse:collapse;width:100%;max-width:420px} th,td{padding:3px 6px;text-align:left;color:var(--primary-text-color);font-size:.85rem;white-space:nowrap}
        thead th{font-size:.78rem;font-weight:500;color:var(--secondary-text-color)} tbody th{width:22px;color:var(--secondary-text-color)}
        input[type=time]{font:inherit;font-size:.85rem;width:92px;padding:3px 4px;border:1px solid var(--divider-color);border-radius:6px;background:var(--card-background-color);color:var(--primary-text-color)}
        input:disabled{opacity:.4} tr.off input{opacity:.55}
        .st{font-size:.78rem;color:var(--secondary-text-color)} .mk{margin-left:4px;font-size:.78rem;color:var(--secondary-text-color)} .ok{color:var(--success-color,#2e7d32)} .bad{color:var(--error-color,#c62828)}
        button{font:inherit;font-size:.78rem;border:0;background:none;color:var(--primary-color);cursor:pointer;padding:2px 4px}
      </style><ha-card>${c.title ? `<div class="t">${c.title}</div>` : ""}${c.hint ? `<div class="h">${c.hint}</div>` : ""}
        <table><thead><tr><th>#</th><th>Start</th><th>End</th><th></th><th></th></tr></thead><tbody>${rows}</tbody></table></ha-card>`;
    }
  }

  if (!customElements.get("time-window-card")) customElements.define("time-window-card", TimeWindowCard);
  window.customCards = window.customCards || [];
  window.customCards.push({ type: "time-window-card", name: "Time windows", description: "Compact Start/End editor for inverter time windows" });
})();
