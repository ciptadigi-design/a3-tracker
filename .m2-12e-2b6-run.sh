#!/usr/bin/env bash
set -euo pipefail

APP_SHA='b69c7e125f083f52dc519f4a3cc3d401ba5a64b0'
REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
REMOTE_SCRIPT='/home/u777904340/a3-production-app/.checkpoint-2b6-migrate.sh'

LOCAL_REMOTE_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/m212e-2b6-remote.XXXXXX")"
trap 'rm -f "$LOCAL_REMOTE_SCRIPT"' EXIT
chmod 600 "$LOCAL_REMOTE_SCRIPT"

cat > "$LOCAL_REMOTE_SCRIPT" <<'REMOTE_SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
umask 077

SCRIPT_PATH="$0"
MIGRATION_LIST=''
APPLIED_LIST=''
MYSQL_OPT=''
PARSER=''

cleanup() {
  rm -f "${MYSQL_OPT:-}" "${PARSER:-}" "${MIGRATION_LIST:-}" "${APPLIED_LIST:-}" "${SCRIPT_PATH:-}"
}
trap cleanup EXIT

ROOT='/home/u777904340/a3-production-app'
SHARED="$ROOT/shared"
RELEASE="$ROOT/releases/b69c7e125f083f52dc519f4a3cc3d401ba5a64b0"
BACKEND="$RELEASE/backend"
PUBLIC='/home/u777904340/domains/a3.ciptagrafika.com/public_html'
ENV_FILE='/home/u777904340/a3-production-app/shared/.env'
BACKUP_FILE='/home/u777904340/m2-12e-backups/phase2b-5-pre-schema-20260901T081854Z/production-pre-schema.sql.gz'
EXPECTED_BACKUP_SHA='ddd216f38defc109a670af52d6b35a6c4109eb94ae7009b518b9f14ac0778657'
EXPECTED_DB='u777904340_a3production'
EXPECTED_DB_USER='u777904340_a3production'
EXPECTED_APP_SHA='b69c7e125f083f52dc519f4a3cc3d401ba5a64b0'
EXPECTED_DEFAULT_SHA='aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45'

fail_stop() {
  printf 'STOP: %s\n' "$1"
  exit 1
}

printf '%s\n' '--- PRE-MIGRATION AUDIT ---'
[ "$(hostname)" = 'id-dci-web1761.main-hosting.eu' ] || fail_stop 'hostname mismatch'
[ "$(whoami)" = 'u777904340' ] || fail_stop 'whoami mismatch'
test -d "$RELEASE" || fail_stop 'selected release missing'
test -f "$BACKEND/artisan" || fail_stop 'backend/artisan missing'
test -f "$BACKEND/vendor/autoload.php" || fail_stop 'vendor/autoload.php missing'
test -f "$BACKEND/config/view.php" || fail_stop 'config/view.php missing'
test -f "$BACKEND/composer.lock" || fail_stop 'composer.lock missing'
test -f "$ENV_FILE" || fail_stop 'shared .env missing'
[ "$(stat -c '%a' "$ENV_FILE")" = '600' ] || fail_stop 'shared .env mode mismatch'
test ! -e "$RELEASE/.env" || fail_stop 'release root .env exists'
test ! -e "$ROOT/current" || fail_stop 'current symlink exists'

BACKEND_ENV_PREEXISTING=NO
if [ -e "$BACKEND/.env" ] || [ -L "$BACKEND/.env" ]; then
  [ -L "$BACKEND/.env" ] || fail_stop 'backend .env exists but is not a symlink'
  [ "$(readlink "$BACKEND/.env")" = "$ENV_FILE" ] || fail_stop 'backend .env symlink target mismatch'
  BACKEND_ENV_PREEXISTING=YES
fi
printf 'backend_env_preexisting=%s\n' "$BACKEND_ENV_PREEXISTING"

MIGRATION_LIST="$(mktemp)"
APPLIED_LIST="$(mktemp)"
MYSQL_OPT="$(mktemp)"
PARSER="$(mktemp)"
chmod 600 "$MIGRATION_LIST" "$APPLIED_LIST" "$MYSQL_OPT" "$PARSER"

find "$BACKEND/database/migrations" -maxdepth 1 -type f -name '*.php' -printf '%f\n' | sed 's/\.php$//' | sort > "$MIGRATION_LIST"
EXPECTED_MIGRATION_COUNT="$(wc -l < "$MIGRATION_LIST" | tr -d ' ')"
[ "$EXPECTED_MIGRATION_COUNT" = '15' ] || fail_stop 'migration count is not 15'
printf 'expected_migration_count=%s\n' "$EXPECTED_MIGRATION_COUNT"
printf 'migration_files=\n'
sed 's/^/  /' "$MIGRATION_LIST"

