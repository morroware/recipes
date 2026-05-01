// public_html/assets/js/ai.js
// Floating AI quick-assist panel: a quick chat + bulk pantry add + recipe
// suggestions + recipe import. The full chat experience lives at /chat.

import { apiFetch, toast, appUrl } from './app.js';
import { getWindowContext } from './window-context.js';

let aiEnabled = null;
let panelEl = null;
const CONV_STORAGE_KEY = 'recipes:ai:fab-conversation-id';
let chatConversationId = (() => {
  try {
    const v = localStorage.getItem(CONV_STORAGE_KEY);
    return v ? parseInt(v, 10) || null : null;
  } catch { return null; }
})();
function setChatConversationId(id) {
  chatConversationId = id || null;
  try {
    if (chatConversationId) localStorage.setItem(CONV_STORAGE_KEY, String(chatConversationId));
    else localStorage.removeItem(CONV_STORAGE_KEY);
  } catch {}
}

async function ensureStatus() {
  if (aiEnabled !== null) return aiEnabled;
  try {
    const { data } = await apiFetch('/api/ai/status');
    aiEnabled = !!(data && data.enabled);
  } catch {
    aiEnabled = false;
  }
  return aiEnabled;
}

function buildPanel() {
  if (panelEl) return panelEl;

  panelEl = document.createElement('div');
  panelEl.className = 'ai-panel-overlay';
  panelEl.hidden = true;
  panelEl.innerHTML = `
    <div class="ai-panel" role="dialog" aria-label="AI assistant">
      <div class="ai-panel-header">
        <span class="ai-panel-title">✨ Kitchen brain</span>
        <button class="btn btn-sm" data-ai="close" type="button" aria-label="Close">✕</button>
      </div>
      <div class="ai-panel-tabs" role="tablist">
        <button class="ai-panel-tab active" data-tab="chat" type="button">💬 Chat</button>
        <button class="ai-panel-tab" data-tab="bulk" type="button">📥 Bulk add to pantry</button>
        <button class="ai-panel-tab" data-tab="suggest" type="button">🍳 What can I make?</button>
        <button class="ai-panel-tab" data-tab="import" type="button">📋 Import recipe</button>
      </div>
      <div class="ai-panel-body">

        <div class="ai-pane" data-pane="chat">
          <div class="muted" style="font-size:12px; display:flex; justify-content:space-between; align-items:center;">
            <span>Quick chat. I remember your preferences.</span>
            <a class="btn btn-sm" href="${appUrl('/chat')}">Open full chat ↗</a>
          </div>
          <div class="ai-chat-log" data-ai="chatlog"></div>
          <form data-ai="chat-form" style="display:flex; gap:8px; margin-top:8px;">
            <input class="search-input" name="msg" placeholder="What should I cook tonight?" autocomplete="off" required>
            <button class="btn btn-primary" type="submit">Send</button>
          </form>
        </div>

        <div class="ai-pane" data-pane="bulk" hidden>
          <div class="muted" style="font-size:12px;">
            Paste a list, fridge dump, or recipe ingredients. I'll parse and add them.
          </div>
          <textarea class="ai-textarea" data-ai="bulk-text"
            placeholder="2 yellow onions&#10;1 lb chicken thighs&#10;cumin&#10;coconut milk…"></textarea>
          <div class="row" style="gap:8px;">
            <label class="row" style="gap:6px; font-size:13px;">
              <input type="checkbox" data-ai="bulk-instock" checked> Mark in stock
            </label>
            <span style="flex:1;"></span>
            <button class="btn" type="button" data-ai="bulk-preview">Preview</button>
            <button class="btn btn-primary" type="button" data-ai="bulk-commit">Add all</button>
          </div>
          <div class="ai-output" data-ai="bulk-output"></div>
        </div>

        <div class="ai-pane" data-pane="suggest" hidden>
          <div class="muted" style="font-size:12px;">
            Recipe ideas from Claude, tailored to what you have.
          </div>
          <div class="row" style="gap:6px; flex-wrap:wrap;">
            <button class="filter-chip active" data-mode="pantry" type="button">Use my pantry</button>
            <button class="filter-chip" data-mode="weeknight" type="button">Quick weeknight</button>
            <button class="filter-chip" data-mode="new" type="button">Try something new</button>
          </div>
          <input class="search-input" data-ai="suggest-note"
                 placeholder="Optional: 'comfort food', 'dairy free', etc.">
          <button class="btn btn-primary" type="button" data-ai="suggest-go">Suggest meals</button>
          <div class="ai-output" data-ai="suggest-output"></div>
        </div>

        <div class="ai-pane" data-pane="import" hidden>
          <div class="muted" style="font-size:12px;">
            Paste any recipe (text or URL contents). I'll structure it for the editor.
          </div>
          <textarea class="ai-textarea" data-ai="import-text"
            placeholder="Paste recipe text here…"></textarea>
          <div class="row">
            <span style="flex:1;"></span>
            <button class="btn btn-primary" type="button" data-ai="import-go">Open in editor</button>
          </div>
          <div class="ai-output" data-ai="import-output"></div>
        </div>

      </div>
    </div>
  `;
  document.body.appendChild(panelEl);
  wirePanel(panelEl);
  return panelEl;
}

