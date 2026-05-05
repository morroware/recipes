// public_html/assets/js/recipe-picker.js
// Vanilla port of project/recipe-picker.jsx.
// Searchable, sortable, single- or multi-select picker for hundreds of
// recipes. Mirrors the prototype's CSS classes and ARIA semantics.
//
// Usage:
//   import { RecipePicker } from './recipe-picker.js';
//   const picker = new RecipePicker(rootEl, {
//     recipes: [...],          // [{id, title, cuisine, time_minutes,
//                              //   servings, glyph, color, photo_url, tags?}]
//     selected: 12,            // for single mode: id|null. for multi: id[]
//     mode: 'single',          // 'single' | 'multi'
//     height: 480,             // list height in px
//     hideHeader: false,
//     onChange: (next) => {},
//   });
//   picker.setSelected(...);   // imperative update
//   picker.destroy();

const STICKER = {
  mint:   '#C8F0DC', butter: '#FFE9A8', peach:  '#FFCFB8', lilac: '#E2D2FF',
  sky:    '#C7E6FF', blush:  '#FFC9DC', lime:   '#DDF3A2', coral: '#FFB3B3',
};

export class RecipePicker {
  constructor(root, opts = {}) {
    this.root = root;
    this.recipes = opts.recipes || [];
    this.mode = opts.mode === 'multi' ? 'multi' : 'single';
    this.selected = this.mode === 'multi' ? (opts.selected || []) : (opts.selected ?? null);
    this.onChange = typeof opts.onChange === 'function' ? opts.onChange : () => {};
    this.height = opts.height || 480;
    this.hideHeader = !!opts.hideHeader;
    this.search = '';
    this.cuisine = 'All';
    this.sortBy = 'title';
    this.render();
  }

  setSelected(next) {
    this.selected = this.mode === 'multi' ? (next || []) : (next ?? null);
    this.refreshSelection();
  }

  setRecipes(next) {
    this.recipes = next || [];
    this.render();
  }

  destroy() { this.root.innerHTML = ''; }

  // ------------------------------------------------------------------------

  isSelected(id) {
    if (this.mode === 'multi') return this.selected.includes(id);
    return this.selected === id;
  }

  toggle(id) {
    if (this.mode === 'multi') {
      this.selected = this.selected.includes(id)
        ? this.selected.filter(x => x !== id)
        : [...this.selected, id];
    } else {
      this.selected = id;
    }
    this.refreshSelection();
    this.onChange(this.selected);
  }

  filtered() {
    const q = this.search.trim().toLowerCase();
    let list = this.recipes.filter(r => {
      if (this.cuisine !== 'All' && (r.cuisine || '') !== this.cuisine) return false;
      if (!q) return true;
      const tags = (r.tags || []).join(' ').toLowerCase();
      return (r.title || '').toLowerCase().includes(q)
          || (r.cuisine || '').toLowerCase().includes(q)
          || tags.includes(q);
    });
    if (this.sortBy === 'title') {
      list.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
    } else if (this.sortBy === 'time') {
      list.sort((a, b) => (a.time_minutes || 0) - (b.time_minutes || 0));
    }
    return list;
  }

  cuisines() {
    const seen = new Set();
    const out = ['All'];
    for (const r of this.recipes) {
      const c = (r.cuisine || '').trim();
      if (c && !seen.has(c)) { seen.add(c); out.push(c); }
    }
    return out;
  }

  // ------------------------------------------------------------------------

  render() {
    this.root.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'recipe-picker';
    if (!this.hideHeader) wrap.appendChild(this.renderHeader());

    const list = document.createElement('ul');
    list.className = 'recipe-picker-list';
    list.style.maxHeight = this.height + 'px';
    list.setAttribute('role', this.mode === 'multi' ? 'listbox' : 'radiogroup');
    if (this.mode === 'multi') list.setAttribute('aria-multiselectable', 'true');
    // The list itself isn't tabbable — roving tabindex on rows handles it.
    list.addEventListener('keydown', (e) => this.onKeyDown(e, list));
    this.list = list;
    this.refreshList();
    wrap.appendChild(list);
    this.root.appendChild(wrap);
  }

