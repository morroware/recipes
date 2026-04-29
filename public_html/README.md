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
