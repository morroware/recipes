# AI Assistant ("Sprout") Enhancement Plan

A phased, checkable plan to evolve the in-app AI assistant from a useful sidekick
into a polished, fully site-aware co-pilot that can operate every surface of the
Personal Recipe Book app.

**Working branch:** `claude/enhance-aiu-assistant-gKreq`
**Target stack (unchanged):** vanilla PHP 8.1+ · MySQL · plain ES modules ·
Anthropic Messages API (Claude Sonnet 4.6) with prompt caching.

---

## Guiding principles

1. **Server is the source of truth.** Every model action goes through a tool;
   the model never directly mutates state.
2. **Preview-then-commit** for every destructive write (already used for
   `bulk_add_to_pantry` and `save_recipe_to_book` — extend to all new
   write-tools).
3. **Context is structured, not free-text.** Pages publish a JSON context
   object; the server normalises and trims it before sending to the model.
4. **Cache aggressively.** Keep the system prompt + slow-changing context in
   the cached block so per-turn cost stays low.
5. **Fail loudly to the user, gracefully to the API.** Retries with backoff on
   transport errors; clear toast messages on tool failures.
6. **No new build pipeline.** Stay vanilla — no bundlers, no frameworks.
7. **Backwards compatible.** Existing data, routes, and UI keep working through
   each phase.

---

## How to use this document

- Each phase is independently shippable.
- Each task has a checkbox. Tick `[x]` when merged to `main`.
- Acceptance criteria describe "done" in user-visible terms.
- "Files touched" is a starting hint, not a hard list.

---

## Phase 0 — Baseline & guardrails (prep work)

Small, low-risk changes that make every later phase safer and easier to debug.

- [ ] **Add retry + backoff to `ai_call`**
  Retries on HTTP 429 / 529 / transport errors with exponential backoff
  (250ms → 500ms → 1s, max 3 retries). Configurable via constant.
  Files: `src/lib/ai.php`.
- [ ] **Wrap user-pasted blobs in `<untrusted_input>` tags** for parse endpoints
  (`apiParseIngredients`, `apiParseRecipe`) and chat messages that contain
  large pastes. Update the system prompt to treat that block as data, not
  instructions.
  Files: `src/lib/ai.php`, `src/controllers/AiController.php`.
- [ ] **Add `ai_tool_audit` table + insert on every tool call**
  Columns: `id, user_id, conversation_id, tool, input_json, result_json,
  ok, created_at`. Add migration `002_ai_tool_audit.sql`.
  Files: `db/migrations/002_ai_tool_audit.sql`, `db/schema.sql`,
  `src/controllers/AiController.php::executeTool`.
- [ ] **Per-user daily token cap** (soft limit; configurable in `config.php`).
  Counts `tokens_in + tokens_out` from `ai_messages` for the day. Returns
  a friendly error when exceeded.
  Files: `src/lib/ai.php`, `src/controllers/AiController.php`.
- [ ] **Surface usage in the UI footer**
  Tiny chip on `/chat` showing today's input/output token totals (cost
  estimate optional). Pulls from `/api/ai/status`.
  Files: `src/views/chat/page.php`, `assets/js/chat.js`,
  `src/controllers/AiController.php`.

**Acceptance:** retry storm survives a forced 529; abusive paste does not
override system instructions; every tool call appears in `ai_tool_audit`;
hitting the daily cap shows a clear toast.

---

## Phase 1 — Full context awareness

Make the assistant know *exactly* where the user is and what they're looking at.

### 1.1 Per-page context payload

- [ ] **New JS module `assets/js/window-context.js`**
  Exports `getWindowContext()` that returns a normalised object:
  ```js
  {
    page: 'recipes-show',            // or 'pantry', 'plan', 'shopping', etc.
    recipe_id: 123,                  // when applicable
    selection_text: '…',             // current text selection if non-empty
    filters: { search: '…', cuisine: '…', tag: '…' },
    visible_ids: [12, 17, 22],       // up to 20 currently-visible recipe ids
  }
  ```
  Each page-specific module (`browse.js`, `pantry.js`, `plan.js`, `shopping.js`,
  `detail.js`, `add.js`) calls a `setWindowContext({...})` helper to publish
  its slice.
- [ ] **Both chat surfaces send `window_context` on every request**
  Replace the existing `page` string with the full object on
  `/api/ai/chat`.
  Files: `assets/js/chat.js`, `assets/js/ai.js`.
