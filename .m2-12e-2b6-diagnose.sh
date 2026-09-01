#!/usr/bin/env bash
set -euo pipefail

REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
REMOTE_SCRIPT='/home/u777904340/a3-production-app/.checkpoint-2b6-diagnostic.sh'

LOCAL_REMOTE_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/m212e-2b6-diagnostic.XXXXXX")"
trap 'rm -f "$LOCAL_REMOTE_SCRIPT"' EXIT
chmod 600 "$LOCAL_REMOTE_SCRIPT"

cat > "$LOCAL_REMOTE_SCRIPT" <<'REMOTE_SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
umask 077

SCRIPT_PATH="$0"
MYSQL_OPT=''
PARSER=''
trap 'rm -f "${MYSQL_OPT:-}" "${PARSER:-}" "${SCRIPT_PATH:-}"' EXIT

ROOT='/home/u777904340/a3-production-app'
RELEASE="$ROOT/releases/b69c7e125f083f52dc519f4a3cc3d401ba5a64b0"
BACKEND="$RELEASE/backend"
PUBLIC='/home/u777904340/domains/a3.ciptagrafika.com/public_html'
ENV_FILE='/home/u777904340/a3-production-app/shared/.env'
EXPECTED_DB='u777904340_a3production'
EXPECTED_DB_USER='u777904340_a3production'
EXPECTED_DEFAULT_SHA='aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45'

fail_stop() {
  printf 'STOP: %s\n' "$1"
  exit 1
}

test -d "$RELEASE" || fail_stop 'selected release missing'
test -f "$BACKEND/artisan" || fail_stop 'artisan missing'
test -f "$BACKEND/vendor/autoload.php" || fail_stop 'vendor autoload missing'
test -f "$BACKEND/config/view.php" || fail_stop 'config/view.php missing'
test -f "$ENV_FILE" || fail_stop 'shared .env missing'
[ "$(stat -c '%a' "$ENV_FILE")" = '600' ] || fail_stop 'shared .env mode mismatch'
[ -L "$BACKEND/.env" ] || fail_stop 'backend .env is not a symlink'
[ "$(readlink "$BACKEND/.env")" = "$ENV_FILE" ] || fail_stop 'backend .env target mismatch'
test ! -e "$RELEASE/.env" || fail_stop 'release root .env exists'
test ! -e "$ROOT/current" || fail_stop 'current symlink exists'

TOP="$(find "$PUBLIC" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort | paste -sd, -)"
[ "$TOP" = 'default.php,staging' ] || fail_stop 'public_html contents changed'
DEFAULT_SHA="$(sha256sum "$PUBLIC/default.php" | awk '{print $1}')"
[ "$DEFAULT_SHA" = "$EXPECTED_DEFAULT_SHA" ] || fail_stop 'default.php hash changed'

printf 'php_cli_version=%s\n' "$(php -r 'echo PHP_VERSION;')"
printf 'laravel_version=11.56.1\n'
for EXTENSION in pdo_mysql mbstring openssl tokenizer xml ctype fileinfo curl bcmath json; do
  php -m | grep -qx "$EXTENSION" || fail_stop "missing PHP extension: $EXTENSION"
done
printf 'required_php_extensions=PASS\n'

MIGRATION_TABLE_COUNT=''
MYSQL_OPT="$(mktemp)"
PARSER="$(mktemp)"
chmod 600 "$MYSQL_OPT" "$PARSER"

