# Personal Recipe Book — cPanel deployment runbook

This is the canonical step-by-step for cutting a release onto a shared cPanel
host. It assumes:

- PHP 8.1+ available via cPanel's *Select PHP Version*
- MySQL 8.0+ or MariaDB 10.6+
- Apache 2.4+ with `mod_rewrite`
- SSH access (Terminal or `ssh user@host` into your cPanel account)

Hard-NO list still in force from `project/CLAUDE.md`: no `composer install`, no
`npm`, no build step, no transpiler.

---

## 1. Pre-flight

On your laptop:

```sh
bash tools/stack_audit.sh
```

If this fails the deploy is dead — fix the reported file before continuing.
The audit guarantees the deploy zip will not contain `node_modules`,
`composer.json`, TypeScript, Tailwind, or any banned framework reference.

Bump the version constant if cutting a new release:

```sh
$EDITOR public_html/src/lib/version.php   # update APP_VERSION
git tag v$NEW_VERSION
```

---

## 2. Build the upload bundle

The deploy unit is the contents of `public_html/`. Anything outside
`public_html/` (the `tools/` dir, the `docs/` dir, the design prototypes in
`project/`) **does not get uploaded**. They live in the repo for development
only.

```sh
cd public_html
zip -r ../recipe-book-$(date +%Y%m%d).zip . \
    -x 'config.php' -x 'db/install.lock'
```

Excluding `config.php` and `db/install.lock` matters: those are
host-specific. Re-uploading them would either leak the previous host's DB
credentials or fail to re-run the installer.

---

## 3. Provision MySQL (first deploy only)

cPanel → *MySQL Databases*:

1. Create a database, e.g. `cpaneluser_recipes`.
2. Create a user, e.g. `cpaneluser_recipes`, with a strong password.
3. Add the user to the database with **All Privileges**.
4. Note the host (almost always `localhost`).

The web installer will run `db/schema.sql` against this DB on first visit, so
it must be empty (or the tables in `db/schema.sql` must not yet exist).

---

## 4. Upload + extract

cPanel → *File Manager*, navigate to the document root of your domain
(usually `~/public_html/` for the primary domain, or `~/public_html/<addon>/`
for an addon domain).

1. Upload `recipe-book-YYYYMMDD.zip`.
2. Right-click → **Extract**.
3. Verify the structure: you should see `index.php`, `install.php`,
   `assets/`, `src/`, `db/`, `.htaccess` directly at the docroot — **not**
   inside a `public_html/` subdirectory.

---

## 5. Run the installer

Visit `https://yourdomain.com/install.php` in a browser. Provide:

- DB host / name / user / password from step 3
- An admin email + password (this is the single login for the app)

The installer:

- runs `db/schema.sql` against the DB
- creates the admin user at `users.id = 1`
- runs `db/seeds.sql` (12 prototype recipes + 30 pantry staples)
- writes `config.php` (mode `0640`, contains DB credentials and an app key)
- creates `db/install.lock` so the installer refuses to run again

If the page reports an error, the most common causes are:

| Error | Likely cause |
|---|---|
| `DB connection failed: SQLSTATE[HY000] [1045]` | Wrong DB password, or the DB user wasn't added to the database with privileges |
| `DB connection failed: SQLSTATE[HY000] [2002]` | Wrong host (try `localhost` instead of an IP) |
| `Could not create admin user yet` | Schema didn't apply — usually a permissions issue; check that the DB user has `CREATE TABLE` |

---

## 6. Lock down the installer

Either delete `install.php` from the server, or trust the lockfile and leave
it. The lockfile is belt; deleting the file is suspenders.

---

## 7. Verify

Over SSH on the cPanel host, from the parent of `public_html/`:

```sh
# 1. Stack audit — must pass.
bash tools/stack_audit.sh

# 2. DB validation — schema + seed sanity.
php tools/db_validate.php

# 3. Perf check — EXPLAINs hot queries, flags full scans.
php tools/perf_check.php

# 4. End-to-end HTTP smoke — logs in, walks every API endpoint.
php tools/smoke.php https://yourdomain.com admin@example.com 'p4ssword'
```

If any of these exit non-zero, the deploy is not green.

---

## 8. Manual UX pass

Once the four scripts are clean, do a quick manual pass against the v1
checklist from `project/CLAUDE.md`:

- [ ] `/` browse: search, cuisine filter, time filter, tag bar all narrow the grid
- [ ] `/recipes/{id}` detail: scaler updates quantities live; metric ↔ imperial flips
- [ ] Cooking mode: ▶ Cook → arrow keys navigate steps → ✕ Exit returns focus to the trigger
- [ ] `/pantry`: category groups, in/out toggle, *Most used* + *Out of stock* sections, "+ Shop" works
- [ ] Pantry suggestions panel ranks recipes by % match using only in-stock items
- [ ] `/shopping`: add manually, add from recipe, **🥕 Stock pantry** button moves checked items to pantry and bumps `purchase_count` + `last_bought`
- [ ] `/plan`: assign recipes to days; *Build shopping list from plan*
- [ ] `/print`: shopping list, single card, booklet (multi-select), week — actually print on Letter and A4
- [ ] `/add`: form validates; ingredient/step rows add/remove
- [ ] Tweaks panel: settings persist server-side and re-apply on reload

---

## 9. Health endpoint

`GET /healthz` (no auth) returns:

```json
{
  "ok": true,
  "data": {
    "logged_in": false,
    "app": {
      "version": "1.0.0-alpha",
      "installed_at": "2026-04-29T18:50:00+00:00",
      "php": "8.2.x"
    }
  }
}
```

Useful for monitoring or for confirming what version is actually live after a
re-upload. The version is also exposed as `<meta name="app-version">` in
every authenticated page so QA can read it from the DOM.

---

## Updating an existing install

For routine updates (no schema change):

1. Run the pre-flight on your laptop (step 1).
2. Build the bundle (step 2).
3. Upload + extract over the existing files. **Do not** delete `config.php`
   or `db/install.lock`.
4. Run the four verification scripts (step 7).

For updates that change `db/schema.sql`:

1. Take a SQL dump first: cPanel → *phpMyAdmin* → *Export*.
2. Decide whether to apply the diff manually or to wipe + reinstall (the
   latter loses non-seeded user data — your custom recipes, pantry adds,
   shopping history). For a single-user app the practical path is usually a
   manual `ALTER TABLE` over phpMyAdmin.
3. Bump `APP_VERSION` so `/healthz` reflects the change.

---

## Troubleshooting

**`mod_rewrite` not active.** Symptom: `/recipes/12` 404s but `/index.php`
serves. Ask cPanel support to enable `mod_rewrite`, or move to a host that
ships it on by default.

**`config.php` is world-readable.** Re-set permissions over SSH:

```sh
chmod 0640 public_html/config.php
```

**Sessions don't persist.** PHP's session save path is sometimes locked down
on shared hosts. cPanel → *Select PHP Version* → *Switch To PHP Options* and
set `session.save_path` to a writable directory inside your home, e.g.
`/home/cpaneluser/tmp`.

**Fonts don't load behind a corporate proxy.** The layout pulls Google Fonts.
On a hardened network you can replace the `<link>` in `src/views/layout.php`
with a self-hosted bundle; the font names are unchanged.

**Want to reinstall from scratch.** Delete `public_html/config.php` and
`public_html/db/install.lock`, re-upload `install.php` if you removed it,
then visit `/install.php`. The installer will recreate everything.
