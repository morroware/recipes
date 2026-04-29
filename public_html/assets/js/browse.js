// public_html/assets/js/browse.js
// Debounced auto-submit on the search input so typing narrows the grid
// without requiring an explicit click. Filters still work without JS.

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