function wirePanel(panel) {
  panel.addEventListener('click', (e) => {
    if (e.target === panel) closePanel();
    if (e.target.closest('[data-ai="close"]')) closePanel();

    const tab = e.target.closest('.ai-panel-tab');
    if (tab) {
      panel.querySelectorAll('.ai-panel-tab').forEach(t => t.classList.toggle('active', t === tab));
      const which = tab.dataset.tab;
      panel.querySelectorAll('.ai-pane').forEach(p => {
        p.hidden = p.dataset.pane !== which;
      });
    }

    const modeBtn = e.target.closest('[data-mode]');
    if (modeBtn && modeBtn.parentElement.contains(modeBtn)) {
      modeBtn.parentElement.querySelectorAll('[data-mode]').forEach(b => b.classList.toggle('active', b === modeBtn));
    }
  });

  // Chat
  const chatForm = panel.querySelector('[data-ai="chat-form"]');
  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = chatForm.querySelector('input[name="msg"]');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    appendChat('user', text);
    const busyEl = appendChat('assistant', '…thinking');
    busyEl.classList.add('ai-busy');
    busyEl.textContent = 'thinking…';
    try {
      const { data } = await apiFetch('/api/ai/chat', {
        method: 'POST',
        body: JSON.stringify({
          conversation_id: chatConversationId,
          message: text,
          window_context: getWindowContext(),
        }),
      });
      setChatConversationId(data.conversation_id || chatConversationId);
      busyEl.classList.remove('ai-busy');
      busyEl.textContent = data.reply || '(no reply)';
      if (Array.isArray(data.actions) && data.actions.length) {
        renderActions(panelEl.querySelector('[data-ai="chatlog"]'), data.actions);
        applySideEffects(data.actions);
      }
    } catch (e) {
      busyEl.classList.remove('ai-busy');
      busyEl.classList.add('error');
      busyEl.textContent = 'Error: ' + e.message;
    }
  });

  // Bulk pantry
  panel.querySelector('[data-ai="bulk-preview"]').addEventListener('click', () => bulkSubmit(panel, false));
  panel.querySelector('[data-ai="bulk-commit"]').addEventListener('click', () => {
    // If a preview is already on screen, commit those exact items (no
    // re-parse). Otherwise, parse + commit in one shot.
    if (panel._bulkPreviewItems && panel._bulkPreviewItems.length) {
      bulkCommitParsed(panel, panel._bulkPreviewItems);
    } else {
      bulkSubmit(panel, true);
    }
  });
  // If the user edits the textarea after a preview, drop the cached items so
  // the next "Add all" re-parses the new text rather than committing stale.
  panel.querySelector('[data-ai="bulk-text"]').addEventListener('input', () => {
    panel._bulkPreviewItems = null;
  });

  // Suggest
  panel.querySelector('[data-ai="suggest-go"]').addEventListener('click', async () => {
    const out = panel.querySelector('[data-ai="suggest-output"]');
    const note = panel.querySelector('[data-ai="suggest-note"]').value.trim();
    const mode = panel.querySelector('[data-pane="suggest"] [data-mode].active')?.dataset.mode || 'pantry';
    out.innerHTML = '<span class="ai-busy">cooking up ideas…</span>';
    try {
      const { data } = await apiFetch('/api/ai/recipe-suggestions', {
        method: 'POST',
        body: JSON.stringify({ mode, note }),
      });
      const items = data.suggestions || [];
      if (!items.length) { out.textContent = 'No suggestions came back. Try a different mode.'; return; }
      out.innerHTML = '';
      for (const s of items) {
        const card = document.createElement('div');
        card.className = 'ai-suggest-card';
        const missing = (s.missing_ingredients || []).join(', ');
        card.innerHTML = `
          <div class="ai-suggest-card-title">${(s.glyph || '🍽️') + ' ' + escapeHtml(s.title || 'Untitled')}</div>
          <div class="ai-suggest-card-meta">
            ${escapeHtml(s.cuisine || '—')} · ${parseInt(s.time_minutes || 30, 10)}m · ${escapeHtml(s.difficulty || 'Easy')}
          </div>
          <div style="font-size:13px;">${escapeHtml(s.summary || '')}</div>
          ${missing ? `<div class="ai-suggest-card-meta">Need: ${escapeHtml(missing)}</div>` : ''}
          <div class="row" style="gap:6px;">
            <button class="btn btn-sm btn-mint" type="button" data-ai-add-recipe>＋ Add to my book</button>
          </div>
        `;
        card.querySelector('[data-ai-add-recipe]').addEventListener('click', async (ev) => {
          ev.target.disabled = true;
          ev.target.textContent = 'building…';
          try {
            const { data: r } = await apiFetch('/api/ai/recipe-from-idea', {
              method: 'POST',
              body: JSON.stringify({ title: s.title, note: s.summary || '' }),
            });
            const created = await apiFetch('/api/recipes', {
              method: 'POST',
              body: JSON.stringify(r.recipe),
            });
            const id = created?.data?.id || created?.data?.recipe?.id;
            toast('Recipe saved ✨');
            if (id) window.location.href = appUrl('/recipes/' + id);
          } catch {
            ev.target.disabled = false;
            ev.target.textContent = '＋ Add to my book';
          }
        });
        out.appendChild(card);
      }
    } catch (e) {
      out.textContent = 'Error: ' + e.message;
    }
  });

  // Import
  panel.querySelector('[data-ai="import-go"]').addEventListener('click', async () => {
    const text = panel.querySelector('[data-ai="import-text"]').value.trim();
    const out = panel.querySelector('[data-ai="import-output"]');
    if (!text) { out.textContent = 'Paste some recipe text first.'; return; }
    out.innerHTML = '<span class="ai-busy">parsing recipe…</span>';
    try {
      const { data } = await apiFetch('/api/ai/parse-recipe', {
        method: 'POST',
        body: JSON.stringify({ text }),
      });
      const created = await apiFetch('/api/recipes', {
        method: 'POST',
        body: JSON.stringify(data.recipe),
      });
      const id = created?.data?.id || created?.data?.recipe?.id;
      toast('Recipe imported ✨');
      if (id) window.location.href = appUrl('/recipes/' + id + '/edit');
      else out.textContent = 'Imported, but no id returned.';
    } catch (e) {
      out.textContent = 'Error: ' + e.message;
    }
  });
}