STALE_CONFIG_CACHE="$(find "$BACKEND/bootstrap/cache" -maxdepth 1 -type f -name 'config.php' -print -quit 2>/dev/null || true)"
[ -z "$STALE_CONFIG_CACHE" ] || fail_stop 'unexpected compiled config cache exists'
test -w "$BACKEND/storage" || fail_stop 'storage is not writable'
test -w "$BACKEND/bootstrap/cache" || fail_stop 'bootstrap/cache is not writable'

grep -R -q 'machine_component_slot_uq' "$BACKEND/database/migrations" || fail_stop 'machine_component_slot_uq not proven'
grep -R -q 'component_lifecycle_active_uq' "$BACKEND/database/migrations" || fail_stop 'component_lifecycle_active_uq not proven'
grep -R -q 'component_lifecycle_request_uq' "$BACKEND/database/migrations" || fail_stop 'component_lifecycle_request_uq not proven'
grep -R -q 'mc_excl_machine_slot_clear_uq' "$BACKEND/database/migrations" || fail_stop 'mc_excl_machine_slot_clear_uq not proven'
grep -R -q '00000000-0000-0000-0000-000000000001' "$BACKEND/database/migrations" || fail_stop 'baseline UUID not proven'
printf 'repository_identifier_audit=PASS\n'

test -f "$BACKUP_FILE" || fail_stop 'pre-schema backup missing'
[ "$(sha256sum "$BACKUP_FILE" | awk '{print $1}')" = "$EXPECTED_BACKUP_SHA" ] || fail_stop 'pre-schema backup SHA mismatch'
gzip -t "$BACKUP_FILE" || fail_stop 'pre-schema backup gzip integrity failed'
printf 'pre_schema_backup_identity=PASS\n'

TOP="$(find "$PUBLIC" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort | paste -sd, -)"
[ "$TOP" = 'default.php,staging' ] || fail_stop 'public_html contents mismatch'
DEFAULT_SHA="$(sha256sum "$PUBLIC/default.php" | awk '{print $1}')"
[ "$DEFAULT_SHA" = "$EXPECTED_DEFAULT_SHA" ] || fail_stop 'default.php hash mismatch'

