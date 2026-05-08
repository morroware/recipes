# Personal Recipe Book

A vanilla **PHP 8.1+ / MySQL** recipe manager designed to drop into a shared
cPanel `public_html/` and run with no build step, no Composer, no Node, and no
framework. The optional AI layer is powered by Claude (Anthropic Messages API)
and gives the app a built-in cooking assistant — *Sprout* — that can take real
actions on your data through tool use.

## What the app does

- 📖 **Recipe library** — add / edit / delete recipes with structured
  ingredients, ordered steps, tags, cuisine, time, servings, difficulty,
  emoji glyph, color sticker, and free-form notes. Image uploads (JPEG / PNG /
  WebP / GIF, ≤ 10 MB) support a primary photo plus a multi-image gallery.
- 🔎 **Browse** — full-text-ish search across title / cuisine / summary plus
  cuisine, tag, time-bucket, and sort (A–Z / quickest / newest / easiest)
  filters.
- ♥ **Favorites** — one-click favorite from cards or detail page.
- 🥕 **Pantry tracking** — items with category, in-stock toggle, qty / unit,
  purchase counter, "last bought" timestamp, restock action, and an
  ingredient-categorization helper (rule-based, optionally Claude-assisted).
- 🛒 **Shopping list** — manual items plus "add from recipe" (with scale),
  per-aisle organisation, dedupe by source, "move checked to pantry", and a
  "build from this week's plan" generator.
- 📅 **Weekly meal plan** — assign one recipe per weekday, with a recipe-picker
  modal, day-clear / week-clear, and "build shopping list" in one click.
- 🖨️ **Print modes** — shopping list (grouped by aisle), single recipe card,
  recipe booklet (multiple ids), and the planned week.
- ✨ **AI assistant ("Sprout")** — full chat at `/chat`, plus a floating
  quick-assist panel on every page with bulk pantry add, recipe suggestions,
  and recipe import. (Optional — only active when an Anthropic API key is
  configured.)
- 🎨 **Tweaks panel** — live theme / mode / density / radius / font-pair /
  card-style / units toggles. Persisted per user.

## Tech stack

- **Backend:** PHP 8.1+, PDO with prepared statements, MySQL 8.0+ /
  MariaDB 10.6+ (utf8mb4 throughout).
- **Frontend:** server-rendered PHP views + vanilla ES module JavaScript.
- **Styling:** two hand-written stylesheets (global + recipe-picker). No
  Tailwind, Bootstrap, jQuery, React, etc. — the `tools/stack_audit.sh` script
  enforces this.
- **Routing:** front-controller (`public_html/index.php`) with a regex route
  table. Subdirectory installs (`/cookbook/...`) are supported via
  `app_base_path()` and `url_for()` helpers.