async function bulkSubmit(panel, commit) {
  const text = panel.querySelector('[data-ai="bulk-text"]').value.trim();
  const out  = panel.querySelector('[data-ai="bulk-output"]');
  const inStock = panel.querySelector('[data-ai="bulk-instock"]').checked;
  if (!text) { out.textContent = 'Paste a list first.'; return; }
  out.innerHTML = '<span class="ai-busy">parsing…</span>';
  panel._bulkPreviewItems = null;
  try {
    const { data } = await apiFetch('/api/ai/parse-ingredients', {
      method: 'POST',
      body: JSON.stringify({ text, commit, in_stock: inStock }),
    });
    const items = data.items || [];
    if (!items.length) { out.textContent = 'No ingredients found.'; return; }
    if (commit) {
      out.innerHTML = '';
      out.appendChild(renderBulkResult(data.added || [], items));
      if ((data.added || []).length > 0 && document.querySelector('[data-page="pantry"]')) {
        setTimeout(() => window.location.reload(), 600);
      }
    } else {
      // Stash the parsed items so the Add button commits this exact list
      // (no second AI parse, no model drift).
      panel._bulkPreviewItems = items.map(i => ({
        name: i.name, qty: i.qty ?? null, unit: i.unit || '',
        category: i.category, in_stock: inStock,
      }));
      out.innerHTML = '';
      out.appendChild(renderBulkPreview(panel, panel._bulkPreviewItems));
    }
  } catch (e) {
    out.textContent = 'Error: ' + e.message;
  }
}

