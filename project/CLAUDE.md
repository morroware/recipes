# Personal Recipe Book — Engineering Handoff (CLAUDE.md)

## Mission
Reimplement the existing high-fidelity React prototype (`Recipe Book.html`) as a production web app using **vanilla HTML/CSS/JS on the front end** and **PHP + MySQL on the back end**. The prototype is the source of truth for visual design, interaction patterns, copy, and feature scope. Match it pixel-for-pixel and behavior-for-behavior unless this doc explicitly diverges.

## Stack — non-negotiable
- **Front end:** vanilla HTML5, vanilla CSS (custom properties + flex/grid), vanilla JS (ES2020+, **no framework, no build step, no transpiler**). Native ES modules via `<script type="module">` are fine.
- **Back end:** PHP 8.1+ — a thin hand-rolled router + controllers. **No frameworks** (no Slim, Lumen, Laravel, Symfony, CodeIgniter). Composer is OK only for PSR-3 logging or similar tiny utilities; default to no Composer at all.
- **Database:** MySQL 8.0+ (or MariaDB 10.6+). Direct PDO — **no ORMs** (no Eloquent, Doctrine, RedBean).
- **Templating:** plain PHP templates with `<?= htmlspecialchars(...) ?>`. No Twig, no Blade.
- **Auth:** session-based (PHP `$_SESSION`), single-user app — but design schema with `user_id` foreign keys throughout so multi-user is a future flip.
- **Hard NO list:** React, Vue, Svelte, Alpine, htmx, jQuery, Tailwind, Bootstrap, Bulma, Webpack, Vite, Rollup, esbuild, TypeScript, Sass/Less, Node runtime in production, any ORM, any PHP framework, any CSS-in-JS.
- **Hard YES list:** PDO with prepared statements, a tiny `db()` helper, CSRF tokens on every mutating form/fetch, hand-written CSS using the existing custom-property tokens, vanilla JS using `fetch()` + `addEventListener`.

## Prototype files reference
The prototype is built in JSX (React) for prototyping speed only — **none of it ships as React**. Use these as reference:

| Prototype file | Translates to |
|---|---|
| `Recipe Book.html` | base layout + page shell (`/src/views/layout.php`) |
| `app.jsx` | top-level router & state — split across PHP controllers + per-page JS |
| `data.jsx` | recipes, sticker colors, aisles → `db/seeds.sql` + `/src/lib/constants.php` |
| `pantry-data.jsx` | **auto-categorizer + normalization + restock logic — port to PHP** (`Pantry::categorize()`, `Pantry::normalizeName()`). The 30-staple seed list lives here; replicate in `db/seeds.sql`. |
| `pages-a.jsx` | `BrowsePage`, `RecipeCard`, `DetailPage`, cooking mode, unit converter `fmtQty()` |
| `pages-b.jsx` | `PantryPage` (inventory checklist), `ShoppingPage`, `PlanPage`, `FavoritesPage`, `AddPage` |
| `pages-c.jsx` | `PrintHubPage` and the four print sheets |
| `recipe-picker.jsx` / `recipe-picker.css` | `RecipePicker` searchable list — port as a vanilla-JS class |
| `tweaks-panel.jsx` | settings panel — port as a vanilla-JS class, persist via `/api/settings` |
| `styles.css` | **copy as-is** to `/public/assets/css/styles.css` |
| `recipe-picker.css` | **copy as-is** to `/public/assets/css/recipe-picker.css` |

## Repo layout
```
/public            # web root (point Apache/nginx here)
  index.php        # front controller — routes to controllers
  /assets
    /css           # split per page or feature, mirror prototype's component groupings
    /js            # one file per page + shared utils
    /img           # uploaded recipe photos
/src
  /controllers     # one per resource: RecipesController, ShoppingController, etc.
  /views           # PHP templates
  /models          # thin data classes (Recipe, Ingredient, Pantry…)
  /lib             # db.php, auth.php, csrf.php, helpers.php
/db
  schema.sql       # CREATE TABLE statements
  seeds.sql        # the 12 seeded recipes from data.jsx
/CLAUDE.md         # this file
```

## Database schema (target)
Translate the prototype's in-memory state to these tables. Use `utf8mb4_unicode_ci`.