- **Auth:** session-based, single admin user. Session cookie hardened
  (`HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS). Schema carries `user_id`
  FKs everywhere so multi-user is a future flip, not a rewrite.
- **CSRF:** per-session token in `<meta name="csrf-token">`; required on every
  non-GET endpoint via the `X-CSRF-Token` header (or `_csrf` form field).
- **AI:** Anthropic Messages API; default model `claude-sonnet-4-6`. System
  prompt is sent as a cached block (`cache_control: ephemeral`); transport
  errors retry with exponential backoff.

## Repository layout

```text
recipes/
├── public_html/              # Deployable web root (everything ships from here)
│   ├── index.php             # Front controller + route table
│   ├── install.php           # One-shot installer (DB + admin + config.php)
│   ├── migrate.php           # Web-based DB migration runner
│   ├── config.example.php    # Reference shape for config.php
│   ├── .htaccess             # Front-controller rewrites + .php denials
│   ├── README.md             # Deployment-side notes
│   ├── assets/
│   │   ├── css/              # styles.css + recipe-picker.css
│   │   ├── js/                # ES modules (one per page + shared)
│   │   └── img/uploads/       # User-uploaded recipe images (gitignored)
│   ├── db/
│   │   ├── schema.sql        # Base schema (fresh installs)
│   │   ├── seeds.sql         # 12 starter recipes + pantry staples
│   │   └── migrations/       # 001_ai_memory.sql, 002_ai_tool_audit.sql, …
│   └── src/
│       ├── controllers/      # Auth, Recipes, Pantry, Plan, Shopping, Print,
│       │                       Settings, Ai
│       ├── models/           # Recipe, Pantry, Shopping, Plan, Settings,
│       │                       Memory, Conversation, CookingLog, ToolAudit
│       ├── lib/              # db, auth, csrf, response, view, constants,
│       │                       version, ai, pantry_helpers
│       └── views/            # Plain PHP templates per page
├── tools/                    # CLI / SSH validation utilities
│   ├── stack_audit.sh        # No-frameworks-allowed enforcement
│   ├── db_validate.php       # Schema / seed integrity
│   ├── perf_check.php        # EXPLAIN hot queries, flag full scans
│   └── smoke.php             # End-to-end HTTP smoke against a live site
├── archive/design-prototype/ # Original JSX/HTML prototype, kept for reference
├── AI_ENHANCEMENT_PLAN.md    # Phased plan for the AI assistant
└── README.md                 # This file
```

## Quick start

### Local dev

1. Create a MySQL database + user.
2. Copy `public_html/config.example.php` to `public_html/config.php` and fill
   in DB credentials. Generate an `app_key` (32 random bytes hex):
   `php -r "echo bin2hex(random_bytes(32));"`.
3. Import the schema and (optionally) the seeds:
   ```sh
   mysql -u <user> -p <db> < public_html/db/schema.sql
   mysql -u <user> -p <db> < public_html/db/seeds.sql
   ```
4. Serve `public_html/` with PHP 8.1+ (built-in `php -S` works for dev) and
   open `/`.

### cPanel / shared hosting (recommended)

1. Create a MySQL DB + user in cPanel and grant all privileges.
2. Upload the contents of `public_html/` into your site docroot.
3. Visit `/install.php`. The wizard:
   - validates DB credentials,
   - runs `db/schema.sql` and `db/seeds.sql` (if present),
   - creates the admin user (id = 1) with the supplied email / password,
   - writes `public_html/config.php` (mode 0640) with a fresh `app_key`,
   - writes `db/install.lock` so the installer refuses to re-run.
4. **Delete `install.php`** from the server.
5. Sign in at `/login` and you're up.

To re-install, remove **both** `db/install.lock` *and* `config.php`.

## Database upgrades

For existing installs, new features ship as forward-only migrations in
`public_html/db/migrations/`. Two ways to apply them:

### Web (recommended — no SSH needed)

Visit `migrate.php` while signed in as the admin (e.g.
`https://yourdomain.com/migrate.php`, or
`https://yourdomain.com/cookbook/migrate.php` for a subdirectory install). It:

- creates a `schema_migrations` tracker table on first run,
- walks every `.sql` file in `db/migrations/` in lexicographic order,
- skips anything already recorded in the tracker,
- reports per-file pass / fail and stops on the first failure.

If a future migration ever locks you out of login, append the `app_key` from
`config.php` as a query string: `migrate.php?key=…`. The page is safe to
re-run; applied migrations are idempotent.

### CLI (SSH / `mysql` available)

```sh
mysql -u <user> -p <db> < public_html/db/migrations/001_ai_memory.sql
mysql -u <user> -p <db> < public_html/db/migrations/002_ai_tool_audit.sql
```

Fresh installs get every table from `db/schema.sql` automatically — migrations
are only needed when upgrading an existing DB.

## AI features (optional)

Set `anthropic_api_key` in `config.php` (or the `ANTHROPIC_API_KEY` env var) to
turn Sprout on. Without a key, every AI endpoint returns `503 ai_disabled` and
the UI gracefully hides AI controls.

When enabled:

- ✨ **Full chat at `/chat`** — persistent conversations, an editable list of
  remembered preferences, and a quick view of recently cooked recipes. Sprout
  knows your pantry, recipe library, cooking history, and everything you've
  told it. Includes a "🧠 Save preferences" button that scans the current
  conversation for durable facts and stores them.
- 🪟 **Per-page context** — every page publishes a small JSON `window_context`
  (current recipe id, visible ids, filters, selected text). The server
  re-fetches authoritative data from those hints, so when you say "halve this"
  or "organize this list", Sprout knows what *this* is.
- 🛠️ **Tool use** — Sprout can take real actions during a chat. Tools are
  preview-then-commit for anything destructive or bulk:
  - **Recipes:** `recipe_search`, `recipe_get`, `save_recipe_to_book`,
    `update_recipe`, `update_recipe_ingredients`, `update_recipe_steps`,
    `scale_recipe`, `substitute_ingredient`, `toggle_favorite`,
    `delete_recipe`, `open_recipe`.
  - **Pantry:** `pantry_search`, `pantry_set_in_stock`, `pantry_restock`,
    `pantry_remove`, `pantry_update`, `bulk_add_to_pantry`.
  - **Shopping:** `add_to_shopping_list`, `shopping_check`,
    `shopping_clear_checked`, `shopping_organize_by_aisle`,
    `shopping_build_from_plan`, `shopping_remove`.
  - **Plan:** `set_meal_plan_day`, `plan_clear_day`, `plan_clear_week`,
    `plan_swap_days`, `apply_week_plan`.
  - **Memory + meta:** `remember_preference`, `forget_preference`,
    `log_cooked_recipe`, `set_user_settings`, `navigate`, `undo`.
  - **Web:** Anthropic's server-side `web_search` tool for finding recipes
    online; Sprout never fabricates a result and pretends to have found it.