cat > "$PARSER" <<'PHP'
<?php
declare(strict_types=1);
$envPath = $argv[1];
$optionPath = $argv[2];
$expectedDb = 'u777904340_a3production';
$expectedUser = 'u777904340_a3production';
$lines = file($envPath, FILE_IGNORE_NEW_LINES);
$values = [];
foreach ($lines as $line) {
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
$dbPassword = decodeDotenv($values['DB_PASSWORD'][0]);
if ($dbPassword === '') exit(19);
$escape = static fn(string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
$content = "[client]\n";
$content .= "host=localhost\n";
$content .= "port=3306\n";
$content .= "user=" . $expectedUser . "\n";
$content .= "password=\"" . $escape($dbPassword) . "\"\n";
if (file_put_contents($optionPath, $content, LOCK_EX) === false) exit(20);
chmod($optionPath, 0600);
PHP

/usr/bin/php "$PARSER" "$ENV_FILE" "$MYSQL_OPT" || fail_stop 'DB configuration parse failed'
[ "$(stat -c '%a' "$MYSQL_OPT")" = '600' ] || fail_stop 'MySQL option file mode mismatch'

DB_IDENTITY="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT DATABASE();')"
[ "$DB_IDENTITY" = "$EXPECTED_DB" ] || fail_stop 'database identity mismatch'
printf 'database_identity=PASS\n'

DB_USER_IDENTITY="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT CURRENT_USER();')"
[ "$DB_USER_IDENTITY" = "$EXPECTED_DB_USER@localhost" ] || fail_stop 'database user identity mismatch'
printf 'database_user_identity=PASS\n'

PRE_TABLE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();')"
[ "$PRE_TABLE_COUNT" = '0' ] || fail_stop 'pre-migration application table count is not zero'
printf 'pre_migration_application_table_count=0\n'

PRE_MIGRATIONS_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations';")"
[ "$PRE_MIGRATIONS_COUNT" = '0' ] || fail_stop 'pre-migration migrations table exists'
printf 'pre_migration_migrations_table=ABSENT\n'

if [ "$BACKEND_ENV_PREEXISTING" = 'NO' ]; then
  ln -s "$ENV_FILE" "$BACKEND/.env"
fi
[ -L "$BACKEND/.env" ] || fail_stop 'backend .env symlink creation failed'
[ "$(readlink "$BACKEND/.env")" = "$ENV_FILE" ] || fail_stop 'backend .env symlink target mismatch'
printf 'backend_env_wiring=PASS\n'

STALE_CONFIG_CACHE_AFTER="$(find "$BACKEND/bootstrap/cache" -maxdepth 1 -type f -name 'config.php' -print -quit 2>/dev/null || true)"
[ -z "$STALE_CONFIG_CACHE_AFTER" ] || fail_stop 'unexpected compiled config cache exists'

(
  cd "$BACKEND"
  /usr/bin/php -r 'require "vendor/autoload.php"; try { $app = require_once "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (app()->environment() !== "production") exit(10); if ((bool) config("app.debug") !== false) exit(11); if ((string) config("database.default") !== "mysql") exit(12); if ((string) config("database.connections.mysql.database") !== "u777904340_a3production") exit(13); printf("laravel_environment=production\\nlaravel_debug=false\\nlaravel_db_connection=mysql\\nlaravel_db_database_identity=PASS\\n"); } catch (Throwable $e) { $m = preg_replace("/(password|secret|token|APP_KEY)=[^ ]+/i", "$1=[REDACTED]", $e->getMessage()) ?? "[REDACTED]"; fwrite(STDERR, get_class($e)."|".$m."|".$e->getFile()."|".$e->getLine()); exit(14); }'
) || fail_stop 'Laravel environment bootstrap failed'

MIGRATION_START="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
printf 'migration_start_utc=%s\n' "$MIGRATION_START"
set +e
(
  cd "$BACKEND"
  /usr/bin/php artisan migrate --force --no-interaction
)
MIGRATION_STATUS=$?
set -e
MIGRATION_END="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
printf 'migration_end_utc=%s\n' "$MIGRATION_END"

if [ "$MIGRATION_STATUS" -ne 0 ]; then
  printf 'migration_command_status=FAIL\n'
  PARTIAL_MIGRATIONS_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations';" 2>/dev/null || printf 'UNAVAILABLE')"
  PARTIAL_TABLE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();' 2>/dev/null || printf 'UNAVAILABLE')"
  printf 'partial_migrations_table_count=%s\n' "$PARTIAL_MIGRATIONS_COUNT"
  printf 'partial_application_table_count=%s\n' "$PARTIAL_TABLE_COUNT"
  printf 'backend_env_symlink=present\n'
  printf 'backend_env_target=%s\n' "$ENV_FILE"
  printf 'STOP: migration failed; no rollback or retry was attempted\n'
  exit 40
fi
printf 'migration_command_status=PASS\n'

(
  cd "$BACKEND"
  /usr/bin/php artisan migrate:status --no-interaction
)
mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT migration FROM migrations ORDER BY migration;' > "$APPLIED_LIST"
APPLIED_COUNT="$(wc -l < "$APPLIED_LIST" | tr -d ' ')"
[ "$APPLIED_COUNT" = "$EXPECTED_MIGRATION_COUNT" ] || fail_stop 'applied migration count mismatch'
diff -u "$MIGRATION_LIST" "$APPLIED_LIST" >/dev/null || fail_stop 'migration ledger mismatch'
printf 'migrations_table=present\n'
printf 'applied_migration_count=%s\n' "$APPLIED_COUNT"
printf 'pending_migration_count=0\n'
printf 'migration_set_identity=PASS\n'

POST_TABLE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();')"
[ "$POST_TABLE_COUNT" -gt 0 ] || fail_stop 'post-migration application table count is zero'
printf 'post_migration_application_table_count=%s\n' "$POST_TABLE_COUNT"

BAD_ENGINE_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB');")"
[ "$BAD_ENGINE_COUNT" = '0' ] || fail_stop 'non-InnoDB table detected'
printf 'schema_engine_validation=PASS\n'

BAD_COLLATION_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND (TABLE_COLLATION IS NULL OR TABLE_COLLATION <> 'utf8mb4_unicode_ci');")"
[ "$BAD_COLLATION_COUNT" = '0' ] || fail_stop 'unexpected table collation detected'
printf 'schema_charset_validation=PASS\n'

FOREIGN_KEY_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE();')"
[ "$FOREIGN_KEY_COUNT" -gt 0 ] || fail_stop 'foreign keys are absent'
printf 'schema_foreign_key_validation=PASS\n'

IMPORTANT_INDEX_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ('machine_component_slot_uq','component_lifecycle_active_uq','component_lifecycle_request_uq','mc_excl_machine_slot_clear_uq');")"
[ "$IMPORTANT_INDEX_COUNT" -ge 4 ] || fail_stop 'required unique indexes are absent'
printf 'schema_index_validation=PASS\n'

BASELINE_COUNTER_COUNT="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM counter_types WHERE id = '00000000-0000-0000-0000-000000000001' AND code = 'total_impressions';")"
[ "$BASELINE_COUNTER_COUNT" = '1' ] || fail_stop 'required baseline counter type is missing'
printf 'schema_baseline_counter_type=present\n'

for TABLE in users accounts branches machines operational_people counter_readings purchases operational_incidents inventory_items component_lifecycles; do
  TABLE_EXISTS="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$TABLE';")"
  [ "$TABLE_EXISTS" = '1' ] || fail_stop "expected table missing: $TABLE"