function renderBulkPreview(panel, items) {
  const wrap = document.createElement('div');
  wrap.className = 'ai-bulk-preview';
  const head = document.createElement('div');
  head.className = 'ai-bulk-preview-head';
  head.innerHTML = `<strong>Preview (${items.length})</strong> · click ✕ to drop, then "Add all" commits the rest.`;
  wrap.appendChild(head);
  const ul = document.createElement('ul');
  ul.className = 'ai-bulk-preview-list';
  items.forEach((it, idx) => {
    const li = document.createElement('li');
    li.className = 'ai-bulk-preview-item';
    li.dataset.idx = String(idx);
    const qty = (it.qty != null ? String(it.qty) : '').trim();
    const unit = (it.unit || '').trim();
    const meta = [qty, unit].filter(Boolean).join(' ');
    li.innerHTML = `
      <span class="ai-bulk-preview-cat" title="${escapeHtml(it.category || 'Other')}"></span>
      <span class="ai-bulk-preview-name"></span>
      ${meta ? `<span class="ai-bulk-preview-qty"></span>` : ''}
      <button type="button" class="icon-btn" data-ai-bulk-drop aria-label="Drop">✕</button>
    `;
    li.querySelector('.ai-bulk-preview-cat').textContent = it.category || 'Other';
    li.querySelector('.ai-bulk-preview-name').textContent = it.name;
    if (meta) li.querySelector('.ai-bulk-preview-qty').textContent = meta;
    li.querySelector('[data-ai-bulk-drop]').addEventListener('click', () => {
      const i = parseInt(li.dataset.idx, 10);
      panel._bulkPreviewItems.splice(i, 1);
      // Rerender so indexes stay sane.
      const out = panel.querySelector('[data-ai="bulk-output"]');
      out.innerHTML = '';
      if (panel._bulkPreviewItems.length) {
        out.appendChild(renderBulkPreview(panel, panel._bulkPreviewItems));
      } else {
        out.textContent = 'Preview is empty.';
      }
    });
    ul.appendChild(li);
  });
  wrap.appendChild(ul);
  return wrap;
}

function renderBulkResult(added, items) {
  const wrap = document.createElement('div');
  wrap.className = 'ai-bulk-result';
  const head = document.createElement('div');
  head.className = 'ai-bulk-preview-head';
  head.innerHTML = `<strong>Added ${added.length} of ${items.length}</strong> 🥕`;
  wrap.appendChild(head);
  const ul = document.createElement('ul');
  ul.className = 'ai-bulk-preview-list';
  for (const it of items) {
    const li = document.createElement('li');
    li.className = 'ai-bulk-preview-item';
    const qty = (it.qty != null ? String(it.qty) : '').trim();
    const unit = (it.unit || '').trim();
    const meta = [qty, unit].filter(Boolean).join(' ');
    li.innerHTML = `
      <span class="ai-bulk-preview-cat"></span>
      <span class="ai-bulk-preview-name"></span>
      ${meta ? '<span class="ai-bulk-preview-qty"></span>' : ''}
    `;
    li.querySelector('.ai-bulk-preview-cat').textContent = it.category || 'Other';
    li.querySelector('.ai-bulk-preview-name').textContent = it.name;
    if (meta) li.querySelector('.ai-bulk-preview-qty').textContent = meta;
    ul.appendChild(li);
  }
  wrap.appendChild(ul);
  return wrap;
}

async function bulkCommitParsed(panel, items) {
  const out = panel.querySelector('[data-ai="bulk-output"]');
  if (!items.length) { out.textContent = 'Nothing to add.'; return; }
  out.innerHTML = '<span class="ai-busy">adding…</span>';
  const added = [];
  const failed = [];
  // Fire writes sequentially so cPanel + MySQL don't see a thundering herd.
  for (const it of items) {
    try {
      const { data } = await apiFetch('/api/pantry', {
        method: 'POST',
        body: JSON.stringify({
          name: it.name,
          qty: it.qty,
          unit: it.unit,
          category: it.category,
          in_stock: !!it.in_stock,
        }),
      });
      added.push(data?.item || { name: it.name });
    } catch (e) {
      failed.push({ name: it.name, error: e?.message || 'failed' });
    }
  }
  out.innerHTML = '';
  out.appendChild(renderBulkResult(added, items));
  panel._bulkPreviewItems = null;
  if (added.length > 0 && document.querySelector('[data-page="pantry"]')) {
    setTimeout(() => window.location.reload(), 600);
  }
  if (failed.length) {
    toast(`Couldn't add ${failed.length} item${failed.length === 1 ? '' : 's'}`, 'error');
  } else if (added.length) {
    toast(`🥕 Added ${added.length} to pantry`);
  }
}