- [ ] **Server hydrates context server-side**
  In `apiChat`, take the trusted IDs from the payload, *re-fetch* the actual
  records (recipe body, current shopping list, current plan) from the DB,
  and append a `# Current view` block to the system prompt.
  Files: `src/controllers/AiController.php`, `src/lib/ai.php`.

**Acceptance:** From `/recipes/42` say "halve the salt in this" — the model
sees the actual recipe body and proposes the edit. From `/shopping` say
"organise this list by aisle" — it works without naming items.

### 1.2 Recipe RAG tool

- [ ] **New tool `recipe_search(query, filters)`**
  Server runs FULLTEXT (or LIKE fallback) across `recipes.title`,
  `recipes.summary`, `recipes.notes`, `ingredients.name`, `steps.text`.
  Returns up to 8 hits with full bodies (ingredients + steps).
  Files: `src/lib/ai.php` (tool def), `src/models/Recipe.php` (search
  method), `src/controllers/AiController.php` (handler).
- [ ] **New tool `recipe_get(id)`**
  Returns one full recipe by id.
- [ ] **Add a FULLTEXT index migration** if one doesn't already exist.
  Files: `db/migrations/003_recipe_fulltext.sql`.
- [ ] **Trim `ai_kitchen_context` to titles only** now that the model has
  a tool to fetch on demand. Reduces baseline token cost.

**Acceptance:** The 40-recipe-title cap stops mattering; user can ask about a
specific saved recipe by name and the model retrieves it.

### 1.3 Memory weighting

- [ ] **Use `weight` + `pinned` in `ai_profile_context`**
  Order memories DESC by `(pinned, weight)`. When truncating, drop
  low-weight unpinned ones first.
- [ ] **Soft decay** for memories not reinforced in 180 days (excluding
  allergy/diet which never decay): downweight by 1 every 90 days, capped at 2.
  Implemented as a read-time recompute, not a write — no cron needed.
  Files: `src/models/Memory.php`, `src/lib/ai.php`.

**Acceptance:** Strong allergy memories always survive truncation; stale
"likes" fade unless the user re-mentions them.

### 1.4 Streaming responses (SSE)

- [ ] **Stream `/api/ai/chat` over SSE**
  Switch `ai_call` to support `stream: true`; relay deltas to the browser.
  Tool-use loop still works — emit `event: tool` and `event: text` frames.
  Files: `src/lib/ai.php`, `src/controllers/AiController.php`.
- [ ] **Client renders incremental text**
  Update both `chat.js` and `ai.js` to consume SSE; keep the existing
  fallback for non-SSE clients.
- [ ] **Cancellable** — user can abort an in-flight reply.

**Acceptance:** Replies stream within ~500ms. Long answers feel smooth.
Aborting stops the request.

**Phase 1 done when:** an end-to-end demo on each main page produces
contextual answers with streaming and the model uses `recipe_search` rather
than guessing from the title list.

---

## Phase 2 — Expand the tool surface

Give the model a tool for every meaningful action on every page.

> All write-tools follow the **preview-then-commit** pattern: first call
> with `confirm=false` returns a structured preview; second call with
> `confirm=true` (and identical payload) writes.

### 2.1 Recipes

- [ ] `open_recipe(id)` → returns `{navigate_to: '/recipes/:id'}`; client
  auto-navigates after the assistant reply settles.
- [ ] `update_recipe(id, patch)` (preview/commit) — title, summary,
  cuisine, time, servings, difficulty, glyph, color, tags, notes.
- [ ] `update_recipe_ingredients(id, ingredients[])` (preview/commit)
- [ ] `update_recipe_steps(id, steps[])` (preview/commit)
- [ ] `scale_recipe(id, target_servings)` — server computes scaled
  ingredients; preview shows old → new; commit updates or returns a
  scaled copy without saving (user choice).
- [ ] `substitute_ingredient(id, from, to, reason)` — applies a swap
  with allergy-aware validation.
- [ ] `toggle_favorite(id)`
- [ ] `delete_recipe(id)` (preview/commit, requires explicit confirm
  string in the user message)

### 2.2 Pantry

- [ ] `pantry_search(query)`
- [ ] `pantry_set_in_stock(id, in_stock: bool)`
- [ ] `pantry_restock(id)`
- [ ] `pantry_remove(id)` (preview/commit)
- [ ] `pantry_update(id, patch)` — qty, unit, category

