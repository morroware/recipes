# Personal Recipe Book

Production source for the Personal Recipe Book app (vanilla PHP + MySQL).

## Repository layout

- `public_html/` — deployable web root (app, installer, assets, SQL schema/seeds).
- `tools/` — CLI validation utilities for stack, DB integrity, performance, and smoke tests.
- `archive/design-prototype/` — original design/prototype export kept only for reference.

## Quick start (local)

1. Copy `public_html/config.example.php` to `public_html/config.php` and set DB credentials.
2. Or run `public_html/install.php` in a browser to initialize DB + admin user.
3. Serve `public_html/` with PHP 8.1+ and open `/`.

## Notes

- `public_html/config.php` is environment-specific and must not be committed.
- `public_html/src` and `public_html/db` are app-internal directories and should stay HTTP-blocked.
- The UI is mobile-friendly: a sticky top bar, slide-in drawer, and bottom tab
  bar appear under 720px wide. The desktop layout is unchanged.

## AI features (optional)

Setting `anthropic_api_key` in `config.php` (or the `ANTHROPIC_API_KEY` env
var) enables Claude-powered features:

- ✨ **Full chat at `/chat`** — a dedicated page with persistent
  conversations, an editable list of remembered preferences, and a quick
  view of recent cooks. Claude knows your pantry, library, cooking history,
  and everything you've told it.
- 🧠 **Persistent memory** — the assistant remembers stable preferences
  (diet, allergies, dislikes, household, equipment, schedule, goals) in the
  `ai_memories` table. You can edit them by hand, or click "🧠 Save
  preferences" to extract durable facts from the current chat.
- 🛠️ **Tool use** — during chat the assistant can save memories, add
  shopping items, set meal-plan days, and log a cooked recipe — without
  leaving the conversation.
- 🍽️ **Cooking log** — the "🍽️ I made this" button on every recipe records
  a cook (optionally with a 1–5 rating). The assistant uses your highest-
  rated dishes when suggesting new ideas.
- ✨ **Quick assist (floating panel)** — the ✨ button on every page opens
  a quick-chat + bulk pantry add + recipe suggestions + recipe import
  panel.
- 🍳 **Personalized suggestions** — recipe suggestions, the "build me a
  week" planner, and the "flesh out this idea" recipe builder all read your
  memories and cooking history, not just your pantry.
- 📋 **Bulk pantry add** & **Import recipe** — paste raw text; Claude
  parses ingredients or full recipes and writes them into the right tables.

### Database upgrade

Existing installs need the new AI tables. Two ways to apply migrations:

**Web (recommended for shared cPanel hosts without SSH).** Visit
`/migrate.php` while signed in as the admin. It walks every file in
`public_html/db/migrations/`, applies anything that hasn't run on this
database yet, and reports per-file pass/fail. Safe to re-run — applied
migrations are recorded in a `schema_migrations` table and skipped on
subsequent visits. If a future migration ever locks you out of login, you
can also reach it via `/migrate.php?key=<app_key>` using the value already
written into `config.php`.

**CLI (if you have SSH/`mysql` access):**

```sh
mysql -u <user> -p <db> < public_html/db/migrations/001_ai_memory.sql
```

Fresh installs get the AI tables automatically from `public_html/db/schema.sql`.

The default model is `claude-sonnet-4-6`. System prompts are sent with
ephemeral prompt caching so back-to-back requests reuse cached context.