function renderActions(log, actions) {
  if (!log || !actions || !actions.length) return;
  const ul = document.createElement('ul');
  ul.className = 'ai-chat-actions-list';
  for (const a of actions) {
    const li = document.createElement('li');
    const ok = a?.result?.ok !== false;
    li.className = 'ai-chat-action ' + (ok ? 'ok' : 'err');
    li.textContent = describeAction(a);

    if (ok && a?.result?.undo_token) {
      const undoBtn = document.createElement('button');
      undoBtn.type = 'button';
      undoBtn.className = 'btn btn-sm chat-undo-btn';
      undoBtn.style.marginLeft = '8px';
      const token = String(a.result.undo_token);
      let countdown = 10;
      const tick = () => {
        if (countdown <= 0) { undoBtn.remove(); return; }
        undoBtn.textContent = `↶ Undo (${countdown}s)`;
        countdown--;
        undoBtn._timer = setTimeout(tick, 1000);
      };
      tick();
      undoBtn.addEventListener('click', async () => {
        clearTimeout(undoBtn._timer);
        undoBtn.disabled = true;
        undoBtn.textContent = '…';
        try {
          await apiFetch('/api/ai/undo', {
            method: 'POST',
            body: JSON.stringify({ token }),
          });
          undoBtn.textContent = '✓ Undone';
          toast('Undone');
        } catch {
          undoBtn.textContent = '↶ Failed';
          undoBtn.disabled = false;
        }
      });
      li.appendChild(undoBtn);
    }
    ul.appendChild(li);
  }
  log.appendChild(ul);
  log.scrollTop = log.scrollHeight;
}

function describeAction(a) {
  const i = (a && a.input) || {};
  const r = (a && a.result) || {};
  switch (a && a.tool) {
    case 'remember_preference':  return `🧠 saved: ${i.fact}`;
    case 'forget_preference':    return `🗑️ forgot #${i.id}`;
    case 'add_to_shopping_list': return `🛒 added: ${[i.qty, i.unit, i.name].filter(Boolean).join(' ')}`;
    case 'bulk_add_to_pantry': {
      if (r.preview) {
        const n = r.preview_count ?? (i.items || []).length;
        return `👀 previewed ${n} item${n === 1 ? '' : 's'} — awaiting your OK`;
      }
      const n = r.added_count ?? (i.items || []).length;
      return `🥫 stocked pantry with ${n} item${n === 1 ? '' : 's'}`;
    }
    case 'set_meal_plan_day':    return `📅 ${i.day}: recipe #${i.recipe_id}`;
    case 'save_recipe_to_book': {
      const t = (i.recipe && i.recipe.title) || (r.title || 'recipe');
      if (r.preview) return `👀 previewed recipe "${t}" — awaiting your OK`;
      return `📚 saved "${t}" to your book`;
    }
    case 'log_cooked_recipe':    return `🍽️ logged: ${i.recipe_title}`;
    // ---- Phase 2 ---------------------------------------------------------
    case 'recipe_search':        return `🔎 searched recipes (${r.count || 0})`;
    case 'recipe_get':           return `📖 loaded ${r.recipe?.title || ('#' + i.id)}`;
    case 'open_recipe':          return `↗ opening "${r.title || ('#' + i.id)}"…`;
    case 'update_recipe':        return r.preview ? '👀 previewed recipe edits' : '✏️ updated recipe';
    case 'update_recipe_ingredients': return r.preview ? '👀 previewed new ingredient list' : '🥕 replaced ingredients';
    case 'update_recipe_steps':  return r.preview ? '👀 previewed new steps' : '📝 replaced steps';
    case 'scale_recipe':         return r.committed ? `📏 scaled → ${r.to_servings} servings` : `📏 scaled preview → ${r.to_servings} servings`;
    case 'substitute_ingredient':return r.preview ? '👀 sub preview' : `🔄 ${i.from} → ${i.to || '(removed)'}`;
    case 'toggle_favorite':      return r.is_favorite ? '♥ favorited' : '♡ unfavorited';
    case 'delete_recipe':        return r.preview ? `👀 preview delete "${r.title}"` : `🗑️ deleted "${r.title}"`;
    case 'pantry_search':        return `🔎 pantry search (${r.count || 0})`;
    case 'pantry_set_in_stock':  return `${i.in_stock ? '✓' : '✗'} ${r.item?.name || ('#' + i.id)}`;
    case 'pantry_restock':       return `🥕 restocked ${r.item?.name || ('#' + i.id)}`;
    case 'pantry_remove':        return r.preview ? '👀 preview pantry remove' : '🗑️ removed from pantry';
    case 'pantry_update':        return `✏️ pantry updated: ${r.item?.name || ''}`;
    case 'shopping_check':       return `${i.checked ? '☑' : '☐'} ${r.item?.name || ('#' + i.id)}`;
    case 'shopping_clear_checked':return r.preview ? `👀 preview clear (${r.count})` : `🧹 cleared ${r.removed} checked`;
    case 'shopping_organize_by_aisle': return r.preview ? '👀 aisle preview' : `📂 organised ${r.count} by aisle`;
    case 'shopping_build_from_plan': return `🛒 +${r.added} from ${r.recipes} recipe${r.recipes === 1 ? '' : 's'}`;
    case 'shopping_remove':      return `🗑️ removed ${r.name || ('#' + i.id)}`;
    case 'plan_clear_day':       return `🧹 cleared ${i.day}`;
    case 'plan_clear_week':      return r.preview ? `👀 preview clear week` : `🧹 cleared the week`;
    case 'plan_swap_days':       return `🔁 ${i.a} ↔ ${i.b}`;
    case 'apply_week_plan':      return r.preview ? `👀 plan preview` : `📅 applied to ${r.applied_count} day${r.applied_count === 1 ? '' : 's'}`;
    case 'set_user_settings':    return `⚙️ settings: ${Object.keys(r.changed || {}).join(', ') || '(none)'}`;
    case 'navigate':             return `↗ navigating…`;
    case 'undo':                 return r.ok ? `↶ undone (${r.reversed})` : `↶ undo failed`;
    default: return `↺ ${a && a.tool}`;
  }
}