### 2.3 Shopping

- [ ] `shopping_check(id, checked: bool)`
- [ ] `shopping_clear_checked()`
- [ ] `shopping_organize_by_aisle()` — model-driven categorisation
- [ ] `shopping_build_from_plan()` — wraps existing
  `PlanController::apiBuildShopping`
- [ ] `shopping_remove(id)`

### 2.4 Plan

- [ ] `plan_clear_day(day)`
- [ ] `plan_clear_week()`
- [ ] `plan_swap_days(a, b)`
- [ ] `apply_week_plan({Mon: recipe_id|recipe_idea, …})` (preview/commit) —
  one call to fill a whole week (extends `apiPlanWeek`).

### 2.5 Settings & navigation

- [ ] `set_user_settings(patch)` — theme, mode, density, font_pair,
  radius, units, etc. (validated against the enum set).
- [ ] `navigate(route)` — whitelisted routes only.

### 2.6 Audit + undo

- [ ] **Every commit returns an `undo_token`** stored in `ai_tool_audit`.
- [ ] **New `undo(token)` tool** — reverses the last action when feasible
  (favorite toggle, pantry stock, shopping check, plan day, etc.).
- [ ] **Toast UI** in chat shows a 10-second "Undo" button.

**Acceptance:** "Switch me to dark mode and metric, then build a shopping
list from this week's plan, then check off the eggs" — all via chat, with
live UI updates and an undo path.

---

## Phase 3 — Multi-modal input

Unlock the model's vision and break out of the paste-text bottleneck.

- [ ] **Image input on `/chat`**
  Drag-drop / paste / file-picker for images. Encode as base64 image
  blocks for the Messages API. Cap total payload (e.g. 4 images, 5MB
  each).
  Files: `src/views/chat/page.php`, `assets/js/chat.js`,
  `src/controllers/AiController.php`, `src/lib/ai.php`.
- [ ] **Image input on the floating panel** (mobile camera capture too)
- [ ] **"Stock my fridge from this photo" flow**
  Vision → list ingredients → preview → commit via `bulk_add_to_pantry`.
- [ ] **"Import recipe from photo"** — handwritten cards, screenshots,
  cookbook pages → preview → save via `save_recipe_to_book`.
- [ ] **URL importer tool**
  `import_recipe_from_url(url)` — server-side fetch (HEAD first, size
  cap, allowlist content types), strip to readable text, hand to the
  model for parsing → preview → save.
  Files: `src/lib/ai.php`, `src/controllers/AiController.php`.
- [ ] **Voice input** via Web Speech API on chat surfaces (progressive
  enhancement; falls back to text input).

**Acceptance:** Snap a fridge photo on phone → 1 confirmation → pantry
updated. Paste a recipe URL → 1 confirmation → recipe saved.

---

## Phase 4 — UX polish

The "feels professional" pass.

- [ ] **Global ⌘K / Ctrl+K launcher**
  Opens chat with current page context pre-loaded. Slash-commands:
  `/plan`, `/scale 6`, `/sub no dairy`, `/pantry photo`, `/import url`.
  Files: new `assets/js/launcher.js`, `assets/css/styles.css`.
- [ ] **Markdown + code rendering in the floating panel**
  Reuse `chat.js`'s `renderMarkdown`. Extract to a shared module.
- [ ] **Citations card component** for `web_search` results
  Title, source domain, time-to-cook, "Save to book" CTA, deduped by URL.
- [ ] **Per-page proactive nudges**
  Server endpoint `/api/ai/nudges?page=…` returns 0–1 suggestions:
  - `/shopping` empty + plan exists → "Build from plan?"
  - pantry has > N out-of-stock → "Move them to shopping?"
  - plan has empty days → "Want me to fill them?"
  Single click triggers the matching tool.
- [ ] **Per-recipe AI sidebar on `/recipes/:id`**
  Sticky panel: "Ask about this recipe" with the recipe body pre-loaded.
  Quick actions: scale, sub, double, halve, log cook.
- [ ] **Auto memory extraction**
  Run silently after every assistant turn ≥ 3 user messages long.
  Surface a non-blocking "Learned 2 new things" pill that links to memory list.
- [ ] **Auto-rename conversations** after the first exchange
  Use a tiny model call (or local heuristic) to produce a 4-6 word title.
