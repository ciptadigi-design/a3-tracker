#!/usr/bin/env bash
# Non-destructive Production DB backup validator.
#
# Classifies a database backup artifact as VALID or INVALID. Never mutates,
# deletes, or moves the artifact it inspects. Never prints database
# credentials (it does not need any — it only reads the dump file itself).
#
# Background: a naive `grep '^DB_PASSWORD=' .env | cut -d= -f2` mis-parses a
# quoted .env value, which corrupts the credential passed to mysqldump. If
# the caller does not check mysqldump's own exit code before piping into
# gzip, the result is a syntactically valid, empty gzip stream — file
# existence and "gzip -t" alone are NOT sufficient to prove a backup is
# usable. This script performs the fuller set of checks that catches that
# failure mode (and others) before any migration is allowed to proceed.
#
# Usage:
#   validate-db-backup.sh <path-to-backup.sql.gz-or-.sql> [required_table ...]
#
# With no required_table arguments, the project's default core-table list is
# used (see REQUIRED_TABLES below).
#
# Exit codes:
#   0  VALID
#   1  INVALID (reason printed to stdout, prefixed "INVALID:")
#   2  usage error
set -euo pipefail

MIN_SQL_BYTES=200      # anything smaller than this is definitionally not a real schema dump
MIN_CREATE_TABLE_COUNT=10

DEFAULT_REQUIRED_TABLES=(users accounts branches machines inventory_movements component_replacements counter_readings operational_people)

path="${1:-}"
if [ -z "$path" ]; then
  echo "usage: validate-db-backup.sh <path-to-backup.sql.gz-or-.sql> [required_table ...]" >&2
  exit 2
fi
shift || true
if [ "$#" -gt 0 ]; then
  required_tables=("$@")
else
  required_tables=("${DEFAULT_REQUIRED_TABLES[@]}")
fi

fail() {
  printf 'INVALID: %s\n' "$1"
  printf 'CLASSIFICATION=INVALID\n'
  printf 'PATH=%s\n' "$path"
  exit 1
}

file_size() {
  stat -c%s "$1" 2>/dev/null || stat -f%z "$1" 2>/dev/null
}

[ -e "$path" ] || fail "artifact does not exist at this path"
[ -f "$path" ] || fail "artifact is not a regular file"

size="$(file_size "$path")"
[ -n "$size" ] || fail "could not determine file size"
[ "$size" -gt 0 ] || fail "file is 0 bytes"

case "$path" in
  *.gz)
    gzip -t "$path" 2>/dev/null || fail "gzip integrity check failed (corrupt/truncated archive)"
    sql_bytes="$(gzip -dc "$path" | wc -c | tr -d '[:space:]')"
    dump_cmd=(gzip -dc "$path")
    ;;
  *)
    sql_bytes="$size"
    dump_cmd=(cat "$path")
    ;;
esac

[ "$sql_bytes" -gt 0 ] || fail "decompressed content is 0 bytes (classic signature of a failed mysqldump silently wrapped in a valid empty gzip stream — this is exactly the M2.13.1 / near-miss M2.15.2 failure mode)"
[ "$sql_bytes" -ge "$MIN_SQL_BYTES" ] || fail "decompressed SQL is only ${sql_bytes} bytes (< ${MIN_SQL_BYTES} byte floor) — too small to be a real schema dump"

tmp_sql="$(mktemp)"
trap 'rm -f "$tmp_sql"' EXIT
"${dump_cmd[@]}" > "$tmp_sql"

error_markers=(
  "mysqldump: Got error"
  "mysqldump: Error"
  "Access denied"
  "Unknown database"
  "ERROR 1045"
  "ERROR 1044"
  "ERROR 1049"
  "command not found"
  "Usage: mysqldump"
  "-uUSERNAME -pPASSWORD"
)
for marker in "${error_markers[@]}"; do
  if grep -qiF -- "$marker" "$tmp_sql"; then
    fail "dump content contains an error/usage marker instead of SQL: \"${marker}\""
  fi
done

create_table_count="$(grep -c -i '^CREATE TABLE' "$tmp_sql" || true)"
[ "$create_table_count" -ge "$MIN_CREATE_TABLE_COUNT" ] || fail "only ${create_table_count} CREATE TABLE statements found (expected >= ${MIN_CREATE_TABLE_COUNT})"

missing_tables=()
for t in "${required_tables[@]}"; do
  if ! grep -qiF -- "CREATE TABLE \`${t}\`" "$tmp_sql"; then
    missing_tables+=("$t")
  fi
done
if [ "${#missing_tables[@]}" -gt 0 ]; then
  fail "missing required tables: ${missing_tables[*]}"
fi

insert_statement_count="$(grep -c -i '^INSERT INTO' "$tmp_sql" || true)"

sha256="$(sha256sum "$path" 2>/dev/null | awk '{print $1}')"
if [ -z "$sha256" ]; then
  sha256="$(shasum -a 256 "$path" 2>/dev/null | awk '{print $1}')"
fi
[ -n "$sha256" ] || fail "could not calculate SHA256"

echo "CLASSIFICATION=VALID"
echo "PATH=$path"
echo "COMPRESSED_BYTES=$size"
echo "SQL_BYTES=$sql_bytes"
echo "CREATE_TABLE_COUNT=$create_table_count"
echo "INSERT_STATEMENT_COUNT=$insert_statement_count"
echo "REQUIRED_TABLES_PRESENT=${required_tables[*]}"
echo "SHA256=$sha256"
exit 0
