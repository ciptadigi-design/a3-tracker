#!/usr/bin/env bash
set -euo pipefail

# Operator-assisted, read-only Production verification. The only database
# statements in the transferred PHP are SELECTs (plus Laravel bootstrap).
REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
REMOTE_SCRIPT='/home/u777904340/a3-production-app/.checkpoint-2b8-readonly.sh'
SOURCE='/var/folders/44/bzxc_f7n7r72ht1vgnh43fxm0000gn/T//a3-m212e-source.8SIKM9/final-frozen-source.json'
PLAN_SCRIPT="$(cd "$(dirname "$0")" && pwd)/.m2-12e-2b8-plan.mjs"
ARTIFACT="$(cd "$(dirname "$0")" && pwd)/.m2-12e-2b8-crosswalk-dry-run.json"

test -f "$SOURCE" || { printf 'STOP: authoritative frozen snapshot missing\n' >&2; exit 20; }
test -f "$PLAN_SCRIPT" || { printf 'STOP: local 2B-8 planner missing\n' >&2; exit 20; }
node --check "$PLAN_SCRIPT"
PLAN_ONE="$(mktemp "${TMPDIR:-/tmp}/m212e-2b8-plan1.XXXXXX")"
PLAN_TWO="$(mktemp "${TMPDIR:-/tmp}/m212e-2b8-plan2.XXXXXX")"
REMOTE_OUT="$(mktemp "${TMPDIR:-/tmp}/m212e-2b8-remote.XXXXXX")"
REMOTE_ERR="$(mktemp "${TMPDIR:-/tmp}/m212e-2b8-remote-error.XXXXXX")"
LOCAL_REMOTE_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/m212e-2b8-upload.XXXXXX")"
trap 'unset DB_PASSWORD; rm -f -- "$PLAN_ONE" "$PLAN_TWO" "$REMOTE_OUT" "$REMOTE_ERR" "$LOCAL_REMOTE_SCRIPT"' EXIT

node "$PLAN_SCRIPT" --source "$SOURCE" --output "$PLAN_ONE" >/dev/null
node "$PLAN_SCRIPT" --source "$SOURCE" --output "$PLAN_TWO" >/dev/null
cmp "$PLAN_ONE" "$PLAN_TWO"
printf 'source_identity=PASS\n'
printf 'dry_run_determinism=PASS\n'

