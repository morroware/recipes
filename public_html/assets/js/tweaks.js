// public_html/assets/js/tweaks.js
// Vanilla JS Tweaks panel — nine settings, persists via PUT /api/settings,
// syncs <html data-*> attributes so the prototype CSS theming works unchanged.

import { apiFetch, toast } from './app.js';

const STYLE = `
.tweaks-fab {
  position: fixed; right: 24px; bottom: 94px; z-index: 9999;
  width: 44px; height: 44px; border-radius: 50%;
  border: 2px solid var(--ink); background: var(--cream);
  box-shadow: 3px 3px 0 var(--ink); cursor: pointer;
  font-size: 20px; line-height: 1; display: grid; place-items: center;
}
.tweaks-fab:hover { transform: translate(-1px, -1px); box-shadow: 4px 4px 0 var(--ink); }
.tweaks-fab.active { background: var(--butter); }
@media (max-width: 720px) {
  .tweaks-fab { bottom: 152px; }
}

.tweaks-panel {
  position: fixed; right: 16px; bottom: 150px; z-index: 9998;
  width: 300px; max-height: calc(100vh - 170px);
  display: flex; flex-direction: column;
  background: var(--cream); color: var(--ink);
  border: 2px solid var(--ink); border-radius: var(--r-md);
  box-shadow: 4px 4px 0 var(--ink);
  font: 13px/1.4 var(--font-sans, system-ui), sans-serif;
  overflow: hidden;
}
.tweaks-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-bottom: 2px solid var(--ink);
  background: var(--cream-2, var(--butter));
}
.tweaks-head b { font-family: var(--font-display, sans-serif); font-size: 14px; }
.tweaks-x { background: transparent; border: 0; cursor: pointer; font-size: 16px; padding: 4px 6px; }
.tweaks-body {
  padding: 12px 14px; display: flex; flex-direction: column; gap: 12px;
  overflow-y: auto;
}
.tweaks-section { font-size: 10px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--ink-soft); padding-top: 4px; }
.tweaks-row { display: flex; flex-direction: column; gap: 4px; }
.tweaks-row-h { flex-direction: row; align-items: center; justify-content: space-between; }
.tweaks-row label { font-weight: 500; }
.tweaks-select, .tweaks-seg { width: 100%; }
.tweaks-select select {
  width: 100%; padding: 6px 8px; font: inherit;
  border: 2px solid var(--ink); border-radius: var(--r-sm);
  background: var(--cream); color: var(--ink);
}
.tweaks-seg {
  display: flex; gap: 0; border: 2px solid var(--ink); border-radius: var(--r-sm);
  overflow: hidden;
}
.tweaks-seg button {
  flex: 1; padding: 6px 4px; font: inherit; font-size: 11px;
  background: var(--cream); border: 0; border-right: 2px solid var(--ink);
  cursor: pointer; color: var(--ink);
}
.tweaks-seg button:last-child { border-right: 0; }
.tweaks-seg button.active { background: var(--ink); color: var(--cream); }
.tweaks-toggle {
  width: 36px; height: 20px; border: 2px solid var(--ink);
  border-radius: 999px; background: var(--cream); cursor: pointer; padding: 0;
  position: relative; flex-shrink: 0;
}
.tweaks-toggle.on { background: var(--mint); }
.tweaks-toggle::after {
  content: ''; position: absolute; top: 1px; left: 1px;
  width: 14px; height: 14px; border-radius: 50%; background: var(--ink);
  transition: transform .15s;
}
.tweaks-toggle.on::after { transform: translateX(16px); }
@media print { .tweaks-fab, .tweaks-panel { display: none !important; } }
`;

const FIELDS = [
  { section: 'Theme', items: [
    { key: 'theme', kind: 'select', label: 'Color palette', options: [
      ['rainbow', '🌈 Pastel rainbow'],
      ['sunset',  '🌅 Sunset'],
      ['ocean',   '🌊 Ocean'],
      ['garden',  '🌿 Garden'],
    ]},
    { key: 'mode', kind: 'seg', label: 'Mode', options: [
      ['light', 'Light'], ['dark', 'Dark'],
    ]},
  ]},
  { section: 'Layout', items: [
    { key: 'density', kind: 'seg', label: 'Density', options: [
      ['compact', 'Compact'], ['cozy', 'Cozy'], ['airy', 'Airy'],
    ]},
    { key: 'radius', kind: 'seg', label: 'Corners', options: [
      ['sharp', 'Sharp'], ['default', 'Default'], ['round', 'Round'],
    ]},
    { key: 'card_style', kind: 'seg', label: 'Cards', options: [
      ['mix', 'Mix'], ['photo-only', 'Photos'], ['glyph-only', 'Glyphs'],
    ]},
  ]},
  { section: 'Type', items: [
    { key: 'font_pair', kind: 'select', label: 'Font pair', options: [
      ['default', 'Default'], ['serif', 'Serif'],
      ['mono', 'Mono'], ['rounded', 'Rounded'],
    ]},
  ]},
  { section: 'Details', items: [
    { key: 'sticker_rotate', kind: 'toggle', label: 'Sticker rotate' },
    { key: 'dot_grid',       kind: 'toggle', label: 'Dot grid' },
    { key: 'units', kind: 'seg', label: 'Units', options: [
      ['metric', 'Metric'], ['imperial', 'Imperial'],
    ]},
  ]},
];

