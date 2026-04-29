// public_html/assets/js/plan.js
// Meal plan: pick a recipe per day, clear day, clear week, build shopping list.
// Uses a simple modal + searchable list (lightweight RecipePicker stand-in;
// full RecipePicker port lands in Phase 6).

import { apiFetch, toast } from './app.js';

const page = document.querySelector('[data-page="plan"]');
if (!page) { /* no-op */ }
else {
  const overlay     = page.querySelector('[data-js="picker-overlay"]');
  const dayLabel    = page.querySelector('[data-js="picker-day"]');
  const closeBtn    = page.querySelector('[data-js="picker-close"]');
  const searchInput = page.querySelector('[data-js="picker-search"]');
  const list        = page.querySelector('[data-js="picker-list"]');
  let activeDay = null;
  let lastFocus = null;

  // ---- open/close picker -------------------------------------------------
  function openPicker(day, anchor) {
    activeDay = day;
    lastFocus = anchor || document.activeElement;
    if (dayLabel) dayLabel.textContent = day;
    if (searchInput) searchInput.value = '';
    filterList('');
    overlay.hidden = false;
    setTimeout(() => searchInput?.focus(), 0);
  }
  function closePicker() {
    overlay.hidden = true;
    activeDay = null;
    lastFocus?.focus();
  }
  function filterList(q) {
    const needle = q.trim().toLowerCase();
    list.querySelectorAll('.recipe-picker-row').forEach(row => {
      const hay = row.dataset.search || '';
      row.hidden = needle && !hay.includes(needle);
    });
  }

  page.addEventListener('click', async (e) => {
    const slot = e.target.closest('[data-action="open-picker"]');
    if (slot) {
      const col = slot.closest('[data-day]');
      if (col) openPicker(col.dataset.day, slot);
      return;
    }
    const x = e.target.closest('[data-action="clear-day"]');
    if (x) {
      e.preventDefault();
      e.stopPropagation();
      const col = x.closest('[data-day]');
      if (!col) return;
      try {
        await apiFetch(`/api/plan/${encodeURIComponent(col.dataset.day)}`, {
          method: 'PUT',
          body: JSON.stringify({ recipe_id: null }),
        });
        location.reload();
      } catch {}
    }
  });

  closeBtn?.addEventListener('click', closePicker);
  overlay?.addEventListener('click', (e) => { if (e.target === overlay) closePicker(); });
  document.addEventListener('keydown', (e) => {
    if (overlay.hidden) return;
    if (e.key === 'Escape') closePicker();
  });
  searchInput?.addEventListener('input', () => filterList(searchInput.value));

  // pick a recipe for the active day
  list?.addEventListener('click', async (e) => {
    const row = e.target.closest('.recipe-picker-row');
    if (!row || !activeDay) return;
    const id = row.dataset.recipeId;
    if (!id) return;
    try {
      await apiFetch(`/api/plan/${encodeURIComponent(activeDay)}`, {
        method: 'PUT',
        body: JSON.stringify({ recipe_id: parseInt(id, 10) }),
      });
      closePicker();
      location.reload();
    } catch {}
  });
  list?.addEventListener('keydown', (e) => {
    const row = e.target.closest('.recipe-picker-row');
    if (!row) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      row.click();
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const rows = Array.from(list.querySelectorAll('.recipe-picker-row')).filter(r => !r.hidden);
      const idx = rows.indexOf(row);
      const next = e.key === 'ArrowDown' ? rows[idx + 1] : rows[idx - 1];
      next?.focus();
    }
  });

  // ---- top buttons -------------------------------------------------------
  page.querySelector('[data-js="clear-week"]')?.addEventListener('click', async () => {
    if (!confirm('Clear the entire week?')) return;
    try {
      await apiFetch('/api/plan', { method: 'DELETE' });
      location.reload();
    } catch {}
  });
  page.querySelector('[data-js="build-shopping"]')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    try {
      const { data } = await apiFetch('/api/plan/build-shopping-list', { method: 'POST' });
      const added = data?.added ?? 0;
      const recipes = data?.recipes ?? 0;
      if (recipes === 0) {
        toast('Plan is empty — assign recipes first', 'error');
      } else {
        toast(`🛒 Added ${added} ingredients from ${recipes} recipe${recipes === 1 ? '' : 's'}`);
        setTimeout(() => { location.href = '/shopping'; }, 800);
      }
    } catch {} finally { btn.disabled = false; }
  });
}