// Same map chat.js uses — kept in sync here so the floating panel reloads
// the underlying page when the assistant mutates data the user is viewing.
// Values must match literal `data-page` attributes from the view templates.
const MUTATING_TOOL_PAGES = {
  bulk_add_to_pantry:        ['pantry'],
  pantry_set_in_stock:       ['pantry'],
  pantry_restock:            ['pantry'],
  pantry_remove:             ['pantry'],
  pantry_update:             ['pantry'],
  add_to_shopping_list:      ['shopping'],
  shopping_check:            ['shopping'],
  shopping_clear_checked:    ['shopping'],
  shopping_organize_by_aisle:['shopping'],
  shopping_build_from_plan:  ['shopping'],
  shopping_remove:           ['shopping'],
  set_meal_plan_day:         ['plan'],
  plan_clear_day:            ['plan'],
  plan_clear_week:           ['plan'],
  plan_swap_days:            ['plan'],
  apply_week_plan:           ['plan'],
  update_recipe:             ['recipes-show', 'add'],
  update_recipe_ingredients: ['recipes-show', 'add'],
  update_recipe_steps:       ['recipes-show', 'add'],
  scale_recipe:              ['recipes-show'],
  substitute_ingredient:     ['recipes-show', 'add'],
  toggle_favorite:           ['recipes-show', 'recipes-index', 'favorites'],
  delete_recipe:             ['recipes-show', 'recipes-index', 'favorites'],
};

function toolMutatesCurrentPage(action) {
  const r = action && action.result;
  if (!r || r.ok === false) return false;
  if (!r.committed && !r.undo_token) return false;
  const pages = MUTATING_TOOL_PAGES[action && action.tool];
  if (!pages) return false;
  const current = document.querySelector('[data-page]')?.getAttribute('data-page') || '';
  return pages.includes(current);
}

function applySideEffects(actions) {
  if (!actions || !actions.length) return;
  let navTarget = null;
  let needsReload = false;
  for (const a of actions) {
    const r = a && a.result;
    if (!r || r.ok === false) continue;
    if (r.navigate_to && !navTarget) navTarget = r.navigate_to;
    if (r.reload) needsReload = true;
    if (toolMutatesCurrentPage(a)) needsReload = true;
  }
  if (navTarget) {
    setTimeout(() => { window.location.href = navTarget; }, 900);
  } else if (needsReload) {
    setTimeout(() => { window.location.reload(); }, 900);
  }
}