// Map setting keys to <html data-*> attribute names + value coercers.
const ATTR_MAP = {
  density:        { attr: 'data-density' },
  theme:          { attr: 'data-theme' },
  mode:           { attr: 'data-mode' },
  font_pair:      { attr: 'data-fontpair' },
  radius:         { attr: 'data-radius' },
  card_style:     { attr: 'data-card-style' },
  sticker_rotate: { attr: 'data-sticker-rotate', toAttr: v => v ? 'on' : 'off' },
  dot_grid:       { attr: 'data-dot-grid',       toAttr: v => v ? 'on' : 'off' },
  units:          { attr: 'data-units' },
};

function applyAttrs(values) {
  const html = document.documentElement;
  for (const [key, def] of Object.entries(ATTR_MAP)) {
    if (!(key in values)) continue;
    const v = def.toAttr ? def.toAttr(values[key]) : String(values[key]);
    html.setAttribute(def.attr, v);
  }
}

class Tweaks {
  constructor() {
    this.values = null;
    this.saveTimer = null;
    this.installStyle();
    this.installFab();
  }

  installFab() {
    const fab = document.createElement('button');
    fab.type = 'button';
    fab.className = 'tweaks-fab no-print';
    fab.setAttribute('aria-label', 'Tweaks');
    fab.title = 'Tweaks';
    fab.textContent = '✨';
    fab.addEventListener('click', () => this.toggle());
    document.body.appendChild(fab);
    this.fab = fab;
  }

  async toggle() {
    if (this.panel) {
      this.close();
      return;
    }
    if (!this.values) {
      try {
        const { data } = await apiFetch('/api/settings');
        this.values = data?.settings || {};
      } catch { this.values = {}; }
    }
    this.open();
  }

  open() {
    this.fab.classList.add('active');
    const panel = document.createElement('div');
    panel.className = 'tweaks-panel no-print';
    panel.innerHTML = `
      <div class="tweaks-head">
        <b>✨ Tweaks</b>
        <button type="button" class="tweaks-x" aria-label="Close">✕</button>
      </div>
      <div class="tweaks-body" data-js="body"></div>
    `;
    panel.querySelector('.tweaks-x').addEventListener('click', () => this.close());
    const body = panel.querySelector('[data-js="body"]');
    for (const sec of FIELDS) {
      const h = document.createElement('div');
      h.className = 'tweaks-section';
      h.textContent = sec.section;
      body.appendChild(h);
      for (const f of sec.items) body.appendChild(this.renderField(f));
    }
    document.body.appendChild(panel);
    this.panel = panel;
  }

  close() {
    this.panel?.remove();
    this.panel = null;
    this.fab.classList.remove('active');
  }

  renderField(f) {
    const row = document.createElement('div');
    row.className = f.kind === 'toggle' ? 'tweaks-row tweaks-row-h' : 'tweaks-row';
    const label = document.createElement('label');
    label.textContent = f.label;
    row.appendChild(label);

    if (f.kind === 'select') {
      const wrap = document.createElement('div');
      wrap.className = 'tweaks-select';
      const sel = document.createElement('select');
      for (const [v, l] of f.options) {
        const o = document.createElement('option');
        o.value = v; o.textContent = l;
        if (this.values[f.key] === v) o.selected = true;
        sel.appendChild(o);
      }
      sel.addEventListener('change', () => this.set(f.key, sel.value));
      wrap.appendChild(sel);
      row.appendChild(wrap);
    } else if (f.kind === 'seg') {
      const seg = document.createElement('div');
      seg.className = 'tweaks-seg';
      for (const [v, l] of f.options) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = l;
        if (this.values[f.key] === v) b.classList.add('active');
        b.addEventListener('click', () => {
          for (const sib of seg.children) sib.classList.toggle('active', sib === b);
          this.set(f.key, v);
        });
        seg.appendChild(b);
      }
      row.appendChild(seg);
    } else if (f.kind === 'toggle') {
      const t = document.createElement('button');
      t.type = 'button';
      t.className = 'tweaks-toggle';
      const on = !!this.values[f.key];
      t.classList.toggle('on', on);
      t.setAttribute('role', 'switch');
      t.setAttribute('aria-checked', on ? 'true' : 'false');
      t.addEventListener('click', () => {
        const next = !t.classList.contains('on');
        t.classList.toggle('on', next);
        t.setAttribute('aria-checked', next ? 'true' : 'false');
        this.set(f.key, next ? 1 : 0);
      });
      row.appendChild(t);
    }
    return row;
  }

  set(key, value) {
    this.values[key] = value;
    applyAttrs({ [key]: value });
    this.queueSave({ [key]: value });
  }

  queueSave(patch) {
    clearTimeout(this.saveTimer);
    this._pending = { ...(this._pending || {}), ...patch };
    this.saveTimer = setTimeout(async () => {
      const body = this._pending;
      this._pending = null;
      try {
        await apiFetch('/api/settings', {
          method: 'PUT',
          body: JSON.stringify(body),
        });
      } catch { /* apiFetch already showed an error toast */ }
    }, 350);
  }

  installStyle() {
    // De-dupe the style tag so a second tweaks.js load (e.g. cache-bust race)
    // doesn't append duplicate <style> nodes.
    if (document.getElementById('tweaks-style')) return;
    const el = document.createElement('style');
    el.id = 'tweaks-style';
    el.textContent = STYLE;
    document.head.appendChild(el);
  }
}

new Tweaks();
