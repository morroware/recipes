// public_html/assets/js/pantry.js
// Pantry page interactions: add, toggle in-stock, change category, remove,
// shop-restock for OOS rows, OOS section toggle, ingredient-tag chip search.
//
// Server-rendered first; this script reloads after mutations to keep the
// grouped/most-used/suggestions panels consistent with the prototype without
// re-implementing client-side state.

import { apiFetch, toast } from './app.js';
import { setWindowContext } from './window-context.js';

const page = document.querySelector('[data-page="pantry"]');
if (page) {
  setWindowContext({ page: 'pantry' });

  // ---- Add new item -------------------------------------------------------
  const addForm = page.querySelector('[data-js="pantry-add"]');
  if (addForm) {
    let adding = false;
    addForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (adding) return;
      const input = addForm.querySelector('input[name="name"]');
      const name = (input.value || '').trim();
      if (!name) return;
      adding = true;
      const submitBtn = addForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      try {
        const { data } = await apiFetch('/api/pantry', {
          method: 'POST',
          body: JSON.stringify({ name, in_stock: true }),
        });
        input.value = '';
        // Reload so the server can re-bucket the item into its proper category
        // group + recompute "What can I make?" suggestions.  We could prepend
        // optimistically, but the page's grouping/sort + suggestions render is
        // server-side; reloading is the safer correctness choice.
        toast(`+ Added ${data?.item?.name || name}`);
        setTimeout(() => location.reload(), 200);
      } catch { /* toast already emitted */ }
      finally {
        adding = false;
        if (submitBtn) submitBtn.disabled = false;
      }
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
    const actionEl = e.target.closest('[data-action]');
    const action = actionEl?.dataset.action;
    if (!action) return;

    if (action === 'toggle-stock') {
      e.preventDefault();
      // Read state off the resolved action element, not e.target — if the
      // button gains a child element later, e.target may be that child and
      // the .checked class read would lie.
      const checked = actionEl.classList.contains('checked');
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
          body: JSON.stringify({ name, source_label: 'pantry' }),
        });
        toast(`+ Added "${name}" to shopping list`);
      } catch { /* apiFetch already toasted */ }
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
    // Intercept submit so we can splice the typed draft into the hidden
    // `tags` field. Works for Enter-in-input AND clicking the submit button.
    tagForm.addEventListener('submit', (e) => {
      const v = (draft.value || '').trim().toLowerCase();
      if (!v) {
        // Empty draft + existing tags — let the form submit as-is so the
        // user can re-trigger the search. If there are no tags either,
        // there's nothing to do.
        if (!(hidden.value || '').trim()) e.preventDefault();
        return;
      }
      const current = (hidden.value || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
      if (current.includes(v)) { draft.value = ''; e.preventDefault(); return; }
      current.push(v);
      hidden.value = current.join(',');
      // Native form submit takes over from here.
    });
  }
}
