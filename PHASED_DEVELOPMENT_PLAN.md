# Personal Recipe Book — Phased Development Plan

This plan is based on the engineering handoff in `project/CLAUDE.md` and prototype references in `README.md`.

## Phase 0 — Discovery, Scope Lock, and Technical Setup (2–3 days)
**Goals**
- Confirm the open product decisions before implementation starts.
- Establish a clean repo structure and local runtime that matches the mandated stack.

**Inputs reviewed**
- Product/engineering requirements, stack constraints, and target architecture in `project/CLAUDE.md`.
- Prototype-as-source-of-truth guidance in `README.md`.

**Work items**
- Confirm four open questions from handoff:
  1. Single-user only vs multi-user now.
  2. Image storage strategy (local disk vs S3-compatible).
  3. Hosting target (shared LAMP, VPS, container).
  4. URL recipe import in v1 or v2.
- Initialize directories exactly as specified (`/public`, `/src`, `/db`).
- Add baseline environment config (`.env.example`) for DB/session settings.
- Set coding conventions: escaping, CSRF, prepared statements, JSON API error envelope.
- Define local quality gates (linting style checks, smoke scripts, SQL migration check).

**Exit criteria**
- Architecture decisions documented.
- Local app boots via `public/index.php`.
- Database connection test passes.

---

## Phase 1 — Data Model, Migrations, and Seed Fidelity (3–4 days)
**Goals**
- Build the MySQL schema that maps all prototype state.
- Seed the canonical recipes and pantry staples.

**Work items**
- Implement `db/schema.sql` per required entities/indexes:
  - users, recipes, recipe_tags, ingredients, steps, pantry_items, shopping_items, meal_plan, user_settings.
- Implement `db/seeds.sql`:
  - 12 recipes + ingredients + steps + tags from `project/data.jsx`.
  - 30 staple pantry items from `project/pantry-data.jsx`.
- Create lightweight migration runner script (idempotent for local/dev use).
- Add DB validation script that checks row counts and key constraints.

**Exit criteria**
- Schema applies cleanly to fresh MySQL 8 instance.
- Seed data loads without manual fixes.
- Spot-check parity with prototype content passes.

---

## Phase 2 — Backend Foundation (Router, Core Libs, Security) (3–5 days)
**Goals**
- Stand up the non-framework PHP core and secure request pipeline.

**Work items**
- Implement front controller and thin router in `public/index.php`.
- Add shared libs in `/src/lib`:
  - `db()` PDO helper, session/auth helper, CSRF generation/validation, response helpers.
- Define consistent controller and model structure.
- Implement global validation + error formatting for JSON APIs.
- Add security baseline:
  - CSRF required on all mutating endpoints.
  - server-side input validation and output escaping.

**Exit criteria**
- Core app routing works for HTML and `/api/*` JSON endpoints.
- CSRF enforcement verified on POST/PUT/PATCH/DELETE.
- All DB queries use prepared statements.

---

## Phase 3 — Vertical Slice: Browse + Recipe Detail + Favorites (5–7 days)
**Goals**
- Deliver first user-visible end-to-end flow with prototype visual parity.

**Work items**
- Copy `project/styles.css` and `project/recipe-picker.css` into public assets.
- Implement Browse page (`/`): search/filter/sort recipe grid.
- Implement Recipe detail page (`/recipes/{id}`):
  - full metadata, ingredients, steps, notes.
  - unit scaling logic (`fmtQty()` parity).
  - cooking mode dialog with keyboard navigation.
- Implement favorite toggle endpoint and Favorites page (`/favorites`).
- Build reusable components: Modal, Toast.

**Exit criteria**
- Browse → Detail → Favorite flow works against DB.
- UI behavior matches prototype for this slice.
- No client-side framework/build tooling introduced.

---

## Phase 4 — Pantry System and Suggestions Engine (6–8 days)
**Goals**
- Implement the most logic-heavy subsystem (inventory + categorization + suggestions).

