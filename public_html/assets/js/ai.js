// public_html/assets/js/ai.js
// Floating AI quick-assist panel: a quick chat + bulk pantry add + recipe
// suggestions + recipe import. The full chat experience lives at /chat.

import { apiFetch, toast, appUrl } from './app.js';

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

function pageContext() {
  const el = document.querySelector('[data-page]');
  return el ? el.getAttribute('data-page') : '';
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
          page: pageContext(),
        }),
      });
      setChatConversationId(data.conversation_id || chatConversationId);
      busyEl.classList.remove('ai-busy');
      busyEl.textContent = data.reply || '(no reply)';
      if (Array.isArray(data.actions) && data.actions.length) {
        const note = document.createElement('div');
        note.className = 'ai-chat-actions';
        note.textContent = data.actions.map(a => describeAction(a)).join(' · ');
        const log = panelEl.querySelector('[data-ai="chatlog"]');
        log.appendChild(note);
        log.scrollTop = log.scrollHeight;
      }
    } catch (e) {
      busyEl.classList.remove('ai-busy');
      busyEl.classList.add('error');
      busyEl.textContent = 'Error: ' + e.message;
    }
  });

  // Bulk pantry
  panel.querySelector('[data-ai="bulk-preview"]').addEventListener('click', () => bulkSubmit(panel, false));
  panel.querySelector('[data-ai="bulk-commit"]').addEventListener('click', () => bulkSubmit(panel, true));

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
  try {
    const { data } = await apiFetch('/api/ai/parse-ingredients', {
      method: 'POST',
      body: JSON.stringify({ text, commit, in_stock: inStock }),
    });
    const items = data.items || [];
    if (!items.length) { out.textContent = 'No ingredients found.'; return; }
    const lines = items.map(i =>
      `• ${i.qty ?? ''} ${i.unit ?? ''} ${i.name} — ${i.category}`.replace(/\s+/g, ' ').trim()
    );
    out.textContent = (commit
      ? `Added ${data.added.length} of ${items.length}:\n`
      : `Preview (${items.length}):\n`) + lines.join('\n');
    if (commit && data.added.length > 0 && document.querySelector('[data-page="pantry"]')) {
      // refresh pantry view
      setTimeout(() => window.location.reload(), 600);
    }
  } catch (e) {
    out.textContent = 'Error: ' + e.message;
  }
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
    case 'log_cooked_recipe':    return `🍽️ logged: ${i.recipe_title}`;
    default: return `↺ ${a && a.tool}`;
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
  const open  = document.querySelector('[data-js="drawer-open"]');
  const close = document.querySelector('[data-js="drawer-close"]');
  if (!drawer || !open) return;
  const set = (v) => {
    drawer.dataset.open = v ? 'true' : 'false';
    drawer.setAttribute('aria-hidden', v ? 'false' : 'true');
    open.setAttribute('aria-expanded', v ? 'true' : 'false');
  };
  open.addEventListener('click', () => set(true));
  if (close) close.addEventListener('click', () => set(false));
  drawer.addEventListener('click', (e) => { if (e.target === drawer) set(false); });
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