- [ ] **"Why this suggestion?"** affordance on every suggestion card —
  shows the model's `reason` plus contributing memories/cooking-log entries.
- [ ] **Cost / usage chip** in chat footer (today's tokens, optional ¢
  estimate).
- [ ] **Adaptive starter chips** per page (already partially built —
  finish it and drive from server).
- [ ] **Cross-device chat sync**
  Replace `localStorage` conversation id in the floating panel with a
  per-user "active conversation" stored on the server.

**Acceptance:** A new user lands on the home page, hits `⌘K`, says "what
should I cook tonight?", and gets a streamed answer with citations,
context-aware suggestions, and a one-click action — all without ever
opening `/chat`.

---

## Phase 5 — Cooking-time experiences

Use the assistant in the moments it matters most.

- [ ] **"Cook mode" page (`/recipes/:id/cook`)**
  Full-screen, step-by-step, large type, dark-friendly. Per-step:
  ingredients used in this step highlighted; tap to start a timer
  parsed from the step text ("simmer 12 minutes" → "Start 12:00 timer").
  Side chat for "I burned the garlic, now what?" with the recipe in
  context.
- [ ] **Timers UI** with browser notifications + audio cue.
- [ ] **Voice control in cook mode** ("next", "back", "set 5 minute timer",
  "what's the next ingredient?").
- [ ] **"I cooked extra" flow**
  After logging a cook, optional portion note → assistant proposes a
  next-day remix and offers to add it to the plan / shopping list.

**Acceptance:** A user can cook a recipe entirely hands-free from
`/recipes/:id/cook` and ask the assistant questions mid-cook.

---

## Phase 6 — Differentiators ("wow" features)

- [ ] **Nutrition + macro estimation tool** (`estimate_nutrition(recipe_id)`)
  Cache results in a new `recipe_nutrition` table. Surface on detail page.
- [ ] **Calendar / iCal export of meal plan**
  Tool: `export_plan_ical()`. Returns a downloadable file or URL.
- [ ] **Seasonality + locale awareness**
  Pass current month + (opt-in) zip → suggestions favour in-season produce.
- [ ] **Multi-household mode**
  Multiple eaters per account; per-eater memories. Assistant disambiguates
  ("Alex is veg, Sam isn't; here's a meal that works for both").
- [ ] **Grocery price hints** via `web_search` ("milk is on sale at your
  usual store this week").
- [ ] **AI-generated recipe glyph + alt text** on save.
- [ ] **Public share link for a recipe** (read-only) with assistant
  redaction of personal notes.

These are stretch goals — pull from this list as time/interest allows.

---

## Cross-phase quality bar

Every phase should land with:

- [ ] **Validation tools updated**: `tools/db_validate.php`,
  `tools/perf_check.php`, `tools/smoke.php` cover any new schema or routes.
- [ ] **README + `public_html/README.md` updated** with new features and
  any required migrations.
- [ ] **No new uncaught errors** in PHP error log under happy path + at
  least one chaos test (force a 529, paste a 100KB blob, drop a 6MB image).
- [ ] **Mobile parity**: every new surface works on the bottom-tab layout
  under 720px.
- [ ] **Accessibility**: focus states, `aria-` labels, keyboard reachable.
- [ ] **CSRF on every new write endpoint**.
- [ ] **Migration files are additive** and idempotent
  (`CREATE TABLE IF NOT EXISTS …`, `ADD COLUMN IF NOT EXISTS …` patterns
  where MySQL version allows).

---

## Tracking

Update this document as each task lands. PR commits should reference the
phase + task (e.g. `Phase 1.2: add recipe_search tool`).

When a whole phase is done, move it under a `## Completed phases` section
at the bottom and tag the commit `aiu-phase-N-done`.

---

## Open questions to revisit

- Do we want a separate "admin" / "owner" gate for destructive tools
  (`delete_recipe`, `pantry_remove`) when multi-user lands?
- Should we add a "draft" state to `recipes` so the model can stage
  edits without immediately overwriting the user's content?
- How aggressive should auto-extracted memories be? (Currently manual —
  Phase 4 makes it automatic. Need an undo path.)
- Do we surface model + cost choice to the user (e.g. fall back to Haiku
  for cheap tools), or hard-code Sonnet 4.6 everywhere?

---

_Last updated: 2026-04-30. Owner: Sprout enhancement track
(`claude/enhance-aiu-assistant-gKreq`)._
