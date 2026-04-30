// public_html/assets/js/browse.js
// Debounced auto-submit on the search input so typing narrows the grid
// without requiring an explicit click. Filters still work without JS.

import { setWindowContext } from './window-context.js';

const form = document.getElementById('filter-form');
if (form) {
  const search = form.querySelector('input[name="search"]');
  if (search) {
    let t = null;
    search.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => form.submit(), 250);
    });
  }
  // Sort select submits immediately so the grid re-orders without an extra click.
  const sortSel = form.querySelector('[data-js="sort-select"]');
  if (sortSel) {
    sortSel.addEventListener('change', () => form.submit());
  }
}

// Publish the current filter set + visible recipe ids so the AI knows
// which slice of the library the user is looking at.
const browsePage = document.querySelector('[data-page="recipes-index"], [data-page="recipes-favorites"]');
if (browsePage) {
  const fd = form ? new FormData(form) : null;
  const filters = {};
  if (fd) {
    for (const k of ['search', 'cuisine', 'time', 'tag', 'sort']) {
      const v = (fd.get(k) || '').toString().trim();
      if (v && v !== 'All') filters[k] = v;
    }
  }
  setWindowContext({
    page: browsePage.getAttribute('data-page'),
    filters,
    // Leave visible_ids empty here — getWindowContext() falls back to scanning
    // [data-recipe-id] in the DOM, which already covers the rendered cards.
  });
}
