#!/usr/bin/env bash
set -Eeuo pipefail

# M2.12E operator-assisted Production Inventory HTTP 500 evidence capture.
#
# This script is strictly read-only. It does not upload a remote helper, create
# temporary files, invoke Artisan, bootstrap Laravel, connect to MariaDB, send
# HTTP requests, or modify Production. Run it immediately after reproducing the
# Inventory 500 so that the newest matching log records are unambiguous.

REMOTE='u777904340@145.223.108.179'
SSH_PORT='65002'
SSH_OPTIONS=(-T -o ControlMaster=no -o ControlPath=none -p "$SSH_PORT")

ssh "${SSH_OPTIONS[@]}" "$REMOTE" bash -s <<'REMOTE_SCRIPT'
set -Eeuo pipefail

EXPECTED_HOST='id-dci-web1761.main-hosting.eu'
EXPECTED_USER='u777904340'
EXPECTED_RELEASE_SHA='7f58533360805ced37b36242b6b67351e4aa5ca7'
ROOT='/home/u777904340/a3-production-app'
CURRENT="$ROOT/current"
EXPECTED_RELEASE="$ROOT/releases/$EXPECTED_RELEASE_SHA"
DOMAIN_ROOT='/home/u777904340/domains/a3.ciptagrafika.com'
ACCOUNT_ID='4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c'
TUPAREV_ID='94051ab9-235c-455f-b7ce-63f255cda3f6'
GRAHA_ID='f5c2c443-63a1-44ae-aec9-a683106572ad'

stop() {
  printf 'CHECKPOINT_2B_PRODUCTION_INVENTORY_500_DIAGNOSIS=READY\n'
  printf 'PRODUCTION_INVENTORY_EXCEPTION_CAPTURED=PENDING_OPERATOR\n'
  printf 'STOP_REASON=%s\n' "$1"
  printf 'PRODUCTION_MUTATION=NO\nPRODUCTION_DATABASE_MUTATION=NO\nSTOP\n'
  exit 78
}

redact_line() {
  sed -E \
    -e 's/(Authorization:[[:space:]]*Bearer)[[:space:]]+[^[:space:]]+/\1 [REDACTED]/Ig' \
    -e 's/((password|passwd|secret|token|cookie|session|app_key)[[:space:]]*[:=][[:space:]]*)[^,;[:space:]"}]+/\1[REDACTED]/Ig'
}

command -v hostname >/dev/null 2>&1 || stop 'hostname unavailable'
command -v php >/dev/null 2>&1 || stop 'php unavailable'
command -v readlink >/dev/null 2>&1 || stop 'readlink unavailable'
command -v find >/dev/null 2>&1 || stop 'find unavailable'
command -v sort >/dev/null 2>&1 || stop 'sort unavailable'
test "$(hostname)" = "$EXPECTED_HOST" || stop 'remote host mismatch'
test "$(whoami)" = "$EXPECTED_USER" || stop 'remote user mismatch'
test -L "$CURRENT" || stop 'Production current symlink missing'
CURRENT_REAL="$(readlink -f -- "$CURRENT")"
test "$CURRENT_REAL" = "$EXPECTED_RELEASE" || stop 'Production current release mismatch'
test -d "$CURRENT_REAL/backend" || stop 'Production backend missing'

