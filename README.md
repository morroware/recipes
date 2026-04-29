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