```sql
users (id, email, password_hash, display_name, created_at)

recipes (
  id, user_id FK, title, cuisine, summary,
  time_minutes INT, servings INT, difficulty ENUM('Easy','Medium','Hard'),
  glyph VARCHAR(8), color ENUM('mint','butter','peach','lilac','sky','blush','lime','coral'),
  photo_url, notes TEXT, is_favorite BOOL,
  created_at, updated_at
)

recipe_tags (recipe_id FK, tag VARCHAR(64), PK(recipe_id, tag))

ingredients (
  id, recipe_id FK, position INT,
  qty DECIMAL(10,3), unit VARCHAR(16), name VARCHAR(128),
  aisle ENUM('Produce','Pantry','Dairy','Meat & Fish','Bakery','Frozen','Spices','Other')
)

steps (id, recipe_id FK, position INT, text TEXT)

pantry_items (
  id, user_id FK, name VARCHAR(128), key_normalized VARCHAR(128),
  in_stock BOOL DEFAULT TRUE, qty DECIMAL(10,3) NULL, unit VARCHAR(16) NULL,
  category ENUM('Produce','Dairy','Meat & Fish','Bakery','Pantry','Spices','Frozen','Other'),
  last_bought DATETIME NULL, purchase_count INT DEFAULT 0,
  added_at DATETIME, UNIQUE(user_id, key_normalized)
)

shopping_items (
  id, user_id FK, name VARCHAR(128), qty DECIMAL(10,3), unit VARCHAR(16),
  source_recipe_id FK NULL, source_label VARCHAR(128),
  aisle VARCHAR(32), checked BOOL, position INT, created_at
)

meal_plan (id, user_id FK, day ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') UNIQUE per user, recipe_id FK)

user_settings (user_id PK FK, density, theme, mode, font_pair, radius, card_style,
               sticker_rotate BOOL, dot_grid BOOL, units ENUM('metric','imperial'))
```

Indexes: `recipes(user_id, title)`, `ingredients(recipe_id, name)`, `recipe_tags(tag)`, `shopping_items(user_id, checked)`.

## API surface
RESTful JSON, all under `/api/`. Always require CSRF token (header `X-CSRF-Token`) for non-GET. Always include `user_id` from session — never trust client.

```
GET    /api/recipes                  ?search=&cuisine=&tag=&time=&sort=
GET    /api/recipes/{id}
POST   /api/recipes                  (create)
PUT    /api/recipes/{id}
DELETE /api/recipes/{id}
POST   /api/recipes/{id}/favorite    (toggle)
PUT    /api/recipes/{id}/notes

GET    /api/pantry
POST   /api/pantry                   { name, in_stock?, category? }
PATCH  /api/pantry/{id}               { in_stock?, category?, qty?, unit? }
DELETE /api/pantry/{id}
POST   /api/pantry/{id}/restock      → in_stock=true, last_bought=now, purchase_count++
GET    /api/recipes/suggestions      → ranked by % pantry match (in-stock only)
GET    /api/recipes/by-ingredients   ?names[]=tomato&names[]=onion

GET    /api/shopping
POST   /api/shopping                 { name, qty, unit, source }
POST   /api/shopping/from-recipe/{id}?scale=1
PATCH  /api/shopping/{id}            { checked }
DELETE /api/shopping/{id}
DELETE /api/shopping                 (clear all)
POST   /api/shopping/move-to-pantry  → moves all checked items to pantry, dedupes by normalized name, returns {added, moved}

GET    /api/plan
PUT    /api/plan/{day}               { recipe_id | null }
POST   /api/plan/build-shopping-list

GET    /api/settings
PUT    /api/settings
```

## Pages to build (mirrors prototype's nav)
1. **Browse** (`/`) — hero, search, cuisine/time/tag filters, recipe grid
2. **Pantry** (`/pantry`) — fridge-inventory checklist:
   - Items grouped by category with auto-assigned categories (override-able dropdown per row)
   - Checkbox toggles in-stock/out-of-stock — items stay in the inventory list either way
   - "Most used" section (top 6 by purchase count)
   - "Out of stock" collapsible section with one-tap "+ Shop" button per item
   - Per-row stats: ×N bought, last-bought relative date ("3 days ago")
   - Second mode: "Find by ingredient" multi-tag search
   - "You can make…" panel sorts recipes by % match using **in-stock items only**
3. **Plan** (`/plan`) — week grid, "Build shopping list from plan"
4. **Shopping** (`/shopping`) — flat checklist, manual add, print, **🥕 Stock pantry** button (moves checked items → pantry, bumps purchase count + last-bought date)
5. **Favorites** (`/favorites`) — saved recipes
6. **Print** (`/print`) — shopping list / recipe card / booklet / week, with searchable multi-select picker
7. **Add** (`/add`) — full recipe form (ingredients + steps editor)
8. **Detail** (`/recipes/{id}`) — full view, scaler, cooking mode, notes, print button. "have ✓" badges next to ingredients only show for **in-stock** pantry items.

Cooking mode (fullscreen step-by-step) is a `<dialog>` modal triggered from detail; arrow keys navigate.

## Reusable JS components (write as small ES classes or factory functions)
- **`RecipePicker`** — searchable list, single or multi-select. Used in: Print page, meal-plan modal. Mirror prototype's `recipe-picker.jsx`.
- **`Modal`** — generic, traps focus, ESC to close.
- **`PantryRow`** — checkbox + name + category dropdown + meta + remove. Mirror `PantryRow` in `pages-b.jsx`.
- **`Tweaks`** — settings panel; persists via `PUT /api/settings`. Mirrors `tweaks-panel.jsx`. The nine settings (density, theme, mode, font pair, radius, card style, sticker rotate, dot grid, units) toggle `data-*` attrs on `<html>` so the existing CSS works unchanged.
- **`Toast`** — for confirmations like "🥕 5 items added to pantry" (animation already defined in `styles.css` as `@keyframes shop-toast-in`).

