// public_html/assets/js/shopping.js
// Shopping list interactions: add manual, toggle, remove, clear all,
// 🥕 Stock pantry (move checked → pantry), print.

import { apiFetch, toast } from './app.js';

const page = document.querySelector('[data-page="shopping"]');
if (page) {
  const list      = page.querySelector('[data-js="list"]');
  const stockBtn  = page.querySelector('[data-js="stock-pantry"]');
  const stockN    = page.querySelector('[data-js="stock-count"]');
  const checkedN  = page.querySelector('[data-js="count-checked"]');
  const totalN    = page.querySelector('[data-js="count-total"]');
  const printBtn  = page.querySelector('[data-js="print-btn"]');
  const clearBtn  = page.querySelector('[data-js="clear-all"]');
  const addForm   = page.querySelector('[data-js="add-form"]');

  const countPill = page.querySelector('[data-js="count-pill"]');
  const recompute = () => {
    if (!list) return;
    const rows = list.querySelectorAll('.shop-row');
    const total = rows.length;
    let checked = 0;
    rows.forEach(r => { if (r.querySelector('.shop-check.checked')) checked++; });
    if (checkedN) checkedN.textContent = checked;
    if (totalN)   totalN.textContent   = total;
    if (stockBtn) {
      stockBtn.hidden = checked === 0;
      if (stockN) stockN.textContent = checked;
    }
    if (countPill) countPill.style.display = total === 0 ? 'none' : '';
    if (clearBtn) clearBtn.hidden = total === 0;
    if (total === 0) {
      // Reload to render the server-side empty state and remove the list panel.
      // This also hides the print/clear buttons via PHP conditionals.
      location.reload();
    }
  };

  // ---- add form ----------------------------------------------------------
  if (addForm) {
    addForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = addForm.querySelector('input[name="name"]');
      const name = (input.value || '').trim();
      if (!name) return;
      try {
        await apiFetch('/api/shopping', {
          method: 'POST',
          body: JSON.stringify({ name, source: 'manual' }),
        });
        input.value = '';
        location.reload();
      } catch {}
    });
  }

  // ---- row actions -------------------------------------------------------
  if (list) {
    list.addEventListener('click', async (e) => {
      const row = e.target.closest('.shop-row');
      if (!row) return;
      const action = e.target.closest('[data-action]')?.dataset.action;
      if (!action) return;
      const id = row.dataset.id;

      if (action === 'toggle') {
        e.preventDefault();
        const check = row.querySelector('.shop-check');
        const name  = row.querySelector('.shop-name');
        const isChecked = check.classList.contains('checked');
        try {
          await apiFetch(`/api/shopping/${id}`, {
            method: 'PATCH',
            body: JSON.stringify({ checked: !isChecked }),
          });
          check.classList.toggle('checked', !isChecked);
          check.setAttribute('aria-pressed', !isChecked ? 'true' : 'false');
          if (name) name.classList.toggle('checked', !isChecked);
          recompute();
        } catch {}
        return;
      }
      if (action === 'remove') {
        e.preventDefault();
        try {
          await apiFetch(`/api/shopping/${id}`, { method: 'DELETE' });
          row.remove();
          recompute();
        } catch {}
      }
    });
  }

  // ---- stock pantry ------------------------------------------------------
  if (stockBtn) {
    stockBtn.addEventListener('click', async () => {
      stockBtn.disabled = true;
      try {
        const { data } = await apiFetch('/api/shopping/move-to-pantry', { method: 'POST' });
        const { added, moved } = data || {};
        let msg;
        if (!moved)              msg = 'No checked items to move.';
        else if (added === 0)    msg = `Moved ${moved} item${moved === 1 ? '' : 's'} off your list — already in pantry`;
        else if (added === moved)msg = `🥕 ${added} item${added === 1 ? '' : 's'} added to pantry`;
        else                     msg = `🥕 ${added} new to pantry, ${moved - added} already had`;
        toast(msg);
        location.reload();
      } catch {
        stockBtn.disabled = false;
      }
    });
  }

  // ---- clear all ---------------------------------------------------------
  if (clearBtn) {
    clearBtn.addEventListener('click', async () => {
      if (!confirm('Clear the entire shopping list?')) return;
      try {
        await apiFetch('/api/shopping', { method: 'DELETE' });
        location.reload();
      } catch {}
    });
  }

  // ---- print -------------------------------------------------------------
  if (printBtn) printBtn.addEventListener('click', () => window.print());
}

// ---- Detail-page integration: 🛒 Add to shopping --------------------------
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-action="add-to-shopping"]');
  if (!btn) return;
  e.preventDefault();
  const id = btn.dataset.recipeId;
  if (!id) return;
  // Honor current scale on the detail page if available.
  const root = document.querySelector('[data-recipe]');
  let scale = 1;
  if (root) {
    const base = parseInt(root.dataset.baseServings || '1', 10) || 1;
    const cur  = parseInt(root.querySelector('[data-bind="servings"]')?.dataset.current || base, 10) || base;
    scale = base > 0 ? cur / base : 1;
  }
  btn.disabled = true;
  try {
    const { data } = await apiFetch(`/api/shopping/from-recipe/${id}?scale=${encodeURIComponent(scale)}`, {
      method: 'POST',
    });
    const added = data?.added ?? 0;
    const skipped = data?.skipped ?? 0;
    let msg = `🛒 Added ${added} ingredient${added === 1 ? '' : 's'} to shopping`;
    if (skipped) msg += ` (${skipped} already on list)`;
    toast(msg);
  } catch {} finally { btn.disabled = false; }
});
