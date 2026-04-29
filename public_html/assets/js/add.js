// public_html/assets/js/add.js
// Add / edit recipe form. Vanilla form management — dynamic ingredient and
// step editors, color/difficulty pickers, debounced save status.

import { apiFetch, toast, appUrl } from './app.js';

const page = document.querySelector('[data-page="add"]');
if (page) {
  const mode     = page.dataset.mode;          // 'create' | 'edit'
  const recipeId = parseInt(page.dataset.recipeId || '0', 10);
  const form     = page.querySelector('[data-js="recipe-form"]');
  const ingList  = page.querySelector('[data-js="ingredients"]');
  const stepList = page.querySelector('[data-js="steps"]');

  // ---- Difficulty chips --------------------------------------------------
  const diffRow = page.querySelector('[data-js="diff-row"]');
  const diffInput = diffRow?.querySelector('input[name="difficulty"]');
  diffRow?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-difficulty]');
    if (!btn) return;
    e.preventDefault();
    diffInput.value = btn.dataset.difficulty;
    for (const sib of diffRow.querySelectorAll('[data-difficulty]')) {
      sib.classList.toggle('active', sib === btn);
    }
  });

  // ---- Color swatches ----------------------------------------------------
  const colorRow = page.querySelector('[data-js="color-row"]');
  const colorInput = colorRow?.querySelector('input[name="color"]');
  colorRow?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-color]');
    if (!btn) return;
    e.preventDefault();
    colorInput.value = btn.dataset.color;
    for (const sib of colorRow.querySelectorAll('[data-color]')) {
      const sel = sib === btn;
      sib.style.border = sel ? '3px solid var(--ink)' : '2px solid var(--ink)';
      sib.style.boxShadow = sel ? '3px 3px 0 var(--ink)' : 'none';
    }
  });

  // ---- Ingredients add/remove -------------------------------------------
  const aisles = ['Produce','Pantry','Dairy','Meat & Fish','Bakery','Frozen','Spices','Other'];
  function newIngredientRow() {
    const div = document.createElement('div');
    div.className = 'form-row-mini';
    div.dataset.js = 'ingredient-row';
    div.innerHTML = `
      <input class="form-input" data-field="qty"  placeholder="qty">
      <input class="form-input" data-field="unit" placeholder="unit">
      <input class="form-input" data-field="name" placeholder="ingredient">
      <select class="form-input" data-field="aisle">
        ${aisles.map(a => `<option value="${a}">${a}</option>`).join('')}
      </select>
      <button type="button" class="btn btn-sm btn-ghost" data-action="remove-ingredient">✕</button>
    `;
    return div;
  }
  page.querySelector('[data-action="add-ingredient"]')?.addEventListener('click', () => {
    ingList.appendChild(newIngredientRow());
  });

  // ---- Steps add/remove --------------------------------------------------
  function renumberSteps() {
    stepList.querySelectorAll('[data-js="step-num"]').forEach((s, i) => s.textContent = (i + 1));
  }
  function newStepRow() {
    const div = document.createElement('div');
    div.className = 'form-field';
    div.dataset.js = 'step-row';
    div.innerHTML = `
      <div class="row" style="align-items: flex-start;">
        <span class="step-num" style="flex-shrink: 0;" data-js="step-num"></span>
        <textarea class="form-textarea" data-field="step" style="min-height: 60px;"></textarea>
        <button type="button" class="btn btn-sm btn-ghost" data-action="remove-step">✕</button>
      </div>
    `;
    return div;
  }
  page.querySelector('[data-action="add-step"]')?.addEventListener('click', () => {
    stepList.appendChild(newStepRow());
    renumberSteps();
  });

  // Delegated remove
  page.addEventListener('click', (e) => {
    const ri = e.target.closest('[data-action="remove-ingredient"]');
    if (ri) { ri.closest('[data-js="ingredient-row"]')?.remove(); return; }
    const rs = e.target.closest('[data-action="remove-step"]');
    if (rs) { rs.closest('[data-js="step-row"]')?.remove(); renumberSteps(); }
  });

  // ---- Submit ------------------------------------------------------------
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const ingredients = Array.from(ingList.querySelectorAll('[data-js="ingredient-row"]'))
      .map(row => ({
        qty:   row.querySelector('[data-field="qty"]').value.trim(),
        unit:  row.querySelector('[data-field="unit"]').value.trim(),
        name:  row.querySelector('[data-field="name"]').value.trim(),
        aisle: row.querySelector('[data-field="aisle"]').value,
      }))
      .filter(i => i.name);
    const steps = Array.from(stepList.querySelectorAll('[data-js="step-row"]'))
      .map(row => row.querySelector('[data-field="step"]').value.trim())
      .filter(Boolean);

    const payload = {
      title:        (fd.get('title') || '').toString().trim(),
      summary:      (fd.get('summary') || '').toString(),
      cuisine:      (fd.get('cuisine') || '').toString().trim(),
      time_minutes: parseInt(fd.get('time_minutes') || '0', 10),
      servings:     parseInt(fd.get('servings') || '1', 10),
      difficulty:   (fd.get('difficulty') || 'Easy').toString(),
      glyph:        (fd.get('glyph') || '🍽️').toString(),
      color:        (fd.get('color') || 'mint').toString(),
      photo_url:    (fd.get('photo_url') || '').toString().trim(),
      tags:         (fd.get('tags') || '').toString(),
      notes:        (fd.get('notes') || '').toString(),
      ingredients,
      steps,
    };
    if (!payload.title) {
      toast('Title is required', 'error');
      return;
    }

    const status = page.querySelector('[data-js="save-status"]');
    const btn    = page.querySelector('[data-js="save-btn"]');
    btn.disabled = true;
    if (status) status.textContent = 'Saving…';
    try {
      let url, method;
      if (mode === 'edit') {
        url = `/api/recipes/${recipeId}`; method = 'PUT';
      } else {
        url = '/api/recipes'; method = 'POST';
      }
      const { data } = await apiFetch(url, {
        method,
        body: JSON.stringify(payload),
      });
      const id = data?.id ?? recipeId;
      toast(mode === 'edit' ? '✓ Saved' : '✓ Recipe saved');
      setTimeout(() => { location.href = `/recipes/${id}`; }, 400);
    } catch (err) {
      if (status) status.textContent = '';
      btn.disabled = false;
    }
  });

  // ---- Delete (edit mode) -----------------------------------------------
  page.querySelector('[data-js="delete-btn"]')?.addEventListener('click', async () => {
    if (!confirm('Delete this recipe? This cannot be undone.')) return;
    try {
      await apiFetch(`/api/recipes/${recipeId}`, { method: 'DELETE' });
      toast('🗑 Deleted');
      setTimeout(() => { location.href = appUrl('/'); }, 400);
    } catch {}
  });
}
