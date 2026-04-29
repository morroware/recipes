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

- ✨ **Bulk pantry add** — paste any list / recipe / fridge dump and Claude
  parses it into pantry items (with categories) in one click.
- 🍳 **What can I make?** — Claude suggests new recipes tailored to your
  in-stock pantry; click any idea to have it fleshed out into a full,
  saveable recipe.
- 📋 **Import recipe** — paste raw recipe text and Claude structures it
  (title, ingredients, steps, tags, glyph, color) directly into the editor.
- 💬 **In-app chat** — the floating ✨ button on every page opens a
  kitchen-aware assistant that knows your pantry and recipe library.

The default model is `claude-sonnet-4-6`. The system prompt is sent with
ephemeral prompt caching so back-to-back requests reuse cached context.