LOGS_INPUT="$CURRENT_REAL/backend/storage/logs"
test -e "$LOGS_INPUT" || stop 'Laravel storage/logs path missing'
LOGS_REAL="$(readlink -f -- "$LOGS_INPUT")"
case "$LOGS_REAL" in
  "$ROOT"/*) ;;
  *) stop 'resolved Laravel logs path escaped private Production root' ;;
esac
test -d "$LOGS_REAL" || stop 'resolved Laravel logs path is not a directory'

LARAVEL_LOG="$({ find -L "$LOGS_REAL" -maxdepth 1 -type f -name 'laravel*.log' -printf '%T@|%p\n' 2>/dev/null || true; } | sort -t '|' -k1,1nr | sed -n '1{s/^[^|]*|//;p;}')"
test -n "$LARAVEL_LOG" || stop 'Laravel log file not found'
case "$LARAVEL_LOG" in "$LOGS_REAL"/*) ;; *) stop 'Laravel log path escaped resolved log directory' ;; esac

printf 'PRODUCTION_RELEASE_SHA=%s\n' "$EXPECTED_RELEASE_SHA"
printf 'LARAVEL_LOG_PATH=%s\n' "$LARAVEL_LOG"

EXCEPTION_OUTPUT="$(php -r '
function clean_value(string $value): string {
    $value = preg_replace("/(Authorization:\\s*Bearer)\\s+\\S+/i", "\\$1 [REDACTED]", $value) ?? "[REDACTED]";
    $value = preg_replace("/((?:password|passwd|secret|token|cookie|session|app_key)\\s*[:=]\\s*)[^,;\\s\\\"}]+/i", "\\$1[REDACTED]", $value) ?? "[REDACTED]";
    return trim((string) preg_replace("/\\s+/", " ", $value));
}
$path = $argv[1];
$release = $argv[2];
$raw = file_get_contents($path);
if ($raw === false) exit(20);
$blocks = preg_split("/(?=^\\[\\d{4}-\\d{2}-\\d{2}[^\\]]*\\]\\s+[^.\\s]+\\.ERROR:)/m", $raw, -1, PREG_SPLIT_NO_EMPTY);
$match = null;
for ($i = count($blocks) - 1; $i >= 0; $i--) {
    if (preg_match("/(InventoryController|accounts\\/[^\\s\\\"]+\\/branches\\/[^\\s\\\"]+\\/inventory|InventoryItem|inventory_items|inventory_locations|component_catalogs|RelationNotFoundException)/i", $blocks[$i])) {
        $match = $blocks[$i];
        break;
    }
}
if ($match === null) exit(21);
$timestamp = "UNKNOWN";
if (preg_match("/^\\[([^\\]]+)\\]/", $match, $m)) $timestamp = $m[1];
$class = "UNKNOWN";
$code = "NONE";
$message = "UNKNOWN";
if (preg_match("/\\[object\\] \\(([^(:\\r\\n]+)(?:\\(code:\\s*([^)]*)\\))?:\\s*(.+?)\\s+at\\s+\\/[^\\r\\n]+:\\d+\\)/s", $match, $m)) {
    $class = trim($m[1]);
    $code = trim($m[2] ?? "") !== "" ? trim($m[2]) : "NONE";
    $message = clean_value($m[3]);
} elseif (preg_match("/ERROR:\\s*([^\\r\\n{]+)/", $match, $m)) {
    $message = clean_value($m[1]);
}
$sqlstate = "NONE";
if (preg_match("/SQLSTATE\\[[^]]+\\](?:\\s*\\[[^]]+\\])?/", $match, $m)) $sqlstate = $m[0];
$frames = [];
if (preg_match_all("/^#\\d+\\s+([^\\r\\n]*\\/backend\\/app\\/[^\\r\\n]*)/m", $match, $m)) {
    foreach ($m[1] as $frame) {
        $frame = str_replace($release . "/backend/app", "<RELEASE>/backend/app", $frame);
        $frame = clean_value($frame);
        if (!in_array($frame, $frames, true)) $frames[] = $frame;
        if (count($frames) >= 12) break;
    }
}
echo "EXCEPTION_TIMESTAMP=" . clean_value($timestamp) . "\\n";
echo "EXCEPTION_CLASS=" . clean_value($class) . "\\n";
echo "EXCEPTION_CODE=" . clean_value($code) . "\\n";
echo "SQLSTATE=" . clean_value($sqlstate) . "\\n";
echo "EXCEPTION_MESSAGE=" . $message . "\\n";
echo "APPLICATION_FRAME_COUNT=" . count($frames) . "\\n";
foreach ($frames as $i => $frame) echo "APPLICATION_FRAME_" . ($i + 1) . "=" . $frame . "\\n";
' "$LARAVEL_LOG" "$CURRENT_REAL" 2>/dev/null)" || {
  status=$?
  if test "$status" -eq 21; then stop 'no Inventory-related exception block found in newest Laravel log'; fi
  stop 'safe Laravel exception parser failed'
}
printf '%s\n' "$EXCEPTION_OUTPUT" | redact_line

# Hostinger access-log locations vary by plan. Search only the Production
# domain tree and emit only method, request target, status, and known route IDs.
ACCESS_LOGS="$(
  find "$DOMAIN_ROOT" -maxdepth 4 -type f \
    \( -iname '*access*.log' -o -iname '*access*.log.gz' -o -iname 'access_log' -o -iname 'access_log.gz' \) \
    -printf '%T@|%p\n' 2>/dev/null | sort -t '|' -k1,1nr | sed 's/^[^|]*|//' | head -20
)"

if test -n "$ACCESS_LOGS"; then
  REQUEST_OUTPUT="$(printf '%s\n' "$ACCESS_LOGS" | php -r '
$last = null;
foreach (file("php://stdin", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $path) {
    $gz = str_ends_with($path, ".gz");
    $handle = $gz ? @gzopen($path, "rb") : @fopen($path, "rb");
    if (!$handle) continue;
    while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
        if (preg_match("#\\\"(GET|HEAD|POST|PUT|PATCH|DELETE)\\s+([^\\s\\\"]+)\\s+HTTP/[^\\\"]+\\\"\\s+(\\d{3})#", $line, $m)
            && (int) $m[3] === 500
            && preg_match("#^/api/v1/accounts/([0-9a-f-]{36})/branches/([0-9a-f-]{36})/inventory(?:\\?[^\\s]*)?$#i", $m[2], $ids)) {
            $last = [$m[1], $m[2], $m[3], strtolower($ids[1]), strtolower($ids[2])];
        }
    }
    $gz ? gzclose($handle) : fclose($handle);
    if ($last !== null) break;
}
if ($last === null) exit(21);
[$method, $target, $status, $account, $branch] = $last;
$query = parse_url($target, PHP_URL_QUERY);
echo "HTTP_METHOD=$method\\n";
echo "REQUEST_TARGET=$target\\n";
echo "ROUTE_TEMPLATE=/api/v1/accounts/{account}/branches/{branch}/inventory\\n";
echo "QUERY_PARAMETERS=" . ($query === null ? "NONE" : $query) . "\\n";
echo "HTTP_STATUS=$status\\n";
echo "REQUEST_ACCOUNT_ID=$account\\n";
echo "REQUEST_BRANCH_ID=$branch\\n";
' 2>/dev/null)" || true
else
  REQUEST_OUTPUT=''
fi

if test -n "$REQUEST_OUTPUT"; then
  printf '%s\n' "$REQUEST_OUTPUT" | redact_line
  REQUEST_ACCOUNT_ID="$(printf '%s\n' "$REQUEST_OUTPUT" | sed -n 's/^REQUEST_ACCOUNT_ID=//p')"
  REQUEST_BRANCH_ID="$(printf '%s\n' "$REQUEST_OUTPUT" | sed -n 's/^REQUEST_BRANCH_ID=//p')"
  if test "$REQUEST_ACCOUNT_ID" = "$ACCOUNT_ID"; then
    printf 'AUTHENTICATED_ACCOUNT_SCOPE=CIPTA_GRAFIKA\n'
  else
    printf 'AUTHENTICATED_ACCOUNT_SCOPE=UNEXPECTED_ACCOUNT_ID\n'
  fi
  case "$REQUEST_BRANCH_ID" in
    "$GRAHA_ID") printf 'SELECTED_BRANCH=GRAHA\nSELECTED_BRANCH_PRODUCTION_ID_MATCH=PASS\n' ;;
    "$TUPAREV_ID") printf 'SELECTED_BRANCH=TUPAREV\nSELECTED_BRANCH_PRODUCTION_ID_MATCH=PASS\n' ;;
    *) printf 'SELECTED_BRANCH=UNKNOWN\nSELECTED_BRANCH_PRODUCTION_ID_MATCH=FAIL\n' ;;
  esac
else
  printf 'HTTP_METHOD=PENDING_OPERATOR_NETWORK_COPY\n'
  printf 'REQUEST_TARGET=PENDING_OPERATOR_NETWORK_COPY\n'
  printf 'QUERY_PARAMETERS=PENDING_OPERATOR_NETWORK_COPY\n'
  printf 'AUTHENTICATED_ACCOUNT_SCOPE=PENDING_OPERATOR_NETWORK_COPY\n'
  printf 'SELECTED_BRANCH=PENDING_OPERATOR_NETWORK_COPY\n'
fi

printf 'ROUTE_CONTROLLER=App\\Http\\Controllers\\Api\\InventoryController@workspace\n'
printf 'PRODUCTION_INVENTORY_EXCEPTION_CAPTURED=PASS\n'
printf 'PRODUCTION_MUTATION=NO\n'
printf 'PRODUCTION_DATABASE_ACCESS=NO\n'
printf 'PRODUCTION_DATABASE_MUTATION=NO\n'
printf 'STOP\n'
REMOTE_SCRIPT
