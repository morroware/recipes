#!/usr/bin/env bash
# tools/stack_audit.sh — verify the deployable tree never picks up a banned
# framework, build tool, or transpiler. Run from repo root:
#
#     bash tools/stack_audit.sh
#
# Exit 0 if the public_html/ tree is clean. Exit 1 with a list of offenses
# otherwise. CLAUDE.md mandates: vanilla HTML/CSS/JS + plain PHP only.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="${1:-$ROOT/public_html}"

if [[ ! -d "$TARGET" ]]; then
  echo "stack_audit: target dir '$TARGET' does not exist" >&2
  exit 1
fi

echo "Recipe Book — stack audit"
echo "Scanning: $TARGET"
echo

failures=0
fail() {
  echo "FAIL: $1"
  failures=$((failures + 1))
}
ok() {
  echo "  OK: $1"
}

# 1. Banned tooling files anywhere in the deploy tree.
banned_files=(
  package.json
  package-lock.json
  yarn.lock
  pnpm-lock.yaml
  bun.lockb
  composer.json
  composer.lock
  webpack.config.js
  vite.config.js
  vite.config.ts
  rollup.config.js
  esbuild.config.js
  tsconfig.json
  tailwind.config.js
  tailwind.config.ts
  postcss.config.js
  babel.config.js
  .babelrc
)
for f in "${banned_files[@]}"; do
  hits=$(find "$TARGET" -type f -name "$f" 2>/dev/null)
  if [[ -n "$hits" ]]; then
    fail "banned file present: $f"
    echo "$hits" | sed 's/^/      /'
  else
    ok "no $f"
  fi
done

# 2. Banned directories.
banned_dirs=(node_modules vendor .next .nuxt dist build .parcel-cache)
for d in "${banned_dirs[@]}"; do
  hits=$(find "$TARGET" -type d -name "$d" 2>/dev/null)
  if [[ -n "$hits" ]]; then
    fail "banned directory present: $d"
    echo "$hits" | sed 's/^/      /'
  else
    ok "no $d/"
  fi
done

# 3. Banned source extensions (TypeScript / Sass / JSX).
ext_globs=("*.ts" "*.tsx" "*.jsx" "*.scss" "*.sass" "*.less")
for g in "${ext_globs[@]}"; do
  hits=$(find "$TARGET" -type f -name "$g" 2>/dev/null)
  if [[ -n "$hits" ]]; then
    fail "banned source extension: $g"
    echo "$hits" | sed 's/^/      /'
  else
    ok "no $g files"
  fi
done

# 4. Banned framework / library strings inside JS, CSS, PHP, HTML files in
#    the assets and views trees. We grep with word-ish boundaries to avoid
#    false hits on comments that explicitly call something out as forbidden.
#    Each pattern is tested as a regex in extended grep.
declare -a banned_patterns=(
  'from[[:space:]]+["'\''](react|vue|svelte|alpinejs|htmx|jquery)["'\'']'
  'require\(["'\''](react|vue|jquery)["'\'']\)'
  'cdn\.tailwindcss\.com'
  'tailwindcss'
  'bootstrap(\.min)?\.(css|js)'
  'jquery(-[0-9.]+)?(\.min)?\.js'
  'react(-dom)?(\.production|\.development)?(\.min)?\.js'
  'alpinejs'
  'htmx\.org'
)

# Limit to file types that ship to the browser or render server-side.
mapfile -t source_files < <(find "$TARGET" \
  \( -name '*.php' -o -name '*.html' -o -name '*.htm' -o -name '*.js' -o -name '*.css' \) \
  -type f 2>/dev/null)

if [[ ${#source_files[@]} -gt 0 ]]; then
  for pat in "${banned_patterns[@]}"; do
    hits=$(grep -InE "$pat" "${source_files[@]}" 2>/dev/null \
      | grep -vE 'CLAUDE\.md|README\.md|stack_audit')
    if [[ -n "$hits" ]]; then
      fail "banned reference matched: $pat"
      echo "$hits" | sed 's/^/      /'
    else
      ok "no match for: $pat"
    fi
  done
fi

# 5. composer + node toolchain hints in the wider repo (not just deploy tree).
for sentinel in node_modules .npmrc .nvmrc; do
  if [[ -e "$ROOT/$sentinel" ]]; then
    fail "repo-root sentinel present: $sentinel"
  else
    ok "no repo-root $sentinel"
  fi
done

echo
if [[ $failures -gt 0 ]]; then
  echo "$failures audit check(s) failed."
  exit 1
fi
echo "Stack audit clean."
exit 0