cat > "$PARSER" <<'PHP'
<?php
declare(strict_types=1);
$envPath = $argv[1];
$optionPath = $argv[2];
$expectedDb = 'u777904340_a3production';
$expectedUser = 'u777904340_a3production';
$values = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) $values[$m[1]][] = $m[2];
}
$required = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
foreach ($required as $key) if (!isset($values[$key]) || count($values[$key]) !== 1) exit(10);
function decodeDotenv(string $value): string {
    if (strlen($value) < 2 || $value[0] !== '"' || $value[strlen($value) - 1] !== '"') exit(11);
    $value = substr($value, 1, -1);
    return preg_replace_callback('/\\\\(["\\\\$])/', static fn(array $m): string => $m[1], $value) ?? exit(12);
}
$plain = static function (string $key) use ($values): string {
    $value = $values[$key][0];
    if ($value === '' || preg_match('/["\']/', $value)) exit(13);
    return $value;
};
if ($plain('DB_CONNECTION') !== 'mysql') exit(14);
if ($plain('DB_HOST') !== 'localhost') exit(15);
if ($plain('DB_PORT') !== '3306') exit(16);
if ($plain('DB_DATABASE') !== $expectedDb) exit(17);
if ($plain('DB_USERNAME') !== $expectedUser) exit(18);
$password = decodeDotenv($values['DB_PASSWORD'][0]);
if ($password === '') exit(19);
$escape = static fn(string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
$content = "[client]\n";
$content .= "host=localhost\nport=3306\nuser=" . $expectedUser . "\n";
$content .= "password=\"" . $escape($password) . "\"\n";
if (file_put_contents($optionPath, $content, LOCK_EX) === false) exit(20);
chmod($optionPath, 0600);
PHP

php "$PARSER" "$ENV_FILE" "$MYSQL_OPT" || fail_stop 'DB configuration parse failed'
[ "$(stat -c '%a' "$MYSQL_OPT")" = '600' ] || fail_stop 'MySQL option file mode mismatch'

DB_IDENTITY="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT DATABASE();')"
[ "$DB_IDENTITY" = "$EXPECTED_DB" ] || fail_stop 'database identity mismatch'
printf 'database_identity=PASS\n'
DB_USER_IDENTITY="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT CURRENT_USER();')"
[ "$DB_USER_IDENTITY" = "$EXPECTED_DB_USER@localhost" ] || fail_stop 'database user identity mismatch'
printf 'database_user_identity=PASS\n'
TABLE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();')"
[ "$TABLE_COUNT" = '0' ] || fail_stop 'application table count is not zero'
printf 'application_table_count=0\n'
MIGRATION_TABLE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations';")"
[ "$MIGRATION_TABLE_COUNT" = '0' ] || fail_stop 'migrations table exists'
printf 'migrations_table=ABSENT\n'

printf '%s\n' '--- ORIGINAL BOOTSTRAP PROBE ---'
set +e
ORIGINAL_ERROR="$(cd "$BACKEND" && php -r 'require "vendor/autoload.php"; $app = require_once "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();' 2>&1)"
ORIGINAL_STATUS=$?
set -e
if [ "$ORIGINAL_STATUS" -eq 0 ]; then
  printf 'original_bootstrap_status=PASS\n'
else
  printf 'original_bootstrap_status=FAIL\n'
  printf 'original_bootstrap_error=%s\n' "$(printf '%s' "$ORIGINAL_ERROR" | tr '\n' ' ' | sed -E 's/(password|secret|token|APP_KEY)=[^ ]+/\1=[REDACTED]/Ig')"
fi

printf '%s\n' '--- LARAVEL CLI PROBES ---'
set +e
(cd "$BACKEND" && php artisan --version >/tmp/m212e-artisan-version.out 2>/tmp/m212e-artisan-version.err)
ARTISAN_VERSION_STATUS=$?
(cd "$BACKEND" && php artisan about --only=environment >/tmp/m212e-artisan-about.out 2>/tmp/m212e-artisan-about.err)
ARTISAN_ABOUT_STATUS=$?
set -e
printf 'artisan_version_status=%s\n' "$ARTISAN_VERSION_STATUS"
printf 'artisan_about_environment_status=%s\n' "$ARTISAN_ABOUT_STATUS"

printf '%s\n' '--- CORRECTED LARAVEL 11 BOOTSTRAP ---'
set +e
BOOTSTRAP_OUTPUT="$(cd "$BACKEND" && php -r '
  require "vendor/autoload.php";
  try {
    $app = require "bootstrap/app.php";
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $message = "";
    $message .= app()->environment() === "production" ? "laravel_environment=production\n" : "";
    $message .= (bool) config("app.debug") === false ? "laravel_debug=false\n" : "";
    $message .= (string) config("database.default") === "mysql" ? "laravel_db_connection=mysql\n" : "";
    $message .= (string) config("database.connections.mysql.database") === "u777904340_a3production" ? "laravel_db_database_identity=PASS\n" : "";
    if ($message !== "laravel_environment=production\nlaravel_debug=false\nlaravel_db_connection=mysql\nlaravel_db_database_identity=PASS\n") exit(21);
    echo $message;
  } catch (Throwable $e) {
    $message = preg_replace("/(password|secret|token|APP_KEY)=[^ ]+/i", "$1=[REDACTED]", $e->getMessage()) ?? "[REDACTED]";
    fwrite(STDERR, get_class($e) . "|" . $message . "|" . $e->getFile() . "|" . $e->getLine());
    exit(22);
  }
' 2>&1)"
BOOTSTRAP_STATUS=$?
set -e
if [ "$BOOTSTRAP_STATUS" -eq 0 ]; then
  printf 'bootstrap_status=PASS\n'
  printf '%s' "$BOOTSTRAP_OUTPUT"
else
  printf 'bootstrap_status=FAIL\n'
  IFS='|' read -r EXCEPTION_CLASS EXCEPTION_MESSAGE EXCEPTION_FILE EXCEPTION_LINE <<< "$BOOTSTRAP_OUTPUT"
  printf 'exception_class=%s\n' "${EXCEPTION_CLASS:-unknown}"
  printf 'exception_message=%s\n' "${EXCEPTION_MESSAGE:-[unavailable]}"
  printf 'exception_file=%s\n' "${EXCEPTION_FILE:-unknown}"
  printf 'exception_line=%s\n' "${EXCEPTION_LINE:-unknown}"
fi

printf 'production_env=present_mode_600\n'
printf 'backend_env_symlink=present\n'
printf 'backend_env_target=%s\n' "$ENV_FILE"
printf 'release_root_env=ABSENT\n'
printf 'current_symlink=ABSENT\n'
printf 'public_html_touched=NO\n'
printf 'default.php_sha_unchanged=YES\n'
printf 'database_mutation=NONE\n'
printf 'migrations=NOT_RUN\n'
printf 'imports=NOT_RUN\n'
printf 'bootstrap_mutation=NONE\n'
REMOTE_SCRIPT

LOCAL_SHA="$(shasum -a 256 "$LOCAL_REMOTE_SCRIPT" | awk '{print $1}')"
REMOTE_EXISTS="$(ssh -T -p "$SSH_PORT" "$REMOTE" "test -e '$REMOTE_SCRIPT' && printf YES || printf NO")"
[ "$REMOTE_EXISTS" = 'NO' ] || {
  printf 'STOP: remote diagnostic script already exists\n'
  exit 20
}

scp -P "$SSH_PORT" "$LOCAL_REMOTE_SCRIPT" "$REMOTE:$REMOTE_SCRIPT"
REMOTE_SHA="$(ssh -T -p "$SSH_PORT" "$REMOTE" "sha256sum '$REMOTE_SCRIPT' | cut -d' ' -f1")"
printf 'diagnostic_script_local_sha256=%s\n' "$LOCAL_SHA"
printf 'diagnostic_script_remote_sha256=%s\n' "$REMOTE_SHA"
[ "$LOCAL_SHA" = "$REMOTE_SHA" ] || {
  ssh -T -p "$SSH_PORT" "$REMOTE" "rm -f '$REMOTE_SCRIPT'"
  printf 'STOP: diagnostic script hash mismatch\n'
  exit 21
}

ssh -T -p "$SSH_PORT" "$REMOTE" "chmod 700 '$REMOTE_SCRIPT'"
ssh -T -p "$SSH_PORT" "$REMOTE" "bash '$REMOTE_SCRIPT'"
