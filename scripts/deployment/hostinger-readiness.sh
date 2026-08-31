#!/usr/bin/env bash
set -euo pipefail

# Non-destructive Hostinger preflight helper. It never edits .env, permissions,
# migrations, databases, DNS, or deployed files.
APP_DIR="${1:-backend}"
failures=0
check() { if "$@"; then printf 'PASS %s\n' "$*"; else printf 'FAIL %s\n' "$*"; failures=$((failures + 1)); fi; }

command -v php >/dev/null 2>&1 || { echo 'FAIL php is unavailable'; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' && printf 'PASS php >= 8.2\n' || { printf 'FAIL php >= 8.2\n'; failures=$((failures + 1)); }
for extension in pdo_mysql mbstring openssl tokenizer xml ctype fileinfo curl bcmath json; do
  if php -r "exit(extension_loaded('${extension}') ? 0 : 1);"; then
    printf 'PASS php extension %s\n' "$extension"
  else
    printf 'FAIL php extension %s\n' "$extension"
    failures=$((failures + 1))
  fi
done
check test -f "$APP_DIR/artisan"
check test -f "$APP_DIR/public/index.php"
check test -d "$APP_DIR/storage"
check test -w "$APP_DIR/storage"
check test -d "$APP_DIR/bootstrap/cache"
check test -w "$APP_DIR/bootstrap/cache"

if [ -f "$APP_DIR/.env" ]; then
  debug_value="$(sed -n 's/^APP_DEBUG=//p' "$APP_DIR/.env" | tail -1)"
  env_value="$(sed -n 's/^APP_ENV=//p' "$APP_DIR/.env" | tail -1)"
  [ "$env_value" = production ] && printf 'PASS APP_ENV=production\n' || { printf 'FAIL APP_ENV=production\n'; failures=$((failures + 1)); }
  [ "$debug_value" = false ] && printf 'PASS APP_DEBUG=false\n' || { printf 'FAIL APP_DEBUG=false\n'; failures=$((failures + 1)); }
else
  printf 'PENDING .env is supplied by the Hostinger operator (not repository-managed)\n'
fi

if git rev-parse --git-dir >/dev/null 2>&1; then
  printf 'INFO release_sha=%s\n' "$(git rev-parse HEAD)"
fi
printf 'INFO non_destructive=true\n'
if [ "$failures" -gt 0 ]; then exit 1; fi
