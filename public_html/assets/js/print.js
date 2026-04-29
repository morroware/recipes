// public_html/assets/js/print.js
// Print Hub interactions: print-now, recipe pickers (single for card,
// multi for booklet), shortcuts (favorites, week plan).

import { apiFetch, toast } from './app.js';
import { RecipePicker } from './recipe-picker.js';

const page = document.querySelector('[data-page="print"]');
if (page) {
  const mode = page.dataset.mode;

  page.querySelector('[data-js="print-now"]')?.addEventListener('click', () => window.print());

  // Load recipes payload from view (single source of truth for both pickers).
  const dataNode = document.querySelector('script[data-bind="print-recipes"]');
  const recipes = dataNode ? JSON.parse(dataNode.textContent || '[]') : [];

  // ---- Card mode: single picker -----------------------------------------
  if (mode === 'card') {
    const mount = page.querySelector('[data-js="card-picker"]');
    const cardId = parseInt(page.dataset.cardId || '0', 10) || null;
    if (mount) {
      new RecipePicker(mount, {
        recipes, mode: 'single', selected: cardId, height: 480,
        onChange: (id) => {
          if (id == null) return;
          const url = new URL(location.href);
          url.searchParams.set('id', id);
          location.href = url.toString();
        },
      });
    }
  }

  // ---- Booklet mode: multi picker + shortcuts ---------------------------
  if (mode === 'booklet') {
    const mount = page.querySelector('[data-js="booklet-picker"]');
    let ids = (page.dataset.bookletIds || '')
      .split(',').map(s => parseInt(s, 10)).filter(Number.isFinite);
    let picker = null;
    if (mount) {
      picker = new RecipePicker(mount, {
        recipes, mode: 'multi', selected: ids, height: 440,
        onChange: (next) => { ids = next; updateUrl(); },
      });
    }
    function updateUrl() {
      const url = new URL(location.href);
      if (ids.length) url.searchParams.set('ids', ids.join(','));
      else url.searchParams.delete('ids');
      // Use a debounce so checkboxes feel snappy; reload to repaint preview.
      clearTimeout(updateUrl._t);
      updateUrl._t = setTimeout(() => { location.href = url.toString(); }, 320);
    }
    page.querySelector('[data-js="booklet-clear"]')?.addEventListener('click', () => {
      ids = []; picker?.setSelected([]); updateUrl();
    });
    page.querySelector('[data-js="booklet-add-favs"]')?.addEventListener('click', () => {
      const favs = recipes.filter(r => r.is_favorite).map(r => r.id);
      ids = Array.from(new Set([...ids, ...favs]));
      picker?.setSelected(ids);
      updateUrl();
    });
    page.querySelector('[data-js="booklet-add-week"]')?.addEventListener('click', async () => {
      try {
        const { data } = await apiFetch('/api/plan');
        const plan = data?.plan || {};
        const weekIds = Object.values(plan).filter(Boolean).map(r => r.id);
        ids = Array.from(new Set([...ids, ...weekIds]));
        picker?.setSelected(ids);
        updateUrl();
      } catch {}
    });
  }
}
