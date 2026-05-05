// public_html/assets/js/app.js
// Shared utilities: API fetch wrapper with CSRF, toast, favorite handler.


const appBasePath = () => {
  const m = document.querySelector('meta[name="app-base-path"]');
  return (m ? m.getAttribute('content') : '') || '';
};

export function appUrl(path = '/') {
  const p = String(path || '/');
  if (!p.startsWith('/')) return p;
  return `${appBasePath()}${p}`;
}

const csrfToken = () => {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.getAttribute('content') : '';
};

export async function apiFetch(url, opts = {}) {
  const headers = new Headers(opts.headers || {});
  const method = (opts.method || 'GET').toUpperCase();
  if (method !== 'GET' && method !== 'HEAD') {
    headers.set('X-CSRF-Token', csrfToken());
  }
  if (opts.body && !headers.has('Content-Type') && typeof opts.body === 'string') {
    headers.set('Content-Type', 'application/json');
  }
  let res;
  try {
    res = await fetch(appUrl(url), { ...opts, method, headers, credentials: 'same-origin' });
  } catch (e) {
    toast('Network error. Try again.', 'error');
    throw e;
  }
  let body = null;
  try { body = await res.json(); } catch { /* not JSON / empty 204 */ }
  if (!res.ok || (body && body.ok === false)) {
    const msg = (body && body.error) ? body.error : `Request failed (${res.status})`;
    toast(msg, 'error');
    throw new Error(msg);
  }
  // Always return an object so `const { data } = await apiFetch(...)` doesn't
  // explode on empty/204 responses.
  return body || { ok: true, data: null };
}

let toastEl = null;
export function toast(message, kind = 'info') {
  if (!toastEl) {
    toastEl = document.createElement('div');
    toastEl.className = 'shop-toast';
    Object.assign(toastEl.style, {
      position: 'fixed',
      left: '50%',
      bottom: '32px',
      transform: 'translateX(-50%)',
      zIndex: '10000',
      pointerEvents: 'none',
      opacity: '0',
      transition: 'opacity 180ms ease',
    });
    document.body.appendChild(toastEl);
  }
  toastEl.textContent = message;
  toastEl.dataset.kind = kind;
  toastEl.style.background = (kind === 'error') ? 'var(--coral, #FFB3B3)' : 'var(--mint)';
  // Errors are interruptive; everything else is polite.
  toastEl.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  toastEl.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
  // re-trigger entrance animation
  toastEl.style.animation = 'none';
  void toastEl.offsetWidth;
  toastEl.style.animation = '';
  toastEl.style.opacity = '1';
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => { toastEl.style.opacity = '0'; }, 2400);
}

// ------------- Favorite toggle (works on cards + detail buttons) -------------

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-action="toggle-favorite"]');
  if (!btn) return;
  e.preventDefault();
  e.stopPropagation();

  const recipeId = btn.dataset.recipeId;
  if (!recipeId) return;
  btn.disabled = true;
  try {
    const { data } = await apiFetch(`/api/recipes/${recipeId}/favorite`, { method: 'POST' });
    const fav = !!(data && data.is_favorite);
    btn.classList.toggle('active', fav);
    btn.setAttribute('aria-pressed', fav ? 'true' : 'false');
    if (btn.classList.contains('recipe-card-fav')) {
      btn.textContent = fav ? '♥' : '♡';
      // On the favorites page, remove cards as they're un-favorited so the
      // grid stays consistent with what the page is showing.
      if (!fav && document.querySelector('[data-page="favorites"]')) {
        const card = btn.closest('.recipe-card');
        if (card) {
          card.style.transition = 'opacity 200ms';
          card.style.opacity = '0';
          setTimeout(() => card.remove(), 220);
        }
      }
    } else {
      btn.classList.toggle('btn-coral', fav);
      btn.textContent = fav ? '♥ Saved' : '♡ Save';
    }
    toast(fav ? '♥ Saved' : 'Removed from favorites');
  } catch {
    /* toast already emitted */
  } finally {
    btn.disabled = false;
  }
});
