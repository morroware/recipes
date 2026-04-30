// public_html/assets/js/window-context.js
// Per-page context payload for the AI assistant.
//
// Page-specific modules call setWindowContext({...}) to publish their slice
// (recipe id, visible filters, current selection, etc.). Both chat surfaces
// (ai.js floating panel and the dedicated /chat page) call getWindowContext()
// when sending /api/ai/chat so the model knows exactly what the user is
// looking at.
//
// The shape returned to the server:
//   {
//     page: 'recipes-show' | 'pantry' | 'plan' | 'shopping' | …,
//     recipe_id: 123 | null,
//     selection_text: '…' | '',
//     filters: { search?, cuisine?, tag?, time?, sort?, … },
//     visible_ids: [12, 17, 22],   // up to 20 currently-rendered recipe ids
//   }
//
// Fields are best-effort: the server treats every IDs field as untrusted hints,
// re-fetches from the DB, and ignores anything the user can't actually see.

const MAX_VISIBLE_IDS = 20;
const MAX_SELECTION_LEN = 800;

let published = {};

const pageName = () => {
  const el = document.querySelector('[data-page]');
  return el ? (el.getAttribute('data-page') || '') : '';
};

const currentSelection = () => {
  try {
    const sel = (window.getSelection && window.getSelection()) || null;
    if (!sel) return '';
    const text = sel.toString().trim();
    if (!text) return '';
    return text.length > MAX_SELECTION_LEN
      ? text.slice(0, MAX_SELECTION_LEN)
      : text;
  } catch {
    return '';
  }
};

// Scan the DOM for visible recipe ids (cards on browse/favorites,
// the meal plan grid, etc.). Caps at MAX_VISIBLE_IDS so payloads stay tight.
const visibleRecipeIds = () => {
  const ids = new Set();
  const nodes = document.querySelectorAll('[data-recipe-id]');
  for (const el of nodes) {
    const v = parseInt(el.getAttribute('data-recipe-id') || '0', 10);
    if (v > 0) ids.add(v);
    if (ids.size >= MAX_VISIBLE_IDS) break;
  }
  return Array.from(ids);
};

export function setWindowContext(patch) {
  if (!patch || typeof patch !== 'object') return;
  published = { ...published, ...patch };
}

export function clearWindowContext() {
  published = {};
}

export function getWindowContext() {
  const page = published.page || pageName() || '';
  const filters = (published.filters && typeof published.filters === 'object')
    ? published.filters
    : {};
  const visible = Array.isArray(published.visible_ids) && published.visible_ids.length
    ? published.visible_ids.slice(0, MAX_VISIBLE_IDS).map(n => parseInt(n, 10)).filter(Boolean)
    : visibleRecipeIds();
  const recipeId = published.recipe_id != null ? parseInt(published.recipe_id, 10) || null : null;

  return {
    page,
    recipe_id: recipeId,
    selection_text: currentSelection(),
    filters,
    visible_ids: visible,
  };
}