done

OPERATIONAL_ROWS="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT (SELECT COUNT(*) FROM users) + (SELECT COUNT(*) FROM accounts) + (SELECT COUNT(*) FROM branches) + (SELECT COUNT(*) FROM machines) + (SELECT COUNT(*) FROM operational_people) + (SELECT COUNT(*) FROM counter_readings) + (SELECT COUNT(*) FROM purchases) + (SELECT COUNT(*) FROM operational_incidents) + (SELECT COUNT(*) FROM inventory_items) + (SELECT COUNT(*) FROM component_lifecycles);')"
[ "$OPERATIONAL_ROWS" = '0' ] || fail_stop 'unexpected operational/auth/import rows exist'

DB_IDENTITY_AFTER="$(mysql --defaults-extra-file="$MYSQL_OPT" --database="$EXPECTED_DB" --batch --skip-column-names --raw -e 'SELECT DATABASE();')"
[ "$DB_IDENTITY_AFTER" = "$EXPECTED_DB" ] || fail_stop 'post-migration database identity mismatch'

TOP_AFTER="$(find "$PUBLIC" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort | paste -sd, -)"
[ "$TOP_AFTER" = 'default.php,staging' ] || fail_stop 'public_html contents changed'
DEFAULT_SHA_AFTER="$(sha256sum "$PUBLIC/default.php" | awk '{print $1}')"
[ "$DEFAULT_SHA_AFTER" = "$EXPECTED_DEFAULT_SHA" ] || fail_stop 'default.php hash changed'
test ! -e "$RELEASE/.env" || fail_stop 'release root .env exists'
[ -L "$BACKEND/.env" ] || fail_stop 'backend .env symlink missing'
[ "$(readlink "$BACKEND/.env")" = "$ENV_FILE" ] || fail_stop 'backend .env target changed'
test ! -e "$ROOT/current" || fail_stop 'current symlink exists'

printf 'legacy_import=NOT_RUN\n'
printf 'auth_bootstrap=NOT_RUN\n'
printf 'operational_bootstrap=NOT_RUN\n'
printf 'imports=NOT_RUN\n'
printf 'backend_env_symlink=present\n'
printf 'backend_env_target=%s\n' "$ENV_FILE"
printf 'current_symlink=ABSENT\n'
printf 'release_root_env=ABSENT\n'
printf 'public_html_touched=NO\n'
printf 'default.php_sha_unchanged=YES\n'
printf 'CHECKPOINT_2B_6=PASS\n'
REMOTE_SCRIPT

LOCAL_SHA="$(shasum -a 256 "$LOCAL_REMOTE_SCRIPT" | awk '{print $1}')"
REMOTE_EXISTS="$(ssh -T -p "$SSH_PORT" "$REMOTE" "test -e '$REMOTE_SCRIPT' && printf YES || printf NO")"
[ "$REMOTE_EXISTS" = 'NO' ] || {
  printf 'STOP: remote checkpoint script already exists\n'
  exit 20
}

scp -P "$SSH_PORT" "$LOCAL_REMOTE_SCRIPT" "$REMOTE:$REMOTE_SCRIPT"
REMOTE_SHA="$(ssh -T -p "$SSH_PORT" "$REMOTE" "sha256sum '$REMOTE_SCRIPT' | cut -d' ' -f1")"
printf 'checkpoint_script_local_sha256=%s\n' "$LOCAL_SHA"
printf 'checkpoint_script_remote_sha256=%s\n' "$REMOTE_SHA"

if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
  ssh -T -p "$SSH_PORT" "$REMOTE" "rm -f '$REMOTE_SCRIPT'"
  printf 'STOP: transferred checkpoint script hash mismatch\n'
  exit 21
fi

ssh -T -p "$SSH_PORT" "$REMOTE" "chmod 700 '$REMOTE_SCRIPT' && test \"\$(stat -c '%a' '$REMOTE_SCRIPT')\" = '700'"
printf 'checkpoint_script_identity=PASS\n'
printf 'checkpoint_script_mode=700\n'

ssh -T -p "$SSH_PORT" "$REMOTE" "bash '$REMOTE_SCRIPT'"