function appendChat(role, text) {
  const log = panelEl.querySelector('[data-ai="chatlog"]');
  const m = document.createElement('div');
  m.className = 'ai-chat-msg ' + role;
  m.textContent = text;
  log.appendChild(m);
  log.scrollTop = log.scrollHeight;
  return m;
}

function escapeHtml(s) {
  return String(s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export async function openPanel(initialTab = 'chat') {
  const enabled = await ensureStatus();
  if (!enabled) {
    toast('AI is not configured. Set anthropic_api_key in config.php.', 'error');
    return;
  }
  const panel = buildPanel();
  panel.querySelectorAll('.ai-panel-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === initialTab);
  });
  panel.querySelectorAll('.ai-pane').forEach(p => {
    p.hidden = p.dataset.pane !== initialTab;
  });
  panel.hidden = false;
  if (initialTab === 'chat') hydrateChatLog().catch(() => {});
}

let chatHydrated = false;
async function hydrateChatLog() {
  if (chatHydrated || !chatConversationId || !panelEl) return;
  const log = panelEl.querySelector('[data-ai="chatlog"]');
  if (!log || log.children.length) { chatHydrated = true; return; }
  try {
    const { data } = await apiFetch('/api/ai/conversations/' + chatConversationId);
    const msgs = (data && data.messages) || [];
    for (const m of msgs.slice(-12)) {
      if (m.role === 'user' || m.role === 'assistant') {
        appendChat(m.role, m.content);
      }
    }
    chatHydrated = true;
  } catch {
    // Stale id — clear it so a new conversation starts fresh.
    setChatConversationId(null);
    chatHydrated = true;
  }
}

function closePanel() {
  if (panelEl) panelEl.hidden = true;
}

// ----- FAB + drawer wiring -------------------------------------------------

function injectFab() {
  if (document.querySelector('.ai-fab')) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'ai-fab no-print';
  btn.setAttribute('aria-label', 'Open AI assistant');
  btn.dataset.js = 'ai-fab';
  btn.textContent = '✨';
  document.body.appendChild(btn);
  btn.addEventListener('click', () => openPanel('chat'));
}

function wireDrawer() {
  const drawer = document.querySelector('[data-js="drawer"]');
  const panel = drawer ? drawer.querySelector('.mobile-drawer-panel') : null;
  const open  = document.querySelector('[data-js="drawer-open"]');
  const close = document.querySelector('[data-js="drawer-close"]');
  if (!drawer || !panel || !open) return;

  const focusableSelector = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';
  let lastFocused = null;

  const set = (v) => {
    drawer.dataset.open = v ? 'true' : 'false';
    drawer.setAttribute('aria-hidden', v ? 'false' : 'true');
    open.setAttribute('aria-expanded', v ? 'true' : 'false');
    document.body.classList.toggle('drawer-open', v);

    if (v) {
      lastFocused = document.activeElement;
      const first = panel.querySelector(focusableSelector);
      if (first) first.focus();
      return;
    }
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  };

  open.addEventListener('click', () => set(true));
  if (close) close.addEventListener('click', () => set(false));

  drawer.addEventListener('click', (e) => { if (e.target === drawer) set(false); });
  panel.addEventListener('click', (e) => {
    if (e.target.closest('a[href]')) set(false);
  });

  document.addEventListener('keydown', (e) => {
    if (drawer.dataset.open !== 'true') return;
    if (e.key === 'Escape') {
      e.preventDefault();
      set(false);
      return;
    }
    if (e.key !== 'Tab') return;

    const nodes = Array.from(panel.querySelectorAll(focusableSelector));
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    const active = document.activeElement;

    if (e.shiftKey && active === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  });
}

function wireAiTriggers() {
  document.querySelectorAll('[data-js="ai-fab-trigger"]').forEach(el => {
    el.addEventListener('click', () => openPanel('chat'));
  });
  document.querySelectorAll('[data-ai-open]').forEach(el => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      openPanel(el.dataset.aiOpen || 'chat');
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => { injectFab(); wireDrawer(); wireAiTriggers(); });
} else {
  injectFab(); wireDrawer(); wireAiTriggers();
}
