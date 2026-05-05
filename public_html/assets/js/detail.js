// public_html/assets/js/detail.js
// Servings scaler with metric/imperial conversion, cooking-mode dialog,
// autosaving notes. Ported from project/pages-a.jsx (DetailPage + CookMode).

import { apiFetch, toast } from './app.js';
import { setWindowContext } from './window-context.js';

const root = document.querySelector('[data-recipe]');
if (root) {
  setWindowContext({
    page: 'recipes-show',
    recipe_id: parseInt(root.dataset.recipeId || '0', 10) || null,
    visible_ids: [parseInt(root.dataset.recipeId || '0', 10) || 0].filter(Boolean),
  });


  // ---- ingredient JSON pulled from the page payload --------------------------
  const ingsEl  = document.querySelector('[data-bind="recipe-ingredients"]');
  const stepsEl = document.querySelector('[data-bind="recipe-steps"]');
  const ingredients = ingsEl ? JSON.parse(ingsEl.textContent || '[]') : [];
  const steps       = stepsEl ? JSON.parse(stepsEl.textContent || '[]') : [];

  const baseServings = parseInt(root.dataset.baseServings || '1', 10) || 1;
  let scaledServings = baseServings;
  let units = root.dataset.units === 'imperial' ? 'imperial' : 'metric';

  // ---- fmtQty (ported verbatim from pages-a.jsx fmtQty) ---------------------
  function fmtQty(q, unit) {
    if (q === '' || q === null || q === undefined) return unit ? unit : '';
    const scale = scaledServings / baseServings;
    let v = Number(q) * scale;
    let u = unit || '';
    if (units === 'imperial') {
      if (u === 'g')       { v = v / 28.35;   u = 'oz'; }
      else if (u === 'kg') { v = v * 2.205;   u = 'lb'; }
      else if (u === 'ml') { v = v / 29.57;   u = 'fl oz'; }
      else if (u === 'l')  { v = v * 4.227;   u = 'cup'; }
    }
    if (v < 1 && v > 0) {
      const fracs = [[0.125, '⅛'], [0.25, '¼'], [0.33, '⅓'], [0.5, '½'], [0.66, '⅔'], [0.75, '¾']];
      const closest = fracs.reduce((p, c) => Math.abs(c[0] - v) < Math.abs(p[0] - v) ? c : p);
      return closest[1] + (u ? ' ' + u : '');
    }
    const n = Number.isInteger(v) ? v : v.toFixed(1).replace(/\.0$/, '');
    return n + (u ? ' ' + u : '');
  }

  // ---- render ingredient quantities ----------------------------------------
  const list   = root.querySelector('[data-bind="ingredient-list"]');
  const rows   = list ? Array.from(list.querySelectorAll('.ingredient-row')) : [];
  const servEl = root.querySelector('[data-bind="servings"]');

  function renderIngredients() {
    rows.forEach((row, i) => {
      const ing = ingredients[i];
      if (!ing) return;
      const qtyEl = row.querySelector('.ingredient-qty');
      if (qtyEl) qtyEl.textContent = fmtQty(ing.qty, ing.unit);
    });
    if (servEl) {
      servEl.textContent = scaledServings + ' servings';
      servEl.dataset.current = String(scaledServings);
    }
  }
  renderIngredients();

  // ---- servings + / - ------------------------------------------------------
  root.addEventListener('click', (e) => {
    const t = e.target.closest('[data-action]');
    if (!t) return;
    if (t.dataset.action === 'servings-up')   { scaledServings += 1; renderIngredients(); }
    if (t.dataset.action === 'servings-down') { scaledServings = Math.max(1, scaledServings - 1); renderIngredients(); }
    if (t.dataset.action === 'print')         { window.print(); }
    if (t.dataset.action === 'cook-mode')     { openCookMode(); }
    if (t.dataset.action === 'cook-close')    { closeCookMode(); }
    if (t.dataset.action === 'cook-prev')     { setCookStep(cookStep - 1); }
    if (t.dataset.action === 'cook-next')     { setCookStep(cookStep + 1); }
    if (t.dataset.action === 'log-cooked')    { logCooked(t); }
  });

  // ---- "I made this" — record a cook + bump pantry usage --------------------
  async function logCooked(btn) {
    const id = btn.dataset.recipeId;
    const title = btn.dataset.recipeTitle || '';
    const result = await openCookLogDialog(title);
    if (!result) return;
    btn.disabled = true;
    try {
      await apiFetch('/api/cooking-log', {
        method: 'POST',
        body: JSON.stringify({
          recipe_id: id ? Number(id) : null,
          recipe_title: title,
          rating: result.rating,
          notes: result.notes,
        }),
      });
      toast('🍽️ Logged. The assistant will remember.');
      btn.classList.add('btn-mint');
    } catch {
      /* toast already shown */
    } finally {
      btn.disabled = false;
    }
  }

  // Inline rating + notes dialog. Returns {rating: number|null, notes: string|null}
  // on save, or null on cancel. iOS prompt() is awful, this is much friendlier.
  function openCookLogDialog(title) {
    return new Promise((resolve) => {
      const dlg = document.createElement('dialog');
      dlg.className = 'cook-log-dialog';
      dlg.setAttribute('aria-label', 'Log this cook');
      dlg.innerHTML = `
        <form method="dialog" class="cook-log-form" novalidate>
          <h3 style="margin: 0 0 4px;">🍽️ I made this</h3>
          <p class="muted" style="margin: 0 0 12px; font-size: 13px;"></p>
          <div class="cook-log-stars" role="radiogroup" aria-label="Rating">
            ${[1,2,3,4,5].map(n => `
              <button type="button" class="cook-log-star" data-star="${n}"
                      aria-label="${n} star${n === 1 ? '' : 's'}"
                      role="radio" aria-checked="false">☆</button>
            `).join('')}
            <button type="button" class="btn btn-sm btn-ghost cook-log-clear" aria-label="Clear rating">No rating</button>
          </div>
          <label class="cook-log-notes-label">
            <span class="page-eyebrow">NOTES (OPTIONAL)</span>
            <textarea class="form-textarea cook-log-notes" rows="3"
              placeholder="Used less salt; doubled the sauce…"></textarea>
          </label>
          <div class="cook-log-actions">
            <button type="button" class="btn btn-ghost" data-act="cancel">Cancel</button>
            <button type="submit" class="btn btn-primary" data-act="save">Save</button>
          </div>
        </form>
      `;
      dlg.querySelector('p.muted').textContent = title || '';
      document.body.appendChild(dlg);

      let rating = null;
      const stars = Array.from(dlg.querySelectorAll('.cook-log-star'));
      const paint = () => {
        stars.forEach(b => {
          const n = parseInt(b.dataset.star, 10);
          const on = rating !== null && n <= rating;
          b.textContent = on ? '★' : '☆';
          b.classList.toggle('active', on);
          b.setAttribute('aria-checked', rating === n ? 'true' : 'false');
        });
      };
      stars.forEach(b => b.addEventListener('click', () => {
        const n = parseInt(b.dataset.star, 10);
        rating = (rating === n) ? null : n;
        paint();
      }));
      dlg.querySelector('.cook-log-clear').addEventListener('click', () => { rating = null; paint(); });

      const cleanup = (val) => { dlg.close(); dlg.remove(); resolve(val); };
      dlg.querySelector('[data-act="cancel"]').addEventListener('click', () => cleanup(null));
      dlg.addEventListener('close', () => { if (dlg.isConnected) dlg.remove(); });
      dlg.addEventListener('cancel', (e) => { e.preventDefault(); cleanup(null); });
      dlg.querySelector('form').addEventListener('submit', (e) => {
        e.preventDefault();
        const notes = (dlg.querySelector('.cook-log-notes').value || '').trim();
        cleanup({ rating, notes: notes || null });
      });

      if (typeof dlg.showModal === 'function') dlg.showModal();
      else dlg.setAttribute('open', '');
      stars[0]?.focus();
    });
  }


  // ---- cooking-mode dialog -------------------------------------------------
  const dialog = root.querySelector('[data-bind="cook-dialog"]');
  const stepNumEl   = root.querySelector('[data-bind="cook-step-num"]');
  const stepTextEl  = root.querySelector('[data-bind="cook-step-text"]');
  const progressEl  = root.querySelector('[data-bind="cook-progress"]');
  let cookStep = 0;
  let cookOpener = null;

  function openCookMode() {
    if (!dialog || steps.length === 0) return;
    cookOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    cookStep = 0;
    setCookStep(0);
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    document.addEventListener('keydown', onCookKey);
    const nextBtn = root.querySelector('[data-action="cook-next"], [data-action="cook-close"]');
    if (nextBtn) nextBtn.focus();
  }
  function closeCookMode() {
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }
  // Native `close` fires for ESC, dialog.close(), and form-method=dialog submits.
  // Centralizing cleanup here means we never leak the keydown handler.
  if (dialog) {
    dialog.addEventListener('close', () => {
      document.removeEventListener('keydown', onCookKey);
      if (cookOpener && typeof cookOpener.focus === 'function') {
        cookOpener.focus();
      }
      cookOpener = null;
    });
  }
  function setCookStep(n) {
    if (!steps.length) return;
    cookStep = Math.min(steps.length - 1, Math.max(0, n));
    if (stepNumEl)  stepNumEl.textContent  = `STEP ${cookStep + 1} OF ${steps.length}`;
    if (stepTextEl) stepTextEl.textContent = steps[cookStep];
    if (progressEl) progressEl.style.width = `${((cookStep + 1) / steps.length) * 100}%`;

    const nextBtn = root.querySelector('[data-action="cook-next"]');
    const prevBtn = root.querySelector('[data-action="cook-prev"]');
    if (prevBtn) prevBtn.disabled = cookStep === 0;
    if (nextBtn) {
      const last = cookStep === steps.length - 1;
      nextBtn.textContent = last ? '🎉 Done!' : 'Next →';
      nextBtn.classList.toggle('btn-mint',    last);
      nextBtn.classList.toggle('btn-primary', !last);
      nextBtn.dataset.action = last ? 'cook-close' : 'cook-next';
    }
  }
  function onCookKey(e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); setCookStep(cookStep + 1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); setCookStep(cookStep - 1); }
    else if (e.key === 'Escape') { closeCookMode(); }
  }

  // ---- notes autosave ------------------------------------------------------
  const notes = root.querySelector('[data-action="save-notes"]');
  const notesStatus = root.querySelector('[data-bind="notes-status"]');
  if (notes) {
    let t = null;
    let lastSaved = notes.value;
    const setStatus = (msg) => { if (notesStatus) notesStatus.textContent = msg; };
    const queueSave = () => {
      clearTimeout(t);
      if (notes.value === lastSaved) { setStatus('Saved'); return; }
      setStatus('Saving…');
      t = setTimeout(async () => {
        try {
          await apiFetch(`/api/recipes/${notes.dataset.recipeId}/notes`, {
            method: 'PUT',
            body: JSON.stringify({ notes: notes.value }),
          });
          lastSaved = notes.value;
          setStatus('✓ Saved');
        } catch {
          setStatus('Save failed');
        }
      }, 600);
    };
    notes.addEventListener('input', queueSave);
    notes.addEventListener('blur', () => {
      // Force an immediate save on blur if there are unsaved changes.
      if (notes.value === lastSaved) return;
      clearTimeout(t);
      setStatus('Saving…');
      apiFetch(`/api/recipes/${notes.dataset.recipeId}/notes`, {
        method: 'PUT',
        body: JSON.stringify({ notes: notes.value }),
      }).then(() => { lastSaved = notes.value; setStatus('✓ Saved'); })
        .catch(() => setStatus('Save failed'));
    });
  }
}
