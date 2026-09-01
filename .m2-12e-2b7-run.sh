#!/usr/bin/env bash
set -euo pipefail

REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
REMOTE_SCRIPT='/home/u777904340/a3-production-app/.checkpoint-2b7-master-bootstrap.sh'

LOCAL_REMOTE_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/m212e-2b7-remote.XXXXXX")"
cleanup_local() {
  rm -f -- "$LOCAL_REMOTE_SCRIPT"
}
trap cleanup_local EXIT
chmod 600 "$LOCAL_REMOTE_SCRIPT"

cat > "$LOCAL_REMOTE_SCRIPT" <<'REMOTE_SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
umask 077

SCRIPT_PATH="$0"
MIGRATION_LIST=''
APPLIED_LIST=''
BOOTSTRAP_PHP=''
MASTER_PASSWORD=''
MASTER_PASSWORD_CONFIRM=''

cleanup_remote() {
  unset MASTER_PASSWORD MASTER_PASSWORD_CONFIRM
  for FILE in "${MIGRATION_LIST:-}" "${APPLIED_LIST:-}" "${BOOTSTRAP_PHP:-}" "${SCRIPT_PATH:-}"; do
    if [ -n "$FILE" ]; then
      rm -f -- "$FILE"
    fi
  done
}
trap cleanup_remote EXIT

ROOT='/home/u777904340/a3-production-app'
RELEASE="$ROOT/releases/b69c7e125f083f52dc519f4a3cc3d401ba5a64b0"
BACKEND="$RELEASE/backend"
SHARED_ENV="$ROOT/shared/.env"
PUBLIC='/home/u777904340/domains/a3.ciptagrafika.com/public_html'
EXPECTED_DB='u777904340_a3production'
EXPECTED_DB_USER='u777904340_a3production@localhost'
EXPECTED_DEFAULT_SHA='aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45'
EXPECTED_ENV_TARGET='/home/u777904340/a3-production-app/shared/.env'

fail_stop() {
  printf 'STOP: %s\n' "$1"
  exit 1
}

printf '%s\n' '--- CHECKPOINT 2B-7 PRE-MUTATION AUDIT ---'
[ "$(hostname)" = 'id-dci-web1761.main-hosting.eu' ] || fail_stop 'hostname mismatch'
[ "$(whoami)" = 'u777904340' ] || fail_stop 'whoami mismatch'
test -d "$RELEASE" || fail_stop 'selected release missing'
test -d "$BACKEND" || fail_stop 'Laravel backend missing'
test -f "$BACKEND/artisan" || fail_stop 'artisan missing'
test -f "$BACKEND/vendor/autoload.php" || fail_stop 'vendor autoload missing'
test -f "$BACKEND/config/view.php" || fail_stop 'config view missing'
test -f "$BACKEND/composer.lock" || fail_stop 'composer lock missing'
test -f "$SHARED_ENV" || fail_stop 'shared env missing'
[ "$(stat -c '%a' "$SHARED_ENV")" = '600' ] || fail_stop 'shared env mode mismatch'
test ! -e "$RELEASE/.env" || fail_stop 'release root env exists'
[ -L "$BACKEND/.env" ] || fail_stop 'backend env is not a symlink'
[ "$(readlink "$BACKEND/.env")" = "$EXPECTED_ENV_TARGET" ] || fail_stop 'backend env target mismatch'
test ! -e "$ROOT/current" || fail_stop 'current symlink exists'

TOP_LEVEL="$(find "$PUBLIC" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort | paste -sd, -)"
[ "$TOP_LEVEL" = 'default.php,staging' ] || fail_stop 'public root contents mismatch'
DEFAULT_SHA="$(sha256sum "$PUBLIC/default.php" | awk '{print $1}')"
[ "$DEFAULT_SHA" = "$EXPECTED_DEFAULT_SHA" ] || fail_stop 'default.php SHA mismatch'

MIGRATION_LIST="$(mktemp)"
APPLIED_LIST="$(mktemp)"
BOOTSTRAP_PHP="$(mktemp)"
chmod 600 "$MIGRATION_LIST" "$APPLIED_LIST" "$BOOTSTRAP_PHP"