  renderHeader() {
    const head = document.createElement('div');
    head.className = 'recipe-picker-header';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'search-input';
    search.placeholder = `Search ${this.recipes.length} recipes…`;
    search.autocomplete = 'off';
    search.value = this.search;
    let searchTimer = null;
    search.addEventListener('input', () => {
      // 150ms debounce keeps each keystroke from triggering a full refilter
      // (which can churn through hundreds of rows).
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        this.search = search.value;
        this.refreshList();
      }, 150);
    });
    head.appendChild(search);
    // Only autofocus on devices with a real pointer — auto-popping the iOS
    // keyboard the moment the picker opens is jarring, and we re-focus on
    // every render which steals input from anything the user is doing.
    if (!this._didInitialFocus
        && (typeof matchMedia !== 'function' || matchMedia('(hover: hover)').matches)) {
      setTimeout(() => search.focus(), 0);
      this._didInitialFocus = true;
    }

    const row = document.createElement('div');
    row.className = 'row';
    row.style.cssText = 'gap: 6px; margin-top: 10px; flex-wrap: wrap;';

    const cuisines = this.cuisines();
    cuisines.slice(0, 8).forEach(c => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'filter-chip' + (this.cuisine === c ? ' active' : '');
      b.style.cssText = 'font-size: 12px; padding: 4px 10px;';
      b.textContent = c;
      b.addEventListener('click', () => {
        this.cuisine = c;
        for (const sib of row.querySelectorAll('.filter-chip')) {
          sib.classList.toggle('active', sib.textContent === c);
        }
        this.refreshList();
      });
      row.appendChild(b);
    });

    const spacer = document.createElement('span');
    spacer.style.flex = '1';
    row.appendChild(spacer);

    const sort = document.createElement('select');
    sort.className = 'form-input';
    sort.style.cssText = 'padding: 4px 10px; font-size: 12px; width: auto;';
    sort.innerHTML = '<option value="title">A→Z</option><option value="time">Quickest first</option>';
    sort.value = this.sortBy;
    sort.addEventListener('change', () => {
      this.sortBy = sort.value;
      this.refreshList();
    });
    row.appendChild(sort);

    head.appendChild(row);

    const meta = document.createElement('div');
    meta.className = 'mono';
    meta.style.cssText = 'font-size: 11px; color: var(--ink-soft); margin-top: 8px;';
    this.metaEl = meta;
    head.appendChild(meta);

    return head;
  }

  refreshList() {
    const list = this.list;
    if (!list) return;
    list.innerHTML = '';
    const filtered = this.filtered();
    if (this.metaEl) {
      let text = `${filtered.length} of ${this.recipes.length}`;
      if (this.mode === 'multi' && this.selected.length > 0) {
        text += ` · ${this.selected.length} selected`;
      }
      this.metaEl.textContent = text;
    }
    if (filtered.length === 0) {
      const li = document.createElement('li');
      li.className = 'muted';
      li.style.cssText = 'padding: 20px; text-align: center; font-size: 13px; list-style: none;';
      li.textContent = 'No matches.';
      list.appendChild(li);
      return;
    }
    for (const r of filtered) list.appendChild(this.renderRow(r));
    // Make exactly one row tabbable: prefer the selected one, else the first.
    const rows = Array.from(list.querySelectorAll('.recipe-picker-row'));
    let anchor = rows.find((li) => li.classList.contains('selected')) || rows[0];
    if (anchor) anchor.tabIndex = 0;
  }

  renderRow(r) {
    const li = document.createElement('li');
    const sel = this.isSelected(r.id);
    li.className = 'recipe-picker-row' + (sel ? ' selected' : '');
    li.dataset.recipeId = String(r.id);
    li.setAttribute('role', this.mode === 'multi' ? 'option' : 'radio');
    li.setAttribute('aria-selected', sel ? 'true' : 'false');
    // Roving tabindex — only one row tabbable at a time. The active one is
    // assigned in refreshList() once the DOM order is final.
    li.tabIndex = -1;

    const thumb = document.createElement('span');
    thumb.className = 'recipe-picker-thumb';
    if (r.photo_url) {
      thumb.style.cssText = `background-image: url(${r.photo_url}); background-size: cover; background-position: center;`;
    } else {
      thumb.style.background = STICKER[r.color] || STICKER.mint;
      thumb.textContent = r.glyph || '🍽️';
    }
    li.appendChild(thumb);

    const body = document.createElement('span');
    body.className = 'recipe-picker-body';
    const title = document.createElement('span');
    title.className = 'recipe-picker-title';
    title.textContent = r.title;
    const meta = document.createElement('span');
    meta.className = 'recipe-picker-meta';
    meta.textContent = `${r.cuisine || ''} · ${r.time_minutes || 0}m · serves ${r.servings || 1}`;
    body.appendChild(title);
    body.appendChild(meta);
    li.appendChild(body);

    const mark = document.createElement('span');
    mark.className = 'recipe-picker-mark recipe-picker-mark-' + this.mode;
    if (sel) mark.textContent = this.mode === 'multi' ? '✓' : '●';
    li.appendChild(mark);

    li.addEventListener('click', () => this.toggle(r.id));
    return li;
  }

  refreshSelection() {
    if (!this.list) return;
    for (const li of this.list.querySelectorAll('.recipe-picker-row')) {
      const id = parseInt(li.dataset.recipeId, 10);
      const sel = this.isSelected(id);
      li.classList.toggle('selected', sel);
      li.setAttribute('aria-selected', sel ? 'true' : 'false');
      const mark = li.querySelector('.recipe-picker-mark');
      if (mark) mark.textContent = sel ? (this.mode === 'multi' ? '✓' : '●') : '';
    }
    if (this.metaEl) {
      const filteredCount = this.list.querySelectorAll('.recipe-picker-row').length;
      let text = `${filteredCount} of ${this.recipes.length}`;
      if (this.mode === 'multi' && this.selected.length > 0) {
        text += ` · ${this.selected.length} selected`;
      }
      this.metaEl.textContent = text;
    }
  }

  onKeyDown(e, list) {
    const rows = Array.from(list.querySelectorAll('.recipe-picker-row'));
    if (!rows.length) return;
    const idx = rows.indexOf(document.activeElement);
    const focusRow = (n) => {
      // Roving tabindex: the focused row is the only tabbable one.
      rows.forEach((li) => { li.tabIndex = -1; });
      n.tabIndex = 0;
      n.focus();
    };
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      focusRow(rows[Math.min(rows.length - 1, idx < 0 ? 0 : idx + 1)]);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      focusRow(rows[Math.max(0, idx < 0 ? 0 : idx - 1)]);
    } else if (e.key === 'Home') {
      e.preventDefault();
      focusRow(rows[0]);
    } else if (e.key === 'End') {
      e.preventDefault();
      focusRow(rows[rows.length - 1]);
    } else if (e.key === 'Enter' || e.key === ' ') {
      if (idx >= 0) {
        e.preventDefault();
        rows[idx].click();
      }
    }
  }
}