**Work items**
- Port pantry normalization and category inference from `project/pantry-data.jsx` into PHP:
  - `normalizeName()` and `categorize()` equivalents.
- Build Pantry page (`/pantry`) with:
  - grouped categories, in-stock toggles, per-row category override.
  - most-used top section (purchase_count based).
  - out-of-stock collapsible section with “+ Shop”.
  - relative last-bought metadata.
- Implement ingredient-tag search mode.
- Implement “You can make…” ranking by in-stock pantry match percentage only.
- Implement pantry CRUD + restock API endpoints.

**Exit criteria**
- Pantry flows are end-to-end and persistence-backed.
- Suggestion ranking is deterministic and tested.
- OOS-to-shopping action works.

---

## Phase 5 — Shopping + Plan Integration (4–6 days)
**Goals**
- Complete weekly planning and shopping execution loop.

**Work items**
- Implement Plan page (`/plan`) and day-to-recipe assignments.
- Implement “Build shopping list from plan”.
- Implement Shopping page (`/shopping`):
  - checklist CRUD, manual add, clear all, print.
  - add-from-recipe with scaling.
  - “🥕 Stock pantry” move-to-pantry flow with dedupe and purchase_count increments.
- Build relevant endpoints under `/api/plan` and `/api/shopping`.

**Exit criteria**
- User can plan meals, generate list, shop, and stock pantry with correct state transitions.
- Server logic matches required dedupe/increment semantics.

---

## Phase 6 — Add/Edit Recipes + Print Hub + RecipePicker/Tweaks (5–7 days)
**Goals**
- Complete authoring and print workflows; finalize reusable JS modules.

**Work items**
- Implement Add page (`/add`) and recipe edit capabilities:
  - full form validation, dynamic ingredient/step editors.
- Implement Print hub (`/print`) and all print sheet variants.
- Port `RecipePicker` component as vanilla JS class.
- Port Tweaks panel (nine settings) with persistence to `/api/settings` and `<html data-*>` sync.

**Exit criteria**
- Authoring and printing are production-ready.
- Settings persist and visually apply across pages.

---

## Phase 7 — QA Hardening, Accessibility, and Release Readiness (4–6 days)
**Goals**
- Ensure v1 quality bar and acceptance checklist are fully met.

**Work items**
- Run complete deliverables checklist from `project/CLAUDE.md`.
- Add regression test matrix (functional smoke scripts + manual UX pass).
- Accessibility pass:
  - keyboard navigation, focus management, ARIA roles, contrast spot checks.
- Print validation on Letter and A4.
- Performance pass on key pages (query/index review, payload trims).
- Stack audit (confirm no prohibited frameworks/tooling).

**Exit criteria**
- All v1 checklist items pass.
- Known defects triaged and release blockers resolved.
- Deployment runbook written.

---

## Cross-Phase Delivery Practices
- **Branch strategy:** short-lived feature branches per vertical slice.
- **Definition of done per feature:**
  - UI parity with prototype.
  - API contract implemented and validated.
  - DB writes/read paths covered by smoke tests.
  - error and empty states implemented.
- **Risk controls:**
  - Build Pantry and Shopping flows early in staging due to highest business logic complexity.
  - Freeze CSS token naming from prototype to avoid theme regressions.
  - Add fixture-backed API tests for normalization, categorization, and shopping-to-pantry moves.

## Suggested Timeline (Indicative)
- **Total:** ~32–46 working days (6.5–9 weeks for one engineer).
- **Parallelization options:**
  - Front-end page shell + CSS parity can run in parallel with schema/API groundwork after Phase 1.
  - Print hub can be parallelized once RecipePicker core is stable.

## Milestones
1. **M1 (end Phase 2):** secure backend skeleton live.
2. **M2 (end Phase 4):** browse/detail/favorites + pantry fully functional.
3. **M3 (end Phase 6):** all pages/features implemented.
4. **M4 (end Phase 7):** release-ready v1.