find "$BACKEND/database/migrations" -maxdepth 1 -type f -name '*.php' -printf '%f\n' | sed 's/\.php$//' | sort > "$MIGRATION_LIST"
[ "$(wc -l < "$MIGRATION_LIST" | tr -d ' ')" = '15' ] || fail_stop 'selected release migration count mismatch'

cat > "$BOOTSTRAP_PHP" <<'PHP'
<?php
declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const EXPECTED_DB = 'u777904340_a3production';
const EXPECTED_DB_USER = 'u777904340_a3production@localhost';
const MASTER_NAME = 'Imad Prakoso';
const MASTER_EMAIL = 'imadprakoso@gmail.com';
const MASTER_USERNAME = 'imadprakoso';
const ACCOUNT_CODE = 'CG';
const ACCOUNT_NAME = 'Cipta Grafika';
const BRANCH_CODE = 'CG-TUP';
const BRANCH_NAME = 'Tuparev';
const MANUFACTURER_CODE = 'konica_minolta';
const MANUFACTURER_NAME = 'Konica Minolta';
const MODEL_CODE = 'accuriopress_c1070';
const MODEL_NAME = 'AccurioPress C1070';
const MACHINE_CODE = 'CG-TUP-A3-01';
const MACHINE_NAME = 'Konica Minolta bizhub PRESS C1070/1070P';

function stop(string $message, int $code = 20): never
{
    fwrite(STDERR, "STOP: {$message}\n");
    exit($code);
}

function tableCount(string $table): int
{
    return (int) DB::table($table)->count();
}

function verifyDatabaseIdentity(): void
{
    $row = DB::selectOne('SELECT DATABASE() AS selected_database, CURRENT_USER() AS authenticated_user');
    if (($row->selected_database ?? null) !== EXPECTED_DB) stop('database identity mismatch');
    if (($row->authenticated_user ?? null) !== EXPECTED_DB_USER) stop('database user identity mismatch');
}

function assertOperationalIsolation(): void
{
    $tables = [
        'operational_people',
        'operational_person_branches',
        'counter_readings',
        'component_catalogs',
        'model_profiles',
        'model_profile_slots',
        'machine_components',
        'machine_component_exclusions',
        'component_lifecycles',
        'inventory_items',
        'inventory_locations',
        'inventory_suppliers',
        'inventory_movements',
        'fifo_layers',
        'fifo_allocations',
        'purchases',
        'purchase_lines',
        'receipts',
        'receipt_lines',
        'component_replacements',
        'operational_incidents',
        'personal_access_tokens',
        'sessions',
        'governance_audit_logs',
    ];
    foreach ($tables as $table) {
        if (tableCount($table) !== 0) stop("unexpected rows in {$table}");
    }
}

function plannedCounts(): array
{
    $tables = [
        'users',
        'accounts',
        'branches',
        'manufacturers',
        'machine_models',
        'machines',
        'account_memberships',
        'account_membership_branches',
        'platform_user_privileges',
    ];
    $counts = [];
    foreach ($tables as $table) $counts[$table] = tableCount($table);
    return $counts;
}

function isEmptyBootstrapState(array $counts): bool
{
    return array_sum($counts) === 0;
}