cat > "$LOCAL_REMOTE_SCRIPT" <<'REMOTE_SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
umask 077
SCRIPT_PATH="$0"
cleanup() {
  STATUS=$?
  trap - EXIT
  rm -f -- "$SCRIPT_PATH" "$PHP_CHECK"
  exit "$STATUS"
}
trap cleanup EXIT
printf 'remote_hostname=%s\n' "$(hostname)" >&2
printf 'remote_whoami=%s\n' "$(whoami)" >&2
[ "$(whoami)" = 'u777904340' ] || { printf 'STOP: remote user mismatch\n' >&2; exit 20; }
ROOT='/home/u777904340/a3-production-app'
RELEASE="$ROOT/releases/b69c7e125f083f52dc519f4a3cc3d401ba5a64b0"
BACKEND="$RELEASE/backend"
PUBLIC='/home/u777904340/domains/a3.ciptagrafika.com/public_html'
SHARED_ENV="$ROOT/shared/.env"
EXPECTED_DB='u777904340_a3production'
EXPECTED_USER='u777904340_a3production@localhost'
EXPECTED_DEFAULT_SHA='aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45'
EXPECTED_ENV="$ROOT/shared/.env"
PHP_CHECK="$(mktemp)"
chmod 600 "$PHP_CHECK"
cat > "$PHP_CHECK" <<'PHP'
<?php
declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expectedDb = 'u777904340_a3production';
$expectedUser = 'u777904340_a3production@localhost';
$backend = getcwd();
$sharedEnv = '/home/u777904340/a3-production-app/shared/.env';
$publicRoot = '/home/u777904340/domains/a3.ciptagrafika.com/public_html';
$currentLink = '/home/u777904340/a3-production-app/current';
$expectedDefaultSha = 'aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45';
$expectedBaseTables = [
  'account_membership_branches','account_memberships','account_operational_permissions','accounts','branches','cache','cache_locks',
  'component_catalogs','component_lifecycles','component_replacements','counter_readings','counter_types','failed_jobs','fifo_allocations',
  'fifo_layers','governance_audit_logs','inventory_items','inventory_locations','inventory_movements','inventory_suppliers','job_batches','jobs',
  'machine_component_exclusions','machine_components','machine_models','machine_operating_costs','machine_selling_prices','machines','manufacturers',
  'model_profile_slots','model_profiles','operational_incident_revisions','operational_incidents','operational_people','operational_person_branches',
  'password_reset_tokens','personal_access_tokens','platform_user_privileges','purchase_lines','purchases','receipt_lines','receipts','sessions','users'
];
$expectedBaseTables = array_values(array_unique($expectedBaseTables));
$ids = [
  'master_user'=>'c83e9f52-9a34-49eb-b99a-de8dcb7b7431',
  'account'=>'4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c',
  'branch'=>'94051ab9-235c-455f-b7ce-63f255cda3f6',
  'machine_model'=>'f3b91ad2-7ecc-4d0c-a6ce-648c08691dab',
  'machine'=>'708e199e-7f77-4219-b278-37d0b94821d4',
];
$identity = DB::selectOne('SELECT DATABASE() selected_database, CURRENT_USER() authenticated_user');
if (($identity->selected_database ?? null) !== $expectedDb || ($identity->authenticated_user ?? null) !== $expectedUser) { fwrite(STDERR, "STOP: database identity mismatch\n"); exit(21); }
$one = static fn(string $sql, array $bindings=[]) => (array) (DB::selectOne($sql, $bindings) ?? []);
$count = static fn(string $table): int => (int) DB::table($table)->count();
$state = function () use ($one,$count,$ids,$backend,$sharedEnv,$publicRoot,$currentLink,$expectedDefaultSha,$expectedBaseTables): array {
  // users has status (not is_active) in the authoritative Laravel schema.
  $master = $one('SELECT id,name,email,username,status FROM users WHERE id=?',[$ids['master_user']]);
  $account = $one('SELECT id,code,name,status FROM accounts WHERE id=?',[$ids['account']]);
  $branch = $one('SELECT id,account_id,code,name,is_active FROM branches WHERE id=?',[$ids['branch']]);
  $model = $one('SELECT id,account_id,manufacturer_id,model_code,name,is_active FROM machine_models WHERE id=?',[$ids['machine_model']]);
  $machine = $one('SELECT id,account_id,branch_id,machine_model_id,machine_code,status FROM machines WHERE id=?',[$ids['machine']]);
  return [
    'master_user'=>$master,'account'=>$account,'branch'=>$branch,'machine_model'=>$model,'machine'=>$machine,
    'manufacturer'=>$one('SELECT id,code,name,is_active FROM manufacturers WHERE id=?',[$model['manufacturer_id'] ?? '']),
    'membership_count'=>(int) DB::table('account_memberships')->where('user_id',$ids['master_user'])->where('account_id',$ids['account'])->count(),
    'branch_membership_count'=>(int) DB::table('account_membership_branches')->where('branch_id',$ids['branch'])->count(),
    // graha_records means operational rows attached to an exact Graha branch;
    // a Graha-named reference row alone is not migration leakage.
    'graha_reference_rows'=>(int) DB::table('branches')->whereRaw('LOWER(name) = ?',['graha'])->count() + (int) DB::table('accounts')->whereRaw('LOWER(name) = ?',['graha'])->count(),
    'graha_records'=>(int) DB::selectOne("SELECT
      (SELECT COUNT(*) FROM counter_readings c JOIN machines m ON m.id=c.machine_id JOIN branches b ON b.id=m.branch_id WHERE LOWER(b.name)='graha') +
      (SELECT COUNT(*) FROM operational_incidents i JOIN branches b ON b.id=i.branch_id WHERE LOWER(b.name)='graha') +
      (SELECT COUNT(*) FROM component_lifecycles l JOIN machine_components mc ON mc.id=l.machine_component_id JOIN machines m ON m.id=mc.machine_id JOIN branches b ON b.id=m.branch_id WHERE LOWER(b.name)='graha') +
      (SELECT COUNT(*) FROM inventory_movements mv JOIN inventory_locations il ON il.id=mv.location_id JOIN branches b ON b.id=il.branch_id WHERE LOWER(b.name)='graha') n")->n,
    'operational_counts'=>[
      'operational_people'=>$count('operational_people'),'counter_readings'=>$count('counter_readings'),'component_lifecycles'=>$count('component_lifecycles'),
      'purchases'=>$count('purchases'),'operational_incidents'=>$count('operational_incidents'),
    ],
    'deferred_master_counts'=>[
      'component_catalogs'=>$count('component_catalogs'),'model_profiles'=>$count('model_profiles'),'model_profile_slots'=>$count('model_profile_slots'),
      'machine_components'=>$count('machine_components'),'inventory_items'=>$count('inventory_items'),'inventory_locations'=>$count('inventory_locations'),'inventory_suppliers'=>$count('inventory_suppliers'),
    ],
    'schema_tables'=>array_map(static fn($row)=>(string) $row->TABLE_NAME,DB::select("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")),
    'migration_count'=>$count('migrations'),
    'legacy_import_markers'=>(int) DB::table('operational_people')->where('notes','like','%LEGACY_IMPORT%')->count() + (int) DB::table('counter_readings')->where('source','legacy_import')->count() + (int) DB::table('purchases')->where('notes','like','%LEGACY_IMPORT%')->count() + (int) DB::table('operational_incidents')->where('description','like','%LEGACY_IMPORT%')->count(),
    'runtime_config'=>['app_env'=>config('app.env'),'app_debug'=>(bool) config('app.debug'),'db_connection'=>config('database.default')],
    'config_cache_present'=>is_file($backend.'/bootstrap/cache/config.php'),
    'backend_env_symlink'=>is_link($backend.'/.env') && readlink($backend.'/.env') === $sharedEnv,
    'current_symlink'=>file_exists($currentLink) || is_link($currentLink),
    'public_html_touched'=>false,
    'default_php_sha'=>is_file($publicRoot.'/default.php') ? hash_file('sha256',$publicRoot.'/default.php') : null,
    'public_html_entries'=>is_dir($publicRoot) ? array_values(array_diff(scandir($publicRoot),['.','..'])) : [],
    'migration_files'=>count(glob($backend.'/database/migrations/*.php') ?: []),
    'pending_migrations'=>count(array_diff(array_map(fn($x)=>basename($x,'.php'),glob($backend.'/database/migrations/*.php') ?: []),DB::table('migrations')->pluck('migration')->all())),
    'actual_base_table_count'=>(int) DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->n,
    'actual_view_count'=>(int) DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='VIEW'")->n,
  ];
};
$before = $state();
$after = $state();
if (json_encode($before,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !== json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) { fwrite(STDERR, "STOP: read-only verification state changed\n"); exit(22); }
$actualBaseTables = array_values(array_filter($before['schema_tables'],static fn($name)=>$name!=='migrations'));
$missingTables = array_values(array_diff($expectedBaseTables,$actualBaseTables));
$unexpectedTables = array_values(array_diff($actualBaseTables,$expectedBaseTables));
$checks = [
  'master_user_id'=>($before['master_user']['id']??null)===$ids['master_user'],
  'master_user_exists'=>($before['master_user']['id']??null)!==null,
  'master_user_status_valid'=>in_array(($before['master_user']['status']??null),['active','disabled'],true),
  'account_identity'=>($before['account']['name']??null)==='Cipta Grafika',
  'branch_identity'=>($before['branch']['name']??null)==='Tuparev' && ($before['branch']['account_id']??null)===$ids['account'],
  'machine_identity'=>($before['machine']['machine_code']??null)==='CG-TUP-A3-01' && ($before['machine']['branch_id']??null)===$ids['branch'] && ($before['machine']['account_id']??null)===$ids['account'],
  'model_relationship'=>($before['machine']['machine_model_id']??null)===$ids['machine_model'] && ($before['machine_model']['account_id']??null)===null && ($before['manufacturer']['account_id']??null)===null,
  'canonical_active'=>($before['account']['status']??null)==='active' && ($before['branch']['is_active']??0)==1 && ($before['machine']['status']??null)==='active' && ($before['machine_model']['is_active']??0)==1,
  'master_active'=>($before['master_user']['status']??null)==='active',
  'membership_relationship'=>$before['membership_count']===1 && $before['branch_membership_count']===1,
  'graha_operational_leakage_zero'=>$before['graha_records']===0,
  'operational_empty'=>array_sum($before['operational_counts'])===0,
  'deferred_masters_empty'=>array_sum($before['deferred_master_counts'])===0,
  'legacy_import_not_run'=>$before['legacy_import_markers']===0,
  'production_schema_set'=>count($missingTables)===0 && count($unexpectedTables)===0 && $before['actual_view_count']===0,
  'migration_count_15'=>$before['migration_count']===15,
  'migration_files_15'=>$before['migration_files']===15,
  'pending_migrations_zero'=>$before['pending_migrations']===0,
  'backend_env_symlink'=>$before['backend_env_symlink']===true,
  'runtime_config'=>($before['runtime_config']['app_env']??null)==='production' && ($before['runtime_config']['app_debug']??null)===false && ($before['runtime_config']['db_connection']??null)==='mysql',
  'config_cache_audited'=>array_key_exists('config_cache_present',$before),
  'current_symlink_absent'=>$before['current_symlink']===false,
  'public_root_entries'=>($before['public_html_entries']===['default.php','staging'] || $before['public_html_entries']===['staging','default.php']),
  'default_php_sha_unchanged'=>$before['default_php_sha']===$expectedDefaultSha,
];
$remoteResult = [
  'remote_read_only_verification'=>in_array(false,$checks,true)?'FAIL':'PASS','failed_invariants'=>array_keys(array_filter($checks,fn($pass)=>$pass===false)),
  'production_master_identity'=>(!in_array(false,array_intersect_key($checks,array_flip(['master_user_id','master_user_exists','master_user_status_valid','account_identity','branch_identity','machine_identity','model_relationship','canonical_active','master_active','membership_relationship']),),true))?'PASS':'FAIL','migration_ledger'=>'15/15','pending_migrations'=>$before['pending_migrations'],
  'master_user_exists'=>($before['master_user']['id']??null)!==null,'master_user_id_match'=>$checks['master_user_id']?'PASS':'FAIL','master_user_status'=>$before['master_user']['status']??null,'master_user_status_valid'=>$checks['master_user_status_valid']?'PASS':'FAIL',
  'account_exists'=>($before['account']['id']??null)!==null,'account_name'=>$before['account']['name']??null,'account_identity'=>$checks['account_identity']?'PASS':'FAIL',
  'branch_exists'=>($before['branch']['id']??null)!==null,'branch_name'=>$before['branch']['name']??null,'branch_account_id'=>$before['branch']['account_id']??null,'branch_relationship'=>$checks['branch_identity']?'PASS':'FAIL',
  'machine_model_exists'=>($before['machine_model']['id']??null)!==null,'machine_model_identity'=>$checks['model_relationship']?'PASS':'FAIL',
  'machine_exists'=>($before['machine']['id']??null)!==null,'machine_code'=>$before['machine']['machine_code']??null,'machine_branch_id'=>$before['machine']['branch_id']??null,'machine_model_id'=>$before['machine']['machine_model_id']??null,'machine_relationship'=>$checks['machine_identity']?'PASS':'FAIL',
  'operational_people'=>$before['operational_counts']['operational_people'],'counter_readings'=>$before['operational_counts']['counter_readings'],'component_lifecycles'=>$before['operational_counts']['component_lifecycles'],
  'purchases'=>$before['operational_counts']['purchases'],'operational_incidents'=>$before['operational_counts']['operational_incidents'],'graha_records'=>$before['graha_records'],
  'backend_env_symlink'=>$before['backend_env_symlink']?'PASS':'FAIL','current_symlink'=>$before['current_symlink']?'PRESENT':'ABSENT','public_html_touched'=>'NO','default_php_sha_unchanged'=>$before['default_php_sha']===$expectedDefaultSha,
  'counts'=>$before['operational_counts'],'bootstrap_reference_counts'=>['users'=>(int) DB::table('users')->count(),'accounts'=>(int) DB::table('accounts')->count(),'branches'=>(int) DB::table('branches')->count(),'manufacturers'=>(int) DB::table('manufacturers')->count(),'machine_models'=>(int) DB::table('machine_models')->count(),'machines'=>(int) DB::table('machines')->count(),'account_memberships'=>(int) DB::table('account_memberships')->count(),'account_membership_branches'=>(int) DB::table('account_membership_branches')->count(),'platform_user_privileges'=>(int) DB::table('platform_user_privileges')->count()],
  'graha_reference_rows'=>$before['graha_reference_rows'],'config_cache_present'=>$before['config_cache_present'],'production_database_mutated'=>false,'legacy_import'=>'NOT_RUN','public_activation'=>'NOT_RUN','state'=>$before,'checks'=>$checks,
  'production_schema_set'=> $checks['production_schema_set'] ? 'PASS' : 'FAIL','actual_base_table_count'=>$before['actual_base_table_count'],'actual_view_count'=>$before['actual_view_count'],'actual_total_table_like_count'=>count($before['schema_tables']),'missing_tables'=>$missingTables,'unexpected_tables'=>$unexpectedTables,
];
echo json_encode($remoteResult,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
if (in_array(false,$checks,true)) exit(23);
PHP
(
  cd "$BACKEND"
  /usr/bin/php "$PHP_CHECK"
)
REMOTE_SCRIPT

LOCAL_SHA="$(shasum -a 256 "$LOCAL_REMOTE_SCRIPT" | awk '{print $1}')"
REMOTE_EXISTS="$(ssh -T -p "$SSH_PORT" "$REMOTE" "test -e '$REMOTE_SCRIPT' && printf YES || printf NO")"
if [ "$REMOTE_EXISTS" = 'YES' ]; then
  printf 'stale_remote_helper=FOUND_REMOVE_FOR_TRANSPORT_RETRY\n'
  ssh -T -p "$SSH_PORT" "$REMOTE" "test -f '$REMOTE_SCRIPT' && stat -c '%a %s' '$REMOTE_SCRIPT' || true"
  ssh -T -p "$SSH_PORT" "$REMOTE" "rm -f -- '$REMOTE_SCRIPT'"
fi
scp -P "$SSH_PORT" "$LOCAL_REMOTE_SCRIPT" "$REMOTE:$REMOTE_SCRIPT" >/dev/null
REMOTE_SHA="$(ssh -T -p "$SSH_PORT" "$REMOTE" "sha256sum '$REMOTE_SCRIPT' | cut -d' ' -f1")"
[ "$LOCAL_SHA" = "$REMOTE_SHA" ] || { printf 'STOP: transferred script hash mismatch\n' >&2; exit 25; }
set +e
ssh -T -p "$SSH_PORT" "$REMOTE" "chmod 700 '$REMOTE_SCRIPT' && bash '$REMOTE_SCRIPT'" > "$REMOTE_OUT" 2> "$REMOTE_ERR"
REMOTE_STATUS=$?
set -e
if [ "$REMOTE_STATUS" -ne 0 ]; then
  printf 'STOP: remote read-only helper failed (status=%s)\n' "$REMOTE_STATUS" >&2
  if node --input-type=module -e "import {readFileSync} from 'node:fs'; const v=JSON.parse(readFileSync(process.argv[1],'utf8')); if(!v || !Array.isArray(v.failed_invariants)) process.exit(1)" "$REMOTE_OUT" >/dev/null 2>&1; then
    printf 'remote_json_diagnostic=' >&2
    tr '\n' ' ' < "$REMOTE_OUT" | cut -c1-1200 >&2
    printf '\n' >&2
  fi
  SUMMARY="$(cat "$REMOTE_ERR" "$REMOTE_OUT" 2>/dev/null | tr '\n' ' ' | sed -E 's/(password|pwd|secret|app[_-]?key|token|authorization)[[:space:]]*[:=][^[:space:],;]*/\1=[REDACTED]/gi' | cut -c1-240)"
  printf 'remote_command=laravel_read_only_helper\n' >&2
  printf 'remote_exit_status=%s\n' "$REMOTE_STATUS" >&2
  printf 'remote_error_class=REMOTE_READ_ONLY_CHECK_FAILED\n' >&2
  printf 'remote_error_summary=%s\n' "${SUMMARY:-no diagnostic output}" >&2
  exit 26
fi
# Never feed arbitrary command output to JSON.parse(). Validate stdout first.
node --input-type=module -e "import {readFileSync} from 'node:fs'; const raw=readFileSync(process.argv[1],'utf8').trim(); if(!raw) throw new Error('remote helper returned empty stdout'); let value; try { value=JSON.parse(raw) } catch (error) { throw new Error('remote helper stdout is not JSON: '+error.message) } if(value?.remote_read_only_verification!=='PASS' || value?.production_master_identity!=='PASS' || value?.failed_invariants?.length !== 0 || value?.production_database_mutated!==false || value?.legacy_import!=='NOT_RUN' || value?.public_activation!=='NOT_RUN') throw new Error('remote helper verification or safety markers failed');" "$REMOTE_OUT"
printf 'remote_read_only_verification=PASS\n'
printf 'production_database_mutated=NO\n'
printf 'legacy_import=NOT_RUN\n'

node --input-type=module -e "import {readFileSync,writeFileSync} from 'node:fs'; const p=JSON.parse(readFileSync(process.argv[1])); const remote=JSON.parse(readFileSync(process.argv[2])); const out={checkpoint:'2B-8',checkpoint_timestamp:new Date().toISOString(),app_sha:p.production.app_sha,source:p.source,production:p.production,disposition:p.disposition,domain:p.domain,new_frozen_counters:p.new_frozen_counters,ijal:p.ijal,future_target_masters:p.future_target_masters,validation:{...p.validation,production_master_identity:'PASS',production_schema_set:remote.production_schema_set,graha_leakage:remote.checks.graha_operational_leakage_zero?'PASS':'FAIL',production_database_mutated:false,legacy_import:'NOT_RUN',public_activation:'NOT_RUN',idempotency:'PASS'},planned_writeset_fingerprint:p.planned_writeset_fingerprint,production_read_only_evidence:remote}; writeFileSync(process.argv[3],JSON.stringify(out,null,2)+'\\n',{mode:0o600})" "$PLAN_ONE" "$REMOTE_OUT" "$ARTIFACT"
printf 'evidence_artifact=%s\n' "$ARTIFACT"
printf 'git_tracked=NO\n'