- ↩️ **One-click undo** — every reversible commit returns an `undo_token`;
  POST to `/api/ai/undo` (or just say "undo that" in chat) to reverse the
  action. Tokens are single-use and recorded in `ai_tool_audit`.
- 🧠 **Persistent memory with soft decay** — `ai_memories` stores facts the
  assistant has learned (diet, allergy, dislike, like, cuisine, household,
  equipment, skill, schedule, goal, other). Memories not reinforced in 180+
  days lose up to 2 weight, *unless* they are pinned, an allergy, or a diet
  rule (those never decay).
- 🍽️ **Cooking log** — the "🍽️ I made this" button on a recipe records a
  cook with optional 1–5 rating + notes. Sprout uses your highest-rated
  dishes when suggesting new ideas.
- ✨ **Quick-assist floating panel** — the ✨ button on every page opens a
  panel with four tabs:
  1. *Chat* — quick chat (a separate floating conversation; shares memory
     and tools with `/chat`),
  2. *Bulk add to pantry* — paste a fridge dump / recipe / list and Sprout
     parses it,
  3. *What can I make?* — pantry-aware suggestions, weeknight mode, or
     "try something new",
  4. *Import recipe* — paste any recipe text and open it pre-filled in the
     editor.
- 🛡️ **Safety** — user-pasted blobs are wrapped in `<untrusted_input>` tags;
  prompts treat that block as data, not instructions. Every tool call is
  recorded in `ai_tool_audit` along with the JSON input, JSON result, and
  (for reversible actions) an `undo_payload`.

The default model is `claude-sonnet-4-6`. The system prompt + slow-changing
context (recipe titles, profile, cooking history) are sent as a cached
ephemeral block, so back-to-back requests reuse cached context cheaply.
Transport errors (HTTP 408 / 429 / 5xx, `rate_limit_error`,
`overloaded_error`, network failures) auto-retry with exponential backoff up
to 2 retries per call.

## Routes & API

The full route table lives in `public_html/index.php`. Highlights:

**Pages (HTML):** `/`, `/favorites`, `/recipes/{id}`, `/recipes/{id}/edit`,
`/add`, `/pantry`, `/shopping`, `/plan`, `/print`, `/chat`, `/login`.

**JSON API (CSRF-protected on writes):**

- Recipes: `POST/PUT/DELETE /api/recipes`, `POST /api/recipes/{id}/favorite`,
  `PUT /api/recipes/{id}/notes`, `POST /api/uploads/recipe-image`,
  `GET /api/recipes/suggestions`, `GET /api/recipes/by-ingredients`.
- Pantry: `GET/POST /api/pantry`, `PATCH/DELETE /api/pantry/{id}`,
  `POST /api/pantry/{id}/restock`, `GET /api/pantry/categorize`.
- Shopping: `GET/POST/DELETE /api/shopping`, `PATCH/DELETE /api/shopping/{id}`,
  `POST /api/shopping/from-recipe/{id}`, `POST /api/shopping/move-to-pantry`.
- Plan: `GET/DELETE /api/plan`, `PUT /api/plan/{Mon..Sun}`,
  `POST /api/plan/build-shopping-list`.
- Settings: `GET/PUT /api/settings`.
- AI: `GET /api/ai/status`, `POST /api/ai/chat`, `POST /api/ai/undo`,
  `POST /api/ai/parse-ingredients`, `POST /api/ai/parse-recipe`,
  `POST /api/ai/recipe-suggestions`, `POST /api/ai/recipe-from-idea`,
  `POST /api/ai/categorize`, `POST /api/ai/plan-week`,
  `POST /api/ai/extract-memories`.
- AI memories: `GET/POST/DELETE /api/ai/memories`,
  `PATCH/DELETE /api/ai/memories/{id}`.
- AI conversations: `GET/POST /api/ai/conversations`,
  `GET/PATCH/DELETE /api/ai/conversations/{id}`.
