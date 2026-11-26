/*
 * collapsible-section-card: a titled group of cards that can be folded.
 *
 *   type: custom:collapsible-section-card
 *   id: protect            # key for remembering open/closed (per browser)
 *   title: Battery protection
 *   icon: mdi:battery-heart-variant
 *   intro: One line under the title when open (optional)
 *   open: true             # default state before the user has chosen
 *   cards: [ ...any cards, including ones with `visibility` conditions ]
 *
 * Children are created only while the group is open, so a folded group costs nothing.
 * They are rendered with <hui-card>, which applies each child's `visibility` conditions.
 */
(() => {
  const FULL = new Set(["entities", "glance", "markdown", "custom:inverter-wizard-card", "custom:section-note-card", "heading"]);

  class CollapsibleSectionCard extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({ mode: "open" });
      this._kids = [];
      this._open = true;
      this.shadowRoot.addEventListener("click", (e) => {
        if (e.composedPath().some((n) => n.classList && n.classList.contains("hd"))) this._toggle();
      });
      this.shadowRoot.addEventListener("keydown", (e) => {
        if ((e.key === "Enter" || e.key === " ") && e.composedPath().some((n) => n.classList && n.classList.contains("hd"))) { e.preventDefault(); this._toggle(); }
      });
    }

    setConfig(c) {
      this._cfg = { open: true, cards: [], ...c };
      this._key = "collapsible-section-" + (c.id || c.title || "x");
      let saved = null;
      try { saved = localStorage.getItem(this._key); } catch (e) { /* storage may be blocked */ }
      this._open = saved === null ? !!this._cfg.open : saved === "1";
      this._build();
    }

    getCardSize() { return this._open ? 1 + Math.ceil((this._cfg.cards || []).length / 2) : 1; }
    getGridOptions() { return { columns: "full" }; }

    set hass(h) {
      this._hass = h;
      this._kids.forEach((k) => { k.hass = h; });
    }

    _toggle() {
      this._open = !this._open;
      try { localStorage.setItem(this._key, this._open ? "1" : "0"); } catch (e) { /* ignore */ }
      this._build();
    }

    async _child(cfg) {
      let el;
      if (customElements.get("hui-card")) {
        el = document.createElement("hui-card");
        el.config = cfg;
      } else { // older frontend: no visibility handling, but cards still render
        const helpers = await window.loadCardHelpers();
        el = helpers.createCardElement(cfg);
      }
      el.hass = this._hass;
      const wide = FULL.has(cfg.type) || (cfg.grid_options && cfg.grid_options.columns === "full");
      el.style.gridColumn = wide ? "1 / -1" : "auto";
      el.style.minWidth = "0";
      return el;
    }

    async _build() {
      const c = this._cfg;
      if (!c) return;
      const seq = (this._seq = (this._seq || 0) + 1);
      this.shadowRoot.innerHTML = `
        <style>
          :host { display: block; }
          ha-card { overflow: hidden; }
          .hd { display: flex; align-items: center; gap: 10px; padding: 12px 14px; cursor: pointer; user-select: none; color: var(--primary-text-color); }
          .hd:focus-visible { outline: 2px solid var(--primary-color); outline-offset: -2px; }
          .hd ha-icon { --mdc-icon-size: 22px; color: var(--secondary-text-color); }
          .hd .t { flex: 1; font-size: 1.05rem; font-weight: 500; }
          .chev { transition: transform .15s; } .open .chev { transform: rotate(180deg); }
          .intro { padding: 0 14px 6px; font-size: .78rem; color: var(--secondary-text-color); line-height: 1.35; }
          .body { --ha-card-header-font-size: .875rem; --ha-card-header-font-weight: 500; --ha-tile-info-primary-font-size: .85rem; --ha-tile-info-secondary-font-size: .78rem; font-size: .85rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 8px; padding: 6px 12px 14px; }
        </style>
        <ha-card class="${this._open ? "open" : ""}">
          <div class="hd" role="button" tabindex="0" aria-expanded="${this._open}">
            ${c.icon ? `<ha-icon icon="${c.icon}"></ha-icon>` : ""}
            <span class="t">${c.title || ""}</span>
            <ha-icon class="chev" icon="mdi:chevron-down"></ha-icon>
          </div>
          ${this._open && c.intro ? `<div class="intro">${c.intro}</div>` : ""}
          ${this._open ? `<div class="body"></div>` : ""}
        </ha-card>`;
      this._kids = [];
      if (!this._open) return;
      const body = this.shadowRoot.querySelector(".body");
      const made = await Promise.all((c.cards || []).map((cfg) => this._child(cfg)));
      if (seq !== this._seq) return; // toggled again while building
      made.forEach((el) => { body.appendChild(el); this._kids.push(el); });
    }
  }

  // A small text note for use inside a section (same size as the section intro). Supports **bold** and *italic*.
  class SectionNoteCard extends HTMLElement {
    constructor() { super(); this.attachShadow({ mode: "open" }); }
    setConfig(c) {
      const esc = (t) => String(t).replace(/[&<>]/g, (ch) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[ch]));
      const html = esc(c.text || "").replace(/\*\*(.+?)\*\*/g, "<b>$1</b>").replace(/\*(.+?)\*/g, "<i>$1</i>");
      this.shadowRoot.innerHTML = `<style>:host{display:block}.n{font-size:.78rem;line-height:1.35;color:var(--secondary-text-color);padding:2px 2px 4px}.n b{color:var(--primary-text-color);font-weight:500}</style><div class="n">${html}</div>`;
    }
    getCardSize() { return 1; }
    getGridOptions() { return { columns: "full" }; }
  }
  if (!customElements.get("section-note-card")) customElements.define("section-note-card", SectionNoteCard);

  if (!customElements.get("collapsible-section-card")) customElements.define("collapsible-section-card", CollapsibleSectionCard);
  window.customCards = window.customCards || [];
  window.customCards.push({ type: "collapsible-section-card", name: "Collapsible section", description: "Foldable group of cards that remembers its state" });
})();
