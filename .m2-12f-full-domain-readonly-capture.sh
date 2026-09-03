#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Captures both M2.12F sources into a private local evidence directory.
# It performs Supabase GET requests and MariaDB SELECT statements inside a
# READ ONLY transaction. It contains no apply/import/migrate/seed commands.

EXPECTED_PARENT_SHA='7f58533360805ced37b36242b6b67351e4aa5ca7'
REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
SSH_OPTIONS=(-T -o ControlMaster=no -o ControlPath=none -p "$SSH_PORT")

test "${1:-}" = '--capture-read-only' || {
  printf 'Usage: NEW_SUPABASE_URL=... NEW_SUPABASE_API_KEY=... NEW_SUPABASE_BEARER_TOKEN=... %s --capture-read-only\n' "$0" >&2
  exit 64
}
for name in awk chmod date git mkdir node php sha256sum ssh; do command -v "$name" >/dev/null 2>&1 || { printf 'STOP_REASON=missing command: %s\nSTOP\n' "$name" >&2; exit 78; }; done
test -n "${NEW_SUPABASE_URL:-}" && test -n "${NEW_SUPABASE_API_KEY:-}" && test -n "${NEW_SUPABASE_BEARER_TOKEN:-}" || {
  printf 'STOP_REASON=authenticated newer-Supabase read-only environment is required; values are never printed\nSTOP\n' >&2
  exit 78
}

REPO="$(git rev-parse --show-toplevel)"
cd "$REPO"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
EVIDENCE_ROOT="$REPO/.migration-private/m2-12f"
EVIDENCE="$EVIDENCE_ROOT/$STAMP"
test ! -e "$EVIDENCE" || { printf 'STOP_REASON=evidence path collision\nSTOP\n' >&2; exit 78; }
mkdir -p "$EVIDENCE_ROOT"
mkdir "$EVIDENCE"
chmod 700 "$EVIDENCE_ROOT" "$EVIDENCE"
SUPABASE_CAPTURE="$EVIDENCE/new-supabase.json"
PRODUCTION_CAPTURE="$EVIDENCE/production-mariadb.json"

node scripts/migration/m2-12f-capture-new-supabase.mjs "$SUPABASE_CAPTURE"

ssh "${SSH_OPTIONS[@]}" "$REMOTE" bash -s -- "$EXPECTED_PARENT_SHA" > "$PRODUCTION_CAPTURE" <<'REMOTE_CAPTURE'
set -Eeuo pipefail

EXPECTED_SHA="$1"
EXPECTED_HOST='id-dci-web1761.main-hosting.eu'
EXPECTED_USER='u777904340'
EXPECTED_DATABASE='u777904340_a3production'
ROOT='/home/u777904340/a3-production-app'
CURRENT="$ROOT/current"

stop() { printf 'STOP_REASON=%s\n' "$1" >&2; exit 78; }
test "$(hostname)" = "$EXPECTED_HOST" && test "$(whoami)" = "$EXPECTED_USER" || stop 'remote identity mismatch'
test -L "$CURRENT" && test "$(readlink -f -- "$CURRENT")" = "$ROOT/releases/$EXPECTED_SHA" || stop 'Production release target mismatch'
ENV_FILE="$(readlink -f -- "$CURRENT/backend/.env")"
case "$ENV_FILE" in "$ROOT/shared"/*) ;; *) stop 'Production environment escaped shared private root' ;; esac

php -r '
function fail(string $message): never { fwrite(STDERR,"STOP_REASON=".preg_replace("/[^A-Za-z0-9 ._:-]/","?",$message)."\n"); exit(78); }
function envFile(string $path): array {
  $result=[]; foreach (file($path,FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line=trim($line); if ($line==="" || str_starts_with($line,"#") || !preg_match("/^([A-Z0-9_]+)=(.*)$/",$line,$m)) continue;
    $value=trim($m[2]); if (strlen($value)>=2 && (($value[0]==="\"" && $value[-1]==="\"") || ($value[0]==="\047" && $value[-1]==="\047"))) $value=substr($value,1,-1);
    else $value=preg_replace("/\s+#.*$/","",$value); $result[$m[1]]=$value;
  } return $result;
}
$env=envFile($argv[1]); foreach (["DB_HOST","DB_DATABASE","DB_USERNAME","DB_PASSWORD"] as $key) if (($env[$key] ?? "")==="") fail("missing ".$key);
if ($env["DB_DATABASE"] !== $argv[2]) fail("Production database identity mismatch");
$pdo=new PDO("mysql:host={$env["DB_HOST"]};port=".($env["DB_PORT"] ?? "3306").";dbname={$env["DB_DATABASE"]};charset=utf8mb4",$env["DB_USERNAME"],$env["DB_PASSWORD"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET SESSION TRANSACTION READ ONLY"); $pdo->beginTransaction();
if ($pdo->query("SELECT DATABASE()")->fetchColumn() !== $argv[2]) fail("connected database identity mismatch");
$tables=["accounts","branches","manufacturers","machine_models","machines","component_catalogs","model_profiles","model_profile_slots","machine_components","component_lifecycles","component_replacements","counter_types","counter_readings","operational_people","operational_person_branches","inventory_locations","inventory_items","inventory_suppliers","purchases","purchase_lines","receipts","receipt_lines","inventory_movements","fifo_layers","fifo_allocations","operational_incidents","account_operational_permissions","machine_selling_prices","machine_operating_costs"];
$captured=[]; foreach ($tables as $table) $captured[$table]=$pdo->query("SELECT * FROM `".$table."`")->fetchAll();
$userCount=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$pdo->rollBack();
echo json_encode(["checkpoint"=>"M2.12F","source"=>"HOSTINGER_MARIADB_PRODUCTION","database"=>$argv[2],"release_sha"=>$argv[3],"captured_at"=>gmdate("c"),"transaction"=>"READ_ONLY_ROLLED_BACK","tables"=>$captured,"auth"=>["policy"=>"PRODUCTION_LOCAL_ONLY","user_count"=>$userCount,"rows_captured"=>false]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
' "$ENV_FILE" "$EXPECTED_DATABASE" "$EXPECTED_SHA"
REMOTE_CAPTURE
chmod 600 "$SUPABASE_CAPTURE" "$PRODUCTION_CAPTURE"

php -r 'foreach (array_slice($argv,1) as $path) { $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR); if (($data["checkpoint"] ?? null) !== "M2.12F") exit(20); }' "$SUPABASE_CAPTURE" "$PRODUCTION_CAPTURE"
printf 'M2_12F_READ_ONLY_CAPTURE=PASS\n'
printf 'NEW_SUPABASE_CAPTURE=%s\nPRODUCTION_MARIADB_CAPTURE=%s\n' "$SUPABASE_CAPTURE" "$PRODUCTION_CAPTURE"
printf 'NEW_SUPABASE_CAPTURE_SHA256=%s\n' "$(sha256sum "$SUPABASE_CAPTURE" | awk '{print $1}')"
printf 'PRODUCTION_MARIADB_CAPTURE_SHA256=%s\n' "$(sha256sum "$PRODUCTION_CAPTURE" | awk '{print $1}')"
printf 'NEW_SUPABASE_HTTP_METHODS=GET_ONLY\nPRODUCTION_DATABASE_TRANSACTION=READ_ONLY_ROLLED_BACK\n'
printf 'PRODUCTION_MUTATION=NO\nPRODUCTION_DATABASE_MUTATION=NO\nPUBLIC_HTML_MUTATION=NO\nSTOP\n'
