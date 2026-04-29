// public_html/assets/js/pantry.js
// Pantry page interactions: add, toggle in-stock, change category, remove,
// shop-restock for OOS rows, OOS section toggle, ingredient-tag chip search.
//
// Server-rendered first; this script reloads after mutations to keep the
// grouped/most-used/suggestions panels consistent with the prototype without
// re-implementing client-side state.

import { apiFetch, toast } from './app.js';

const page = document.querySelector('[data-page="pantry"]');
if (page) {
  // ---- Add new item -------------------------------------------------------
  const addForm = page.querySelector('[data-js="pantry-add"]');
  if (addForm) {
    addForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = addForm.querySelector('input[name="name"]');
      const name = (input.value || '').trim();
      if (!name) return;
      try {
        await apiFetch('/api/pantry', {
          method: 'POST',
          body: JSON.stringify({ name, in_stock: true }),
        });
        input.value = '';
        location.reload();
      } catch { /* toast already emitted */ }
    });
  }

  // ---- OOS section expand/collapse ---------------------------------------
  const oosBtn = page.querySelector('.pantry-oos-toggle');
  if (oosBtn) {
    const list = page.querySelector('[data-js="oos-list"]');
    const caret = page.querySelector('[data-js="oos-caret"]');
    oosBtn.addEventListener('click', () => {
      const open = list.style.display !== 'none';
      list.style.display = open ? 'none' : 'block';
      caret.textContent = open ? '▸' : '▾';
      oosBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
  }

  // ---- Row actions (delegated) -------------------------------------------
  page.addEventListener('click', async (e) => {
    const row = e.target.closest('.pantry-row');
    if (!row) return;
    const id = row.dataset.id;
    const name = row.dataset.name;
    const action = e.target.closest('[data-action]')?.dataset.action;
    if (!action) return;

    if (action === 'toggle-stock') {
      e.preventDefault();
      const checked = e.target.classList.contains('checked');
      try {
        await apiFetch(`/api/pantry/${id}`, {
          method: 'PATCH',
          body: JSON.stringify({ in_stock: !checked }),
        });
        location.reload();
      } catch {}
      return;
    }
    if (action === 'remove') {
      e.preventDefault();
      if (!confirm(`Remove "${name}" from pantry?`)) return;
      try {
        await apiFetch(`/api/pantry/${id}`, { method: 'DELETE' });
        row.remove();
        toast('Removed');
      } catch {}
      return;
    }
    if (action === 'shop') {
      e.preventDefault();
      try {
        await apiFetch('/api/shopping', {
          method: 'POST',
          body: JSON.stringify({ name, source: 'pantry' }),
        });
        toast(`+ Added "${name}" to shopping list`);
      } catch (err) {
        // Shopping API may not exist yet in earlier phases; fall back gracefully.
        if (String(err.message).includes('404') || String(err.message).includes('not_found')) {
          toast('Shopping list not available yet', 'error');
        }
      }
      return;
    }
    if (action === 'edit-category') {
      e.preventDefault();
      const sel = row.querySelector('select[data-action="set-category"]');
      const span = row.querySelector('.pantry-row-cat');
      if (!sel || !span) return;
      span.hidden = true;
      sel.hidden = false;
      sel.focus();
    }
  });

  // category select change/blur (delegated)
  page.addEventListener('change', async (e) => {
    const sel = e.target.closest('select[data-action="set-category"]');
    if (!sel) return;
    const row = sel.closest('.pantry-row');
    const id = row.dataset.id;
    const newCat = sel.value;
    try {
      await apiFetch(`/api/pantry/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ category: newCat }),
      });
      location.reload();
    } catch {}
  });
  page.addEventListener('focusout', (e) => {
    const sel = e.target.closest('select[data-action="set-category"]');
    if (!sel) return;
    const row = sel.closest('.pantry-row');
    const span = row.querySelector('.pantry-row-cat');
    sel.hidden = true;
    if (span) span.hidden = false;
  });

  // ---- Tag-mode chip composer -------------------------------------------
  const tagForm = page.querySelector('[data-js="pantry-tag-form"]');
  if (tagForm) {
    const draft = tagForm.querySelector('[data-js="tag-draft"]');
    const hidden = tagForm.querySelector('[data-js="tags-hidden"]');
    const addBtn = tagForm.querySelector('[data-js="tag-add"]');
    const submitTag = () => {
      const v = (draft.value || '').trim().toLowerCase();
      if (!v) return;
      const current = (hidden.value || '').split(',').map(s => s.trim()).filter(Boolean);
      if (current.includes(v)) { draft.value = ''; return; }
      current.push(v);
      hidden.value = current.join(',');
      tagForm.submit();
    };
    addBtn.addEventListener('click', (e) => { e.preventDefault(); submitTag(); });
    draft.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); submitTag(); }
    });
  }
}