function canonicalState(): array
{
    $counts = plannedCounts();
    foreach ($counts as $table => $count) {
        if ($count !== 1) stop("non-canonical row count in {$table}");
    }

    $user = User::query()->firstOrFail();
    if ($user->name !== MASTER_NAME) stop('master display name mismatch');
    if ($user->email !== MASTER_EMAIL) stop('master email mismatch');
    if ($user->username !== MASTER_USERNAME) stop('master username mismatch');
    if ($user->status !== 'active') stop('master user status mismatch');
    if (!is_string($user->password) || $user->password === '') stop('master password hash absent');

    $account = Account::query()->firstOrFail();
    if ($account->code !== ACCOUNT_CODE) stop('account code mismatch');
    if ($account->name !== ACCOUNT_NAME) stop('account name mismatch');
    if ($account->default_timezone !== 'Asia/Jakarta') stop('account timezone mismatch');
    if ($account->default_currency !== 'IDR') stop('account currency mismatch');
    if ($account->status !== 'active') stop('account status mismatch');

    $branch = Branch::query()->firstOrFail();
    if ($branch->account_id !== $account->id) stop('branch account mismatch');
    if ($branch->code !== BRANCH_CODE) stop('branch code mismatch');
    if ($branch->name !== BRANCH_NAME) stop('branch name mismatch');
    if ($branch->timezone !== 'Asia/Jakarta') stop('branch timezone mismatch');
    if (!$branch->is_active) stop('branch status mismatch');

    $manufacturer = Manufacturer::query()->firstOrFail();
    if ($manufacturer->account_id !== null) stop('manufacturer scope mismatch');
    if ($manufacturer->code !== MANUFACTURER_CODE) stop('manufacturer code mismatch');
    if ($manufacturer->name !== MANUFACTURER_NAME) stop('manufacturer name mismatch');
    if (!$manufacturer->is_active) stop('manufacturer status mismatch');

    $model = MachineModel::query()->firstOrFail();
    if ($model->account_id !== null) stop('machine model scope mismatch');
    if ($model->manufacturer_id !== $manufacturer->id) stop('machine model manufacturer mismatch');
    if ($model->model_code !== MODEL_CODE) stop('machine model code mismatch');
    if ($model->name !== MODEL_NAME) stop('machine model name mismatch');
    if ($model->machine_category !== 'digital_a3') stop('machine model category mismatch');
    if ($model->color_capability !== 'color') stop('machine model color capability mismatch');
    if (!$model->is_active) stop('machine model status mismatch');

    $machine = Machine::query()->firstOrFail();
    if ($machine->account_id !== $account->id) stop('machine account mismatch');
    if ($machine->branch_id !== $branch->id) stop('machine branch mismatch');
    if ($machine->machine_model_id !== $model->id) stop('machine model relationship mismatch');
    if ($machine->machine_code !== MACHINE_CODE) stop('machine code mismatch');
    if ($machine->display_name !== MACHINE_NAME) stop('machine display name mismatch');
    if ($machine->status !== 'active') stop('machine status mismatch');
    if ($machine->timezone !== 'Asia/Jakarta') stop('machine timezone mismatch');

    $membership = AccountMembership::query()->firstOrFail();
    if ($membership->account_id !== $account->id) stop('membership account mismatch');
    if ($membership->user_id !== $user->id) stop('membership user mismatch');
    if ($membership->role !== 'owner') stop('membership role mismatch');
    if ($membership->status !== 'active') stop('membership status mismatch');
    if ($membership->accepted_at === null) stop('membership acceptance missing');

    $assignment = AccountMembershipBranch::query()->firstOrFail();
    if ($assignment->account_id !== $account->id) stop('branch assignment account mismatch');
    if ($assignment->membership_id !== $membership->id) stop('branch assignment membership mismatch');
    if ($assignment->branch_id !== $branch->id) stop('branch assignment branch mismatch');
    if (!$assignment->is_active) stop('branch assignment inactive');

    $privilege = DB::table('platform_user_privileges')->first();
    if (($privilege->user_id ?? null) !== $user->id) stop('platform privilege user mismatch');
    if (($privilege->role ?? null) !== 'superuser') stop('platform privilege role mismatch');
    if ((int) ($privilege->is_active ?? 0) !== 1) stop('platform privilege inactive');

    assertOperationalIsolation();
    return compact('user', 'account', 'branch', 'manufacturer', 'model', 'machine', 'membership', 'assignment');
}

function printIds(array $state): void
{
    printf("production_master_user_id=%s\n", $state['user']->id);
    printf("production_account_id=%s\n", $state['account']->id);
    printf("production_branch_id=%s\n", $state['branch']->id);
    printf("production_machine_model_id=%s\n", $state['model']->id);
    printf("production_machine_id=%s\n", $state['machine']->id);
}

