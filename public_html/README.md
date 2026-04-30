# Personal Recipe Book (public_html)

A no-build, shared-host-friendly recipe manager built with **vanilla PHP + MySQL + plain JS/CSS**.
This app is designed for cPanel-style deployments where `public_html/` is the web root.

## What the app does

- Browse recipe cards with search, cuisine filters, favorites, and quick metadata.
- Add, edit, and delete recipes with structured ingredients/steps and private notes.
- Pantry tracking with in-stock toggles and restock suggestions.
- Weekly meal planning with a recipe picker modal per day.
- Build a shopping list from planned meals.
- Printable recipe card layout.
- Lightweight AI-assisted flows (chat + plan/import helpers) when configured.
- Theme/density/radius/font tweaks and responsive layouts.

## Tech stack

- **Backend:** PHP 8.1+, PDO, MySQL/MariaDB
- **Frontend:** Server-rendered PHP views + vanilla ES modules
- **Styling:** Single global stylesheet + component stylesheet for recipe picker
- **Routing:** Front controller (`index.php`) with path dispatch

No Node/npm, bundler, or build pipeline is required.

## Directory map

```text
public_html/
  index.php                   # Front controller + route dispatch
  install.php                 # One-time installer
  config.php                  # Generated at install time (not committed)

  assets/
    css/
      styles.css              # Global UI system / responsive styles
      recipe-picker.css       # Picker component styles
    js/
      app.js                  # Shared client utilities
      *.js                    # Page-specific modules (plan, add, pantry, shopping...)
    img/uploads/              # Optional local image uploads

  src/
    controllers/              # Request handlers for pages + API
    models/                   # Data access/query logic
    views/                    # PHP templates and partials
    lib/                      # Utilities (db/auth/csrf/response/view helpers)

  db/
    schema.sql                # Base schema
    seeds.sql                 # Initial recipes + pantry staples
    migrations/               # Incremental SQL migrations
```

## Local/dev quick start

1. Create a MySQL database/user.
2. Copy `public_html/config.example.php` to `public_html/config.php` and fill credentials.
3. Import schema + seed data:
   - `public_html/db/schema.sql`
   - `public_html/db/seeds.sql`
4. Serve `public_html/` with PHP (or your Apache vhost docroot).
5. Open `/` in browser and log in with your seeded/admin credentials.

For hosted installs, use `install.php` instead.

## cPanel deployment (recommended)

1. Create DB + DB user in cPanel and grant all privileges.
2. Upload/extract this `public_html/` contents into site docroot.
3. Open `/install.php`, enter DB + admin credentials, and finish install.
4. Delete `install.php` after successful setup.

## API + behavior overview

The app mixes server-rendered pages and JSON APIs:

- Page endpoints render via `src/views/*`.
- Interactive UI actions use `fetch` to `/api/*` routes.
- CSRF protection is enforced for state-changing API requests.

Main interactive modules:

- `assets/js/browse.js`: search/filter/favorite behaviors.
- `assets/js/add.js`: dynamic ingredient/step editor + save/delete.
- `assets/js/plan.js`: day picker modal, clear day/week, build shopping list.
- `assets/js/pantry.js`: pantry toggles and suggestion actions.
- `assets/js/shopping.js`: shopping list CRUD interactions.

## Built-in validation/check tools (repo root)

Run from repo root after setup:

- `bash tools/stack_audit.sh`
- `php tools/db_validate.php`
- `php tools/perf_check.php`
- `php tools/smoke.php https://yourdomain.example admin@example.com 'password'`

## Current UI status notes

Recent UI fixes include:

- Meal plan cards now use valid interactive markup (no nested button-in-link conflict).
- Meal plan cards now preserve layout better for long recipe titles/metadata.
- Added an explicit “Open recipe” action in planned day cards for clearer affordance.

## Security/ops notes

- Keep `config.php` out of version control.
- Delete `install.php` once installation is complete.
- `src/` and `db/` are intended to be HTTP-blocked via `.htaccess`.
- Use HTTPS in production and strong admin credentials.

## Troubleshooting

- Blank/500 page: verify PHP version/extensions and DB credentials in `config.php`.
- API errors in UI: inspect browser console/network and server PHP error log.
- Missing assets/styles: confirm document root points to this `public_html/`.
- Installer lock issues: remove `db/install.lock` only when intentionally reinstalling.

## License / usage

Internal project scaffold for personal/home recipe management.
Adjust branding, copy, and data for your own deployment.