## Migrating the design
- **Copy `styles.css` directly.** It's vanilla CSS — drop it in `/public/assets/css/` and split if it gets unwieldy. The data-attribute theming (`[data-theme="ocean"]`, `[data-mode="dark"]`, etc.) is already vanilla and works as-is.
- **Copy `recipe-picker.css`** as-is.
- **Fonts:** keep the Google Fonts link tag in the layout template head. Same families.
- **The `RECIPES` array in `data.jsx`** becomes `db/seeds.sql`. Convert each recipe (and its ingredients + steps + tags) into matching `INSERT` statements.
- **Print CSS works unchanged** — `@media print` rules don't care about your stack.

## Conversions from prototype
- React `useState` → server-side state in MySQL + per-page JS holding ephemeral UI state in memory or `sessionStorage`.
- `localStorage` persistence in prototype → DB via the API. Drop localStorage entirely once auth is in.
- `setRoute({page})` → real URLs + page reloads (or History API + `fetch()` for SPA-feel; either is fine, but SSR-first is simpler).
- `window.print()` calls → keep verbatim.
- Imperial/metric conversion logic in `pages-a.jsx` `fmtQty()` → port to a JS util or a PHP helper depending on where rendering happens.
- **Pantry auto-categorizer** in `pantry-data.jsx` (`CATEGORY_RULES`, `categorize()`, `normalizeName()`) → port to PHP as `Pantry::categorize()`. Either run server-side on insert, or expose via a `/api/pantry/categorize?name=...` endpoint and call it from the form.
- **Stock-pantry flow:** `actions.moveCheckedToPantry()` in `app.jsx` becomes `POST /api/shopping/move-to-pantry` server-side. Logic: dedupe by normalized name, set `in_stock=1`, set `last_bought=NOW()`, `purchase_count = purchase_count + 1`. If item doesn't exist, INSERT it.
- **Out-of-stock → shopping list:** `actions.addOutOfStockToShopping()` becomes `POST /api/shopping { name, source: 'pantry' }` (just a regular shopping insert).
- **Migration safety:** prototype's `migratePantry()` in `pantry-data.jsx` upgrades legacy string arrays to objects. The PHP equivalent isn't needed since the schema is fresh — just load via `seeds.sql`.

## Quality bar
- Every form: CSRF token, server-side validation, friendly error messages.
- Every query: PDO prepared statement.
- Every fetch: handle network failure, show toast.
- Empty states: copy from prototype ("No favorites yet. Tap the heart on any recipe.")
- Print pages: must produce real, beautiful printouts on Letter and A4. Test before claiming done.
- Accessibility: keyboard nav for picker (↑↓ Enter), focus rings, ARIA on modals, semantic HTML.

## What NOT to redesign
The visual system (palette, type, sticker shadows, dot grid, pill tags, chunky borders) is locked. Don't substitute Bootstrap, Tailwind, or any other framework's defaults. If something looks broken in vanilla CSS, fix the CSS — don't replace the design.

## Open questions to confirm with user before starting
1. Single-user (just them) or multi-user from day one?
2. Photo uploads — local disk under `/public/assets/img/uploads/` or S3-compatible?
3. Hosting target — shared LAMP host, VPS, or container?
4. Do they want recipe import from URL (scrape JSON-LD) as a v1 feature, or v2?

## Deliverables checklist for v1
- [ ] `db/schema.sql` runs clean against fresh MySQL 8
- [ ] `db/seeds.sql` populates the 12 prototype recipes + 30 staple pantry items
- [ ] All 8 pages above render and work end-to-end
- [ ] Tweaks panel persists settings server-side
- [ ] Print: shopping list, single card, booklet, week — all print correctly
- [ ] Pantry inventory: category groups, in/out toggle, OOS section, "+ Shop" action, most-used section, last-bought relative dates
- [ ] Pantry suggestions sort by match % using in-stock items only
- [ ] Search by ingredient(s) returns expected recipes
- [ ] Cooking mode: fullscreen, keyboard nav, progress bar
- [ ] Recipe scaling updates ingredient quantities live
- [ ] Add/edit recipe form: validation, all fields, ingredient/step add/remove
- [ ] "🥕 Stock pantry" button on shopping list moves checked items → pantry, bumps purchase count + last-bought date
- [ ] **Stack audit:** no `package.json`, no `node_modules`, no `composer.json` (or only PSR-3 deps), no React/Vue/jQuery/Tailwind anywhere in `/public`. Grep verifies clean.