function createRelationalBootstrap(string $password): string
{
    if ($password === '' || strlen($password) < 10 || strlen($password) > 128) {
        stop('master password must contain 10 to 128 bytes', 30);
    }

    return DB::transaction(function () use ($password): string {
        $user = User::create([
            'name' => MASTER_NAME,
            'email' => MASTER_EMAIL,
            'username' => MASTER_USERNAME,
            'password' => $password,
            'status' => 'active',
        ]);
        $account = Account::create([
            'code' => ACCOUNT_CODE,
            'name' => ACCOUNT_NAME,
            'default_timezone' => 'Asia/Jakarta',
            'default_currency' => 'IDR',
            'status' => 'active',
        ]);
        $branch = Branch::create([
            'account_id' => $account->id,
            'code' => BRANCH_CODE,
            'name' => BRANCH_NAME,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);
        $manufacturer = Manufacturer::create([
            'account_id' => null,
            'code' => MANUFACTURER_CODE,
            'name' => MANUFACTURER_NAME,
            'is_active' => true,
        ]);
        $model = MachineModel::create([
            'account_id' => null,
            'manufacturer_id' => $manufacturer->id,
            'model_code' => MODEL_CODE,
            'name' => MODEL_NAME,
            'machine_category' => 'digital_a3',
            'color_capability' => 'color',
            'is_active' => true,
        ]);
        Machine::create([
            'account_id' => $account->id,
            'branch_id' => $branch->id,
            'machine_model_id' => $model->id,
            'machine_code' => MACHINE_CODE,
            'display_name' => MACHINE_NAME,
            'status' => 'active',
            'timezone' => 'Asia/Jakarta',
        ]);
        $membership = AccountMembership::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'accepted_at' => now(),
        ]);
        AccountMembershipBranch::create([
            'account_id' => $account->id,
            'membership_id' => $membership->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        return (string) $user->id;
    }, 1);
}

verifyDatabaseIdentity();
$mode = $argv[1] ?? 'plan';

if ($mode === 'ledger') {
    foreach (DB::table('migrations')->orderBy('migration')->pluck('migration') as $migration) echo $migration, "\n";
    exit(0);
}

if ($mode === 'evidence') {
    foreach (plannedCounts() as $table => $count) printf("evidence_%s=%d\n", $table, $count);
    exit(0);
}

if ($mode === 'verify') {
    $state = canonicalState();
    printIds($state);
    echo "bootstrap_state=CANONICAL\n";
    exit(0);
}

$counts = plannedCounts();
assertOperationalIsolation();
if (!isEmptyBootstrapState($counts)) {
    $state = canonicalState();
    printIds($state);
    echo "bootstrap_state=EXISTING_CANONICAL\n";
    exit(0);
}

if ($mode === 'plan') {
    echo "bootstrap_state=EMPTY\n";
    echo "EXPECTED_NEW_RECORDS=users:1,accounts:1,branches:1,manufacturers:1,machine_models:1,machines:1,account_memberships:1,account_membership_branches:1,platform_user_privileges:1\n";
    exit(0);
}

if ($mode !== 'apply') stop('unsupported bootstrap mode');
$password = stream_get_contents(STDIN);
$userId = createRelationalBootstrap($password);
$password = str_repeat("\0", strlen($password));
unset($password);
printf("relational_bootstrap_user_id=%s\n", $userId);
echo "relational_bootstrap_status=PASS\n";
PHP

(
  cd "$BACKEND"
  /usr/bin/php "$BOOTSTRAP_PHP" ledger
) > "$APPLIED_LIST" || fail_stop 'migration ledger query failed'
diff -u "$MIGRATION_LIST" "$APPLIED_LIST" >/dev/null || fail_stop 'migration ledger identity mismatch'
printf 'migration_ledger=15/15\n'
printf 'pending_migrations=0\n'

PLAN_OUTPUT="$(cd "$BACKEND" && /usr/bin/php "$BOOTSTRAP_PHP" plan)" || fail_stop 'bootstrap planning gate failed'
printf '%s\n' "$PLAN_OUTPUT"

if printf '%s\n' "$PLAN_OUTPUT" | grep -qx 'bootstrap_state=EMPTY'; then
  printf 'Production master password: ' > /dev/tty
  IFS= read -r -s MASTER_PASSWORD < /dev/tty
  printf '\nConfirm Production master password: ' > /dev/tty
  IFS= read -r -s MASTER_PASSWORD_CONFIRM < /dev/tty
  printf '\n' > /dev/tty
  [ -n "$MASTER_PASSWORD" ] || fail_stop 'master password is empty'
  [ "$MASTER_PASSWORD" = "$MASTER_PASSWORD_CONFIRM" ] || fail_stop 'master password confirmation mismatch'

  set +e
  APPLY_OUTPUT="$(printf '%s' "$MASTER_PASSWORD" | (cd "$BACKEND" && /usr/bin/php "$BOOTSTRAP_PHP" apply))"
  APPLY_STATUS=$?
  set -e
  MASTER_PASSWORD=''
  MASTER_PASSWORD_CONFIRM=''
  unset MASTER_PASSWORD MASTER_PASSWORD_CONFIRM
  if [ "$APPLY_STATUS" -ne 0 ]; then
    printf 'relational_bootstrap_status=FAIL\n'
    (cd "$BACKEND" && /usr/bin/php "$BOOTSTRAP_PHP" evidence) || true
    fail_stop 'transactional relational bootstrap failed; no retry was attempted'
  fi
  printf '%s\n' "$APPLY_OUTPUT"
  MASTER_USER_ID="$(printf '%s\n' "$APPLY_OUTPUT" | sed -n 's/^relational_bootstrap_user_id=//p')"
  [ -n "$MASTER_USER_ID" ] || fail_stop 'master user ID was not returned'

  set +e
  (
    cd "$BACKEND"
    /usr/bin/php artisan platform:bootstrap-superuser "$MASTER_USER_ID" --confirm=GRANT_PLATFORM_SUPERUSER --no-interaction
  )
  PRIVILEGE_STATUS=$?
  set -e
  if [ "$PRIVILEGE_STATUS" -ne 0 ]; then
    printf 'platform_superuser_status=FAIL\n'
    (cd "$BACKEND" && /usr/bin/php "$BOOTSTRAP_PHP" evidence) || true
    fail_stop 'platform privilege grant failed; no retry or cleanup was attempted'
  fi
  printf 'platform_superuser_status=PASS\n'
elif printf '%s\n' "$PLAN_OUTPUT" | grep -qx 'bootstrap_state=EXISTING_CANONICAL'; then
  printf 'credential_prompt=SKIPPED_EXISTING_CANONICAL\n'
  printf 'platform_superuser_status=EXISTING_CANONICAL\n'
else
  fail_stop 'unexpected bootstrap planning state'
fi

VERIFY_OUTPUT="$(cd "$BACKEND" && /usr/bin/php "$BOOTSTRAP_PHP" verify)" || fail_stop 'post-bootstrap canonical verification failed'
printf '%s\n' "$VERIFY_OUTPUT"

TOP_LEVEL_AFTER="$(find "$PUBLIC" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort | paste -sd, -)"
[ "$TOP_LEVEL_AFTER" = 'default.php,staging' ] || fail_stop 'public root contents changed'
DEFAULT_SHA_AFTER="$(sha256sum "$PUBLIC/default.php" | awk '{print $1}')"
[ "$DEFAULT_SHA_AFTER" = "$EXPECTED_DEFAULT_SHA" ] || fail_stop 'default.php SHA changed'
[ -L "$BACKEND/.env" ] || fail_stop 'backend env symlink missing'
[ "$(readlink "$BACKEND/.env")" = "$EXPECTED_ENV_TARGET" ] || fail_stop 'backend env target changed'
test ! -e "$RELEASE/.env" || fail_stop 'release root env exists'
test ! -e "$ROOT/current" || fail_stop 'current symlink exists'

printf 'planned_writeset=users:1,accounts:1,branches:1,manufacturers:1,machine_models:1,machines:1,account_memberships:1,account_membership_branches:1,platform_user_privileges:1\n'
printf 'operational_people=0\n'
printf 'counter_readings=0\n'
printf 'component_lifecycles=0\n'
printf 'purchases=0\n'
printf 'operational_incidents=0\n'
printf 'inventory_operational_evidence=UNTOUCHED\n'
printf 'legacy_import=NOT_RUN\n'
printf 'graha_records=0\n'
printf 'backend_env_symlink=present\n'
printf 'current_symlink=ABSENT\n'
printf 'public_html_touched=NO\n'
printf 'default.php_sha_unchanged=YES\n'
printf 'CHECKPOINT_2B_7=PASS\n'
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

ssh -t -p "$SSH_PORT" "$REMOTE" "bash '$REMOTE_SCRIPT'"