- Cooking log: `GET/POST /api/cooking-log`, `DELETE /api/cooking-log/{id}`.
- Health: `GET /healthz` (returns version + login state).

All JSON responses share the envelope `{ ok: true, data: … }` on success or
`{ ok: false, error: "<code>" }` on failure (with HTTP status set).

## Theming

The look-and-feel is controlled by `data-*` attributes on the `<html>` element:

| Attribute             | Values                                              |
| --------------------- | --------------------------------------------------- |
| `data-theme`          | `rainbow` · `sunset` · `ocean` · `garden`           |
| `data-mode`           | `light` · `dark`                                    |
| `data-density`        | `compact` · `cozy` · `airy`                         |
| `data-radius`         | `sharp` · `default` · `round`                       |
| `data-fontpair`       | `default` · `serif` · `mono` · `rounded`            |
| `data-card-style`     | `mix` · `photo-only` · `glyph-only`                 |
| `data-units`          | `metric` · `imperial`                               |
| `data-sticker-rotate` | `on` · `off`                                        |
| `data-dot-grid`       | `on` · `off`                                        |

The **floating ⚙ Tweaks panel** (bottom-right) edits all of these live and
persists via `PUT /api/settings`. Sprout can also change them via the
`set_user_settings` tool ("hey, switch me to dark mode").

## Mobile

Under 720px wide, the desktop nav is replaced by:

- a **sticky top bar** with the brand, an ✨ AI button, and a drawer toggle,
- a **slide-in drawer** with full nav + sign-out, and
- a **bottom tab bar** (Browse / Pantry / Add / Plan / Shop).

Desktop layouts are unchanged.

## Validation tools

Run from the repo root after a deploy:

```sh
bash tools/stack_audit.sh                                # CI-safe; no DB
php  tools/db_validate.php                               # CLI; uses config.php
php  tools/perf_check.php                                # CLI; EXPLAINs hot queries
php  tools/smoke.php https://yourdomain.com you@x.com 'pw'  # CLI; live HTTP smoke
```

- **`stack_audit.sh`** scans `public_html/` for any `package.json`,
  `composer.json`, `node_modules/`, TypeScript / JSX / SCSS files, or
  references to React / Vue / Tailwind / jQuery / etc. Fails CI if any of
  them appear.
- **`db_validate.php`** confirms every expected table and index exists,
  charset is utf8mb4, FK integrity is intact, and the seeded counts match.
- **`perf_check.php`** runs `EXPLAIN` against the hottest queries
  (recipe lookups, pantry filters, shopping list, etc.) and warns when the
  optimiser picks a full scan or full-index access.
- **`smoke.php`** logs in via the live site, exercises every JSON endpoint
  (settings round-trip, pantry CRUD, shopping CRUD, plan toggle, favorite
  toggle), and rolls back any state it touched.

## Security & ops notes

- `public_html/config.php` (containing DB password, `app_key`, and
  optionally the Anthropic API key) is **gitignored** and **HTTP-blocked**
  via `.htaccess`. Never commit it.
- `db/install.lock` is gitignored and prevents the installer from re-running.
- `public_html/src/` and `public_html/db/` ship `.htaccess` files that deny
  HTTP access. The root `.htaccess` also denies dotfiles, `config.php`, and
  `config.example.php`.
- Delete `install.php` after first install.
- Use HTTPS in production. The session cookie's `Secure` flag is set
  automatically when `$_SERVER['HTTPS']` is on.
- Image uploads are validated by MIME (`image/jpeg|png|webp|gif`), capped
  at 10 MB, stored under `assets/img/uploads/` with a random name.
- Login redirects validate `?next=` to reject scheme-relative
  (`//evil.com`) and control-character payloads.

## Troubleshooting

- **Blank page / 500** — check PHP error log; verify PHP 8.1+, `pdo_mysql`
  loaded, DB credentials in `config.php`.
- **CSRF errors after long idle** — reload the page; the meta token will
  refresh.
- **`migrate.php` says "Sign in required" but I'm locked out** —
  use `migrate.php?key=<app_key>` with the value from `config.php`.
- **AI features don't appear** — confirm `anthropic_api_key` is set (or
  `ANTHROPIC_API_KEY` env var); hit `/api/ai/status` to verify
  `enabled: true`.
- **Reinstall** — remove **both** `db/install.lock` and `config.php`.
- **Subdirectory install (e.g. `/cookbook/`)** — works out of the box; the
  router derives the base path from `SCRIPT_NAME` and all internal links go
  through `url_for()`.

## License / use

Personal / home cookbook scaffold. Fork it, brand it, deploy it, eat well.
