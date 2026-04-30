// public_html/assets/js/chat.js
// Dedicated /chat page: persistent conversations, memory editor, tool actions.

import { apiFetch, toast, appUrl } from './app.js';
import { getWindowContext, setWindowContext } from './window-context.js';

const root = document.querySelector('[data-page="chat"]');
if (root) {
  setWindowContext({ page: 'chat' });
  init().catch(err => console.error('chat init failed', err));
}

async function init() {
  const els = {
    log:        root.querySelector('[data-chat="log"]'),
    form:       root.querySelector('[data-chat="form"]'),
    input:      root.querySelector('[data-chat="input"]'),
    convList:   root.querySelector('[data-chat="conv-list"]'),
    convTitle:  root.querySelector('[data-chat="conv-title"]'),
    statusDot:  root.querySelector('[data-chat="status-dot"]'),
    memList:    root.querySelector('[data-chat="mem-list"]'),
    memCount:   root.querySelector('[data-chat="mem-count"]'),
    memForm:    root.querySelector('[data-chat="mem-form"]'),
    newConv:    root.querySelector('[data-chat="new-conv"]'),
    extract:    root.querySelector('[data-chat="extract"]'),
    rename:     root.querySelector('[data-chat="rename"]'),
    delete:     root.querySelector('[data-chat="delete"]'),
  };

  let state = {
    conversationId: null,
    messages: [],
    sending: false,
  };

  setStatusIdle();

  // Auto-grow textarea + Enter-to-send (Shift+Enter for newline)
  els.input.addEventListener('input', () => autosize(els.input));
  els.input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      els.form.requestSubmit();
    }
  });

  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = els.input.value.trim();
    if (!message || state.sending) return;
    els.input.value = '';
    autosize(els.input);
    await sendMessage(message);
  });

  els.newConv.addEventListener('click', () => startNewConversation());

  els.convList.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-conv-id]');
    if (!btn) return;
    await loadConversation(parseInt(btn.dataset.convId, 10));
  });

  els.extract.addEventListener('click', async () => {
    if (!state.conversationId) {
      toast('Send a few messages first.', 'error');
      return;
    }
    els.extract.disabled = true;
    els.extract.textContent = 'thinking…';
    try {
      const { data } = await apiFetch('/api/ai/extract-memories', {
        method: 'POST',
        body: JSON.stringify({ conversation_id: state.conversationId }),
      });
      const n = data?.count || 0;
      toast(n ? `Saved ${n} new preference${n > 1 ? 's' : ''}.` : 'Nothing new to remember yet.');
      if (n) await refreshMemories();
    } catch {} finally {
      els.extract.disabled = false;
      els.extract.textContent = '🧠 Save preferences';
    }
  });

  els.rename.addEventListener('click', async () => {
    if (!state.conversationId) return;
    const next = prompt('Rename conversation:', els.convTitle.textContent || '');
    if (!next) return;
    try {
      await apiFetch(`/api/ai/conversations/${state.conversationId}`, {
        method: 'PATCH',
        body: JSON.stringify({ title: next }),
      });
      els.convTitle.textContent = next;
      await refreshConversations();
    } catch {}
  });

  els.delete.addEventListener('click', async () => {
    if (!state.conversationId) return;
    if (!confirm('Delete this conversation? Memories are not affected.')) return;
    try {
      await apiFetch(`/api/ai/conversations/${state.conversationId}`, { method: 'DELETE' });
      state.conversationId = null;
      els.log.innerHTML = '';
      els.convTitle.textContent = 'New conversation';
      renderEmpty();
      await refreshConversations();
    } catch {}
  });

  // Memory: add
  els.memForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(els.memForm);
    const fact = String(fd.get('fact') || '').trim();
    const category = String(fd.get('category') || 'other');
    if (!fact) return;
    try {
      const { data } = await apiFetch('/api/ai/memories', {
        method: 'POST',
        body: JSON.stringify({ fact, category, weight: 7, pinned: true }),
      });
      els.memForm.reset();
      addMemoryRow(data.memory, /*prepend*/ true);
      bumpMemCount(+1);
      toast('Saved.');
    } catch {}
  });

  // Memory: delete
  els.memList.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-chat="mem-del"]');
    if (!btn) return;
    const li = btn.closest('[data-mem-id]');
    const id = li?.dataset?.memId;
    if (!id) return;
    try {
      await apiFetch(`/api/ai/memories/${id}`, { method: 'DELETE' });
      li.remove();
      bumpMemCount(-1);
    } catch {}
  });

  // Suggestion chips
  root.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-chat-prompt]');
    if (!chip) return;
    els.input.value = chip.dataset.chatPrompt;
    autosize(els.input);
    els.input.focus();
  });

  // ---- functions ----------------------------------------------------------

  function autosize(t) {
    t.style.height = 'auto';
    t.style.height = Math.min(t.scrollHeight, 240) + 'px';
  }

  function setStatusIdle()    { els.statusDot.dataset.state = 'idle'; }
  function setStatusBusy()    { els.statusDot.dataset.state = 'busy'; }
  function setStatusError()   { els.statusDot.dataset.state = 'error'; }

  function renderEmpty() {
    els.log.innerHTML = `
      <div class="chat-empty">
        <div style="font-size: 48px;">✨</div>
        <p>Start a conversation. Try one of these:</p>
        <div class="chat-suggestions">
          <button class="filter-chip" type="button" data-chat-prompt="What can I make tonight from what I have?">What can I make tonight?</button>
          <button class="filter-chip" type="button" data-chat-prompt="Look at my pantry, then search the web for 3 fresh recipe ideas that use what I have. Show me each option with its source link before saving anything.">🌐 Find new recipes online</button>
          <button class="filter-chip" type="button" data-chat-prompt="Stock my pantry from this paste:&#10;&#10;">🥫 Stock my pantry from this paste</button>
          <button class="filter-chip" type="button" data-chat-prompt="I'm vegetarian and don't like cilantro. Remember that.">I'm vegetarian (remember this)</button>
          <button class="filter-chip" type="button" data-chat-prompt="Plan a balanced 7-day meal plan for me.">Plan my week</button>
          <button class="filter-chip" type="button" data-chat-prompt="Suggest a comfort food recipe similar to the ones I've rated highest.">Comfort food I'd love</button>
        </div>
      </div>
    `;
  }

  function appendBubble(role, content, opts = {}) {
    const empty = els.log.querySelector('.chat-empty');
    if (empty) empty.remove();

    const wrap = document.createElement('div');
    wrap.className = 'chat-bubble ' + role + (opts.busy ? ' busy' : '');
    if (opts.busy) {
      wrap.innerHTML = '<span class="chat-typing"><span></span><span></span><span></span></span>';
    } else {
      wrap.innerHTML = renderMarkdown(content);
    }
    els.log.appendChild(wrap);
    els.log.scrollTop = els.log.scrollHeight;
    return wrap;
  }

  function appendActions(actions) {
    if (!actions || !actions.length) return;
    const ul = document.createElement('ul');
    ul.className = 'chat-actions';
    for (const a of actions) {
      const li = document.createElement('li');
      const ok = a?.result?.ok !== false;
      li.className = ok ? 'ok' : 'err';
      if (a?.tool === 'save_recipe_to_book' && a?.result?.committed && a?.result?.view_url) {
        const title = a.input?.recipe?.title || a.result.title || 'recipe';
        const link = document.createElement('a');
        link.href = a.result.view_url;
        link.textContent = `Open "${title}"`;
        li.append(`📚 Saved to your book — `, link);
      } else {
        li.textContent = describeAction(a);
      }
      // Inline 10-second Undo button for any reversible commit.
      if (ok && a?.result?.undo_token) {
        const undoBtn = document.createElement('button');
        undoBtn.type = 'button';
        undoBtn.className = 'btn btn-sm chat-undo-btn';
        undoBtn.textContent = '↶ Undo';
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
    els.log.appendChild(ul);
    els.log.scrollTop = els.log.scrollHeight;
  }

  // After the bubble renders, follow any navigate_to / reload hints.
  function applySideEffects(actions) {
    if (!actions || !actions.length) return;
    let navTarget = null;
    let needsReload = false;
    for (const a of actions) {
      const r = a?.result;
      if (!r || r.ok === false) continue;
      if (r.navigate_to && !navTarget) navTarget = r.navigate_to;
      if (r.reload) needsReload = true;
    }
    if (navTarget) {
      // Small delay so the user can read the assistant's reply first.
      setTimeout(() => { window.location.href = navTarget; }, 900);
    } else if (needsReload) {
      setTimeout(() => { window.location.reload(); }, 900);
    }
  }

  function describeAction(a) {
    const i = a?.input || {};
    const r = a?.result || {};
    const titleish = (id) => `#${id}`;
    switch (a.tool) {
      case 'remember_preference':   return `🧠 Remembered: ${i.fact} [${i.category || 'other'}]`;
      case 'forget_preference':     return `🗑️ Forgot memory #${i.id}`;
      case 'add_to_shopping_list':  return `🛒 Added to shopping: ${[i.qty, i.unit, i.name].filter(Boolean).join(' ')}`;
      case 'bulk_add_to_pantry': {
        const items = i.items || [];
        const sample = items.slice(0, 3).map(x => x.name).filter(Boolean).join(', ');
        const tail = sample ? ` — ${sample}${items.length > 3 ? '…' : ''}` : '';
        if (r.preview) {
          const n = r.preview_count ?? items.length;
          return `👀 Previewed ${n} pantry item${n === 1 ? '' : 's'} (waiting for your OK)${tail}`;
        }
        const n = r.added_count ?? items.length;
        return `🥫 Added ${n} item${n === 1 ? '' : 's'} to pantry${tail}`;
      }
      case 'set_meal_plan_day':     return `📅 ${i.day}: recipe #${i.recipe_id}`;
      case 'save_recipe_to_book': {
        const t = i.recipe?.title || r.title || 'recipe';
        if (r.preview) return `👀 Previewed recipe "${t}" (waiting for your OK)`;
        const link = r.view_url ? ` — open: ${r.view_url}` : '';
        return `📚 Saved "${t}" to your book${link}`;
      }
      case 'log_cooked_recipe':     return `🍽️ Logged cook: ${i.recipe_title}` + (i.rating ? ` (${'★'.repeat(i.rating)})` : '');
      // ---- Phase 2 -------------------------------------------------------
      case 'recipe_search':         return `🔎 Searched recipes: "${i.query || '(all)'}" — ${r.count ?? 0} hit${(r.count ?? 0) === 1 ? '' : 's'}`;
      case 'recipe_get':            return `📖 Loaded recipe ${titleish(i.id)}${r.recipe?.title ? ': ' + r.recipe.title : ''}`;
      case 'open_recipe':           return `↗ Opening "${r.title || titleish(i.id)}"…`;
      case 'update_recipe':         return r.preview ? `👀 Previewed edits to ${titleish(i.id)} (${Object.keys(r.diff || {}).join(', ')})` : `✏️ Updated ${titleish(i.id)}: ${(r.changed || []).join(', ')}`;
      case 'update_recipe_ingredients': return r.preview ? `👀 Previewed new ingredient list (${r.new_count})` : `🥕 Replaced ingredients (${r.count})`;
      case 'update_recipe_steps':   return r.preview ? `👀 Previewed new steps (${r.new_count})` : `📝 Replaced steps (${r.count})`;
      case 'scale_recipe':          return r.committed ? `📏 Scaled "${r.title || titleish(i.id)}" → ${r.to_servings} servings` : `📏 Scaled preview: ${r.from_servings} → ${r.to_servings} servings`;
      case 'substitute_ingredient': return r.preview ? `👀 Sub preview: ${i.from} → ${i.to || '(remove)'} on ${titleish(i.id)}` : `🔄 Subbed ${i.from} → ${i.to || '(removed)'} on ${titleish(i.id)}`;
      case 'toggle_favorite':       return r.is_favorite ? `♥ Favorited ${titleish(i.id)}` : `♡ Unfavorited ${titleish(i.id)}`;
      case 'delete_recipe':         return r.preview ? `👀 Previewed deletion of "${r.title}"` : `🗑️ Deleted "${r.title || titleish(i.id)}"`;
      case 'pantry_search':         return `🔎 Searched pantry: "${i.query}" — ${r.count ?? 0} hit${(r.count ?? 0) === 1 ? '' : 's'}`;
      case 'pantry_set_in_stock':   return `${i.in_stock ? '✓' : '✗'} ${r.item?.name || titleish(i.id)} is now ${i.in_stock ? 'in stock' : 'out of stock'}`;
      case 'pantry_restock':        return `🥕 Restocked ${r.item?.name || titleish(i.id)}`;
      case 'pantry_remove':         return r.preview ? `👀 Preview: remove ${r.item?.name || titleish(i.id)} from pantry` : `🗑️ Removed ${r.name || titleish(i.id)} from pantry`;
      case 'pantry_update':         return `✏️ Updated pantry item: ${r.item?.name || titleish(i.id)}`;
      case 'shopping_check':        return `${i.checked ? '☑' : '☐'} ${r.item?.name || titleish(i.id)}`;
      case 'shopping_clear_checked':return r.preview ? `👀 Preview: clear ${r.count} checked` : `🧹 Cleared ${r.removed} checked item${r.removed === 1 ? '' : 's'}`;
      case 'shopping_organize_by_aisle': return r.preview ? `👀 Aisle preview (${r.count} items)` : `📂 Organised ${r.count} items by aisle`;
      case 'shopping_build_from_plan': return `🛒 Built shopping list from plan: +${r.added} ingredient${r.added === 1 ? '' : 's'} from ${r.recipes} recipe${r.recipes === 1 ? '' : 's'}`;
      case 'shopping_remove':       return `🗑️ Removed ${r.name || titleish(i.id)} from shopping`;
      case 'plan_clear_day':        return `🧹 Cleared ${i.day}`;
      case 'plan_clear_week':       return r.preview ? `👀 Preview: clear ${r.count} day${r.count === 1 ? '' : 's'}` : `🧹 Cleared the week (${r.cleared} day${r.cleared === 1 ? '' : 's'})`;
      case 'plan_swap_days':        return `🔁 Swapped ${i.a} ↔ ${i.b}`;
      case 'apply_week_plan':       return r.preview ? `👀 Plan preview (${Object.keys(r.plan || {}).length} day${Object.keys(r.plan || {}).length === 1 ? '' : 's'})` : `📅 Applied plan to ${r.applied_count} day${r.applied_count === 1 ? '' : 's'}`;
      case 'set_user_settings':     return `⚙️ Updated settings: ${Object.keys(r.changed || {}).join(', ') || '(none)'}`;
      case 'navigate':              return `↗ Navigating…`;
      case 'undo':                  return r.ok ? `↶ Undone: ${r.reversed}` : `↶ Undo failed: ${r.error}`;
      default: return `↺ ${a.tool}`;
    }
  }

  async function sendMessage(message) {
    state.sending = true;
    setStatusBusy();
    appendBubble('user', message);
    const busy = appendBubble('assistant', '', { busy: true });
    try {
      const { data } = await apiFetch('/api/ai/chat', {
        method: 'POST',
        body: JSON.stringify({
          conversation_id: state.conversationId,
          message,
          window_context: getWindowContext(),
        }),
      });
      busy.remove();
      appendBubble('assistant', data.reply || '(no reply)');
      if (data.actions?.length) {
        appendActions(data.actions);
        // Some actions (memories) change the rail.
        if (data.actions.some(a => a.tool === 'remember_preference' || a.tool === 'forget_preference')) {
          refreshMemories();
        }
        applySideEffects(data.actions);
      }
      if (!state.conversationId && data.conversation_id) {
        state.conversationId = data.conversation_id;
        await refreshConversations();
      } else {
        await refreshConversations();
      }
      setStatusIdle();
    } catch (e) {
      busy.classList.remove('busy');
      busy.classList.add('error');
      busy.textContent = 'Error: ' + e.message;
      setStatusError();
    } finally {
      state.sending = false;
    }
  }

  async function startNewConversation() {
    state.conversationId = null;
    els.log.innerHTML = '';
    els.convTitle.textContent = 'New conversation';
    renderEmpty();
    els.input.focus();
    document.querySelectorAll('.chat-conv-item.active').forEach(el => el.classList.remove('active'));
  }

  async function loadConversation(id) {
    if (!id || id === state.conversationId) return;
    try {
      const { data } = await apiFetch(`/api/ai/conversations/${id}`);
      state.conversationId = id;
      els.convTitle.textContent = data.conversation?.title || 'Conversation';
      els.log.innerHTML = '';
      const msgs = data.messages || [];
      if (!msgs.length) {
        renderEmpty();
      } else {
        for (const m of msgs) {
          if (m.role === 'user' || m.role === 'assistant') {
            appendBubble(m.role, m.content);
          }
        }
      }
      document.querySelectorAll('.chat-conv-item').forEach(el => {
        el.classList.toggle('active', String(el.dataset.convId) === String(id));
      });
    } catch {}
  }

  async function refreshConversations() {
    try {
      const { data } = await apiFetch('/api/ai/conversations');
      const list = data?.conversations || [];
      els.convList.innerHTML = list.length ? '' : '<li class="muted" style="font-size:13px; padding: 6px 4px;">No chats yet — say hi!</li>';
      for (const c of list) {
        const li = document.createElement('li');
        li.innerHTML = `
          <button class="chat-conv-item${state.conversationId == c.id ? ' active' : ''}" type="button" data-conv-id="${c.id}">
            <span class="chat-conv-title"></span>
            <span class="muted mono" style="font-size:11px;"></span>
          </button>
        `;
        li.querySelector('.chat-conv-title').textContent = c.title;
        li.querySelector('.muted.mono').textContent = (c.updated_at || '').slice(0, 10);
        els.convList.appendChild(li);
      }
    } catch {}
  }

  function addMemoryRow(m, prepend = false) {
    if (!m) return;
    const li = document.createElement('li');
    li.className = 'chat-mem-item';
    li.dataset.memId = m.id;
    li.innerHTML = `
      <span class="chat-mem-cat pill"></span>
      <span class="chat-mem-fact"></span>
      <button class="icon-btn" type="button" data-chat="mem-del" aria-label="Forget">✕</button>
    `;
    li.querySelector('.chat-mem-cat').textContent = m.category;
    li.querySelector('.chat-mem-fact').textContent = m.fact;
    if (prepend) els.memList.prepend(li);
    else els.memList.appendChild(li);
  }

  function bumpMemCount(delta) {
    const n = parseInt(els.memCount.textContent || '0', 10) + delta;
    els.memCount.textContent = String(Math.max(0, n));
  }

  async function refreshMemories() {
    try {
      const { data } = await apiFetch('/api/ai/memories');
      const list = data?.memories || [];
      els.memList.innerHTML = '';
      for (const m of list) addMemoryRow(m, false);
      els.memCount.textContent = String(list.length);
    } catch {}
  }
}

// Tiny markdown: bold, italics, inline code, lists, line breaks. Escapes HTML first.
function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function renderMarkdown(text) {
  let s = escapeHtml(String(text || ''));

  // Fenced code blocks
  s = s.replace(/```([\s\S]*?)```/g, (_, code) => `<pre><code>${code.trim()}</code></pre>`);
  // Inline code
  s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
  // Bold + italic
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
  // Links [text](url)
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  // Bulleted lists (- or *)
  s = s.replace(/(^|\n)([-*] .+(?:\n[-*] .+)*)/g, (m, lead, block) => {
    const items = block.split('\n').map(l => `<li>${l.replace(/^[-*] /, '')}</li>`).join('');
    return `${lead}<ul>${items}</ul>`;
  });
  // Numbered lists
  s = s.replace(/(^|\n)(\d+\. .+(?:\n\d+\. .+)*)/g, (m, lead, block) => {
    const items = block.split('\n').map(l => `<li>${l.replace(/^\d+\. /, '')}</li>`).join('');
    return `${lead}<ol>${items}</ol>`;
  });
  // Paragraph breaks
  s = s.replace(/\n{2,}/g, '</p><p>');
  s = s.replace(/\n/g, '<br>');
  return `<p>${s}</p>`;
}
