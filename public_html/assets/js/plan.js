// public_html/assets/js/plan.js
// Meal plan: pick a recipe per day, clear day, clear week, build shopping list.
// Uses the vanilla RecipePicker class for the modal picker.

import { apiFetch, toast, appUrl } from './app.js';
import { RecipePicker } from './recipe-picker.js';

const page = document.querySelector('[data-page="plan"]');
if (page) {
  // Read recipes JSON injected by the view.
  const dataNode = document.querySelector('script[data-bind="plan-recipes"]');
  const recipes = dataNode ? JSON.parse(dataNode.textContent || '[]') : [];

  const overlay   = page.querySelector('[data-js="picker-overlay"]');
  const dayLabel  = page.querySelector('[data-js="picker-day"]');
  const closeBtn  = page.querySelector('[data-js="picker-close"]');
  const mount     = page.querySelector('[data-js="picker-mount"]');
  let picker = null;
  let activeDay = null;
  let lastFocus = null;

  function openPicker(day, anchor) {
    activeDay = day;
    lastFocus = anchor || document.activeElement;
    if (dayLabel) dayLabel.textContent = day;
    overlay.hidden = false;
    if (!picker) {
      picker = new RecipePicker(mount, {
        recipes,
        mode: 'single',
        selected: null,
        height: 480,
        onChange: async (id) => {
          if (!activeDay || id == null) return;
          try {
            await apiFetch(`/api/plan/${encodeURIComponent(activeDay)}`, {
              method: 'PUT',
              body: JSON.stringify({ recipe_id: id }),
            });
            closePicker();
            location.reload();
          } catch {}
        },
      });
    } else {
      picker.setSelected(null);
    }
  }
  function closePicker() {
    overlay.hidden = true;
    activeDay = null;
    lastFocus?.focus();
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
        setTimeout(() => { location.href = appUrl('/shopping'); }, 800);
      }
    } catch {} finally { btn.disabled = false; }
  });
}
