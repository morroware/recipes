# Personal Recipe Book — cPanel deploy

Vanilla PHP + MySQL, no build step. Drop `public_html/` onto a shared cPanel
host and run the installer.

## Prerequisites

- PHP **8.1+** (cPanel → "Select PHP Version" if you need to change it)
- MySQL **8.0+** or MariaDB **10.6+**
- Apache with `mod_rewrite` enabled (the default on cPanel)

## Deploy steps

1. **Create a MySQL DB** in cPanel → *MySQL Databases*.
   - Make a new database, e.g. `cpaneluser_recipes`
   - Make a new DB user, e.g. `cpaneluser_recipes`
   - Add the user to the database with **All Privileges**
   - Note the host (almost always `localhost`)

2. **Upload this folder** so the contents of `public_html/` end up at the
   document root of your domain (or addon-domain). Easiest path: zip up
   `public_html/`, upload via cPanel → File Manager, extract in place.

3. **Visit `/install.php`** in your browser. Provide:
   - the DB credentials from step 1
   - an admin email + password (this is the single login)

   The installer creates the schema, seeds 12 recipes + 30 pantry staples,
   writes `config.php`, and self-locks.

4. **Delete `install.php`** from the server (the lockfile already prevents it
   from running again, but removing the file is belt-and-braces).

5. Visit `/` — you should see the cookbook home page.

## Layout

```
public_html/
  .htaccess        ← rewrites everything to index.php
  index.php        ← front controller / router
  install.php      ← run-once installer (delete after install)
  config.php       ← written by installer; never commit this
  assets/{css,js,img}
  src/             ← protected by Deny-all .htaccess
    lib/           controllers/  models/  views/
  db/              ← protected by Deny-all .htaccess
    schema.sql  seeds.sql  install.lock
```

`/src` and `/db` sit inside `public_html/` for easy upload, but their own
`.htaccess` files block all HTTP access. PHP still reads them through the
filesystem.

## Reinstalling

Delete `public_html/config.php` and `public_html/db/install.lock`, then
re-upload `install.php` and visit it again.

## Verifying a deployment

For the full step-by-step (zip, upload, install, verify, troubleshoot), see
[`docs/DEPLOYMENT.md`](../docs/DEPLOYMENT.md) at repo root.

Four tools live in the sibling `tools/` directory (one level above
`public_html/`). They are CLI-only and never web-accessible. Run them over
SSH on the cPanel host after install:

1. **Stack audit** — fails the build if the deploy tree picked up a banned
   framework, a build-tool config, or a transpiled source extension. No PHP
   needed.
   ```
   bash tools/stack_audit.sh
   ```

2. **DB validation** — confirms schema + seeds applied cleanly: 12 recipes,
   30+ pantry staples, every recipe has ingredients/steps, indexes exist, no
   orphan rows. Reads `public_html/config.php` automatically.
   ```
   php tools/db_validate.php
   ```

3. **Perf check** — EXPLAINs the hot SQL queries (recipe list, pantry
   in-stock keys, ingredient bulk fetch, shopping list, meal plan…) against
   the live DB and flags full table scans (`type=ALL`). Read-only.
   ```
   php tools/perf_check.php
   ```

4. **HTTP smoke test** — logs in as the admin user via cURL and walks every
   JSON endpoint (settings, pantry CRUD + restock, suggestions, ingredient
   search, shopping CRUD, plan, favorite toggle, CSRF rejection). Self-cleans;
   leaves DB state unchanged. Requires the PHP `curl` extension.
   ```
   php tools/smoke.php https://yourdomain.com admin@example.com 'p4ssword'
   ```

All four exit 0 on success and non-zero with a list of failures otherwise,
so they slot straight into a post-deploy script.

`GET /healthz` (no auth) is a public probe that returns the app version
(`APP_VERSION` from `src/lib/version.php`), the install timestamp, and the
running PHP version — handy for monitoring or for confirming what's live
after a re-upload.
