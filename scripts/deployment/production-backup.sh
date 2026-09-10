#!/usr/bin/env bash
# Safe Production backup creator. Run this ON the Production host, from the
# release directory being deployed (so `backend/artisan` and
# `backend/.env` — a symlink to shared/.env — are reachable).
#
# It creates a timestamped backup directory, dumps the database, backs up
# public_html, VALIDATES both immediately with the companion validator
# scripts, writes a machine-readable manifest, and only prints
# BACKUP_GATE=PASS if every step actually succeeded. It never returns success
# on a partially-completed or unverified backup.
#
# CRITICAL: a failed step never overwrites or deletes a prior valid backup,
# and this script never deletes any pre-existing backup directory. On
# failure it leaves whatever partial artifacts exist (clearly under the
# timestamped dir it created) for diagnosis, but always reports
# BACKUP_GATE=FAIL and exits non-zero.
#
# Usage:
#   production-backup.sh <milestone-slug> <backend-dir> <public-html-dir> <backups-root>
#
# Example:
#   production-backup.sh m2-17-1-pre-cutover \
#     /home/USER/a3-production-app/releases/<sha>/backend \
#     /home/USER/domains/example.com/public_html \
#     /home/USER/a3-production-app/backups
#
# Exit codes:
#   0  BACKUP_GATE=PASS
#   1  BACKUP_GATE=FAIL (see stderr / manifest for the failing step)
#   2  usage error
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

milestone="${1:-}"
backend_dir="${2:-}"
public_html_dir="${3:-}"
backups_root="${4:-}"

if [ -z "$milestone" ] || [ -z "$backend_dir" ] || [ -z "$public_html_dir" ] || [ -z "$backups_root" ]; then
  echo "usage: production-backup.sh <milestone-slug> <backend-dir> <public-html-dir> <backups-root>" >&2
  exit 2
fi

[ -f "$backend_dir/.env" ] || { echo "BACKUP_GATE=FAIL (no .env at $backend_dir/.env)"; exit 1; }
[ -d "$public_html_dir" ] || { echo "BACKUP_GATE=FAIL (no public_html at $public_html_dir)"; exit 1; }
[ -d "$backups_root" ] || { echo "BACKUP_GATE=FAIL (backups root does not exist: $backups_root)"; exit 1; }

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="${backups_root}/${milestone}-${timestamp}"

case "$backup_dir" in
  "${backups_root}"/*) ;;
  *) echo "BACKUP_GATE=FAIL (computed backup path escapes the approved backups root)"; exit 1 ;;
esac

old_umask="$(umask)"
umask 077
mkdir -m 700 "$backup_dir"
umask "$old_umask"

manifest="${backup_dir}/backup-manifest.json"
gate_result="FAIL"
fail_reason=""

cleanup() {
  rm -f "${backup_dir}/.mysql-defaults.cnf"
}
trap cleanup EXIT

step_fail() {
  fail_reason="$1"
  echo "BACKUP_GATE=FAIL ($fail_reason)"
  write_manifest
  exit 1
}

json_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

write_manifest() {
  {
    printf '{\n'
    printf '  "milestone": "%s",\n' "$(json_escape "$milestone")"
    printf '  "timestamp_utc": "%s",\n' "$timestamp"
    printf '  "backup_dir": "%s",\n' "$(json_escape "$backup_dir")"
    printf '  "db_filename": "%s",\n' "$(json_escape "${db_filename:-}")"
    printf '  "db_compressed_bytes": %s,\n' "${db_compressed_bytes:-null}"
    printf '  "db_sql_bytes": %s,\n' "${db_sql_bytes:-null}"
    printf '  "db_sha256": "%s",\n' "$(json_escape "${db_sha256:-}")"
    printf '  "db_create_table_count": %s,\n' "${db_create_table_count:-null}"
    printf '  "db_required_tables_ok": %s,\n' "${db_required_tables_ok:-false}"
    printf '  "db_validation_result": "%s",\n' "$(json_escape "${db_validation_result:-NOT_ATTEMPTED}")"
    printf '  "frontend_filename": "%s",\n' "$(json_escape "${frontend_filename:-}")"
    printf '  "frontend_bytes": %s,\n' "${frontend_bytes:-null}"
    printf '  "frontend_sha256": "%s",\n' "$(json_escape "${frontend_sha256:-}")"
    printf '  "frontend_file_count": %s,\n' "${frontend_file_count:-null}"
    printf '  "frontend_has_htaccess": %s,\n' "${frontend_has_htaccess:-false}"
    printf '  "frontend_validation_result": "%s",\n' "$(json_escape "${frontend_validation_result:-NOT_ATTEMPTED}")"
    printf '  "public_html_inode_before": "%s",\n' "$(json_escape "${public_html_inode_before:-}")"
    printf '  "htaccess_sha256_before": "%s",\n' "$(json_escape "${htaccess_sha256_before:-}")"
    printf '  "validation_timestamp_utc": "%s",\n' "$(date -u +%Y%m%dT%H%M%SZ)"
    printf '  "backup_gate_result": "%s",\n' "$(json_escape "$gate_result")"
    printf '  "fail_reason": "%s"\n' "$(json_escape "$fail_reason")"
    printf '}\n'
  } > "$manifest"
  chmod 600 "$manifest"
}

# --- Record public_html identity before touching anything (Phase 2/13 of the cutover runbook) ---
public_html_inode_before="$(stat -c%i "$public_html_dir" 2>/dev/null || stat -f%i "$public_html_dir" 2>/dev/null || echo "")"
if [ -f "$public_html_dir/.htaccess" ]; then
  htaccess_sha256_before="$(sha256sum "$public_html_dir/.htaccess" 2>/dev/null | awk '{print $1}')"
fi

# --- DB backup: safe credential extraction, dump, gzip, validate ---
defaults_file="${backup_dir}/.mysql-defaults.cnf"
if ! defaults_output="$(php "${SCRIPT_DIR}/lib/mysql-defaults-from-env.php" "$backend_dir/.env" "$defaults_file")"; then
  step_fail "could not safely extract DB credentials from .env"
fi
db_database="$(printf '%s\n' "$defaults_output" | sed -n 's/^DB_DATABASE=//p')"
[ -n "$db_database" ] || step_fail "could not determine DB_DATABASE from .env"

db_filename="full-db.sql.gz"
db_path="${backup_dir}/${db_filename}"

set +e
mysqldump --defaults-extra-file="$defaults_file" --single-transaction --quick --routines --triggers --events \
  "$db_database" 2>"${backup_dir}/.mysqldump-stderr.log" | gzip -9 > "$db_path"
dump_pipe_status=("${PIPESTATUS[@]}")
set -e

rm -f "$defaults_file"

if [ "${dump_pipe_status[0]}" != "0" ]; then
  db_validation_result="DUMP_FAILED_EXIT_${dump_pipe_status[0]}"
  step_fail "mysqldump exited non-zero (${dump_pipe_status[0]}); see ${backup_dir}/.mysqldump-stderr.log for diagnosis (credentials are not present in that file)"
fi
if [ "${dump_pipe_status[1]}" != "0" ]; then
  db_validation_result="GZIP_FAILED_EXIT_${dump_pipe_status[1]}"
  step_fail "gzip exited non-zero (${dump_pipe_status[1]})"
fi

if validator_output="$("${SCRIPT_DIR}/validate-db-backup.sh" "$db_path")"; then
  db_validation_result="VALID"
  db_compressed_bytes="$(printf '%s\n' "$validator_output" | sed -n 's/^COMPRESSED_BYTES=//p')"
  db_sql_bytes="$(printf '%s\n' "$validator_output" | sed -n 's/^SQL_BYTES=//p')"
  db_create_table_count="$(printf '%s\n' "$validator_output" | sed -n 's/^CREATE_TABLE_COUNT=//p')"
  db_sha256="$(printf '%s\n' "$validator_output" | sed -n 's/^SHA256=//p')"
  db_required_tables_ok=true
else
  db_validation_result="INVALID: $(printf '%s\n' "$validator_output" | sed -n 's/^INVALID: //p')"
  step_fail "DB backup failed validation — $db_validation_result"
fi
printf '%s\n' "$db_sha256" > "${backup_dir}/${db_filename}.sha256"
chmod 600 "$db_path" "${backup_dir}/${db_filename}.sha256"

# --- Frontend backup: tar public_html (never replacing the directory itself), validate ---
frontend_filename="public_html-pre-${milestone}.tar.gz"
frontend_path="${backup_dir}/${frontend_filename}"

if ! (cd "$public_html_dir" && tar --no-xattrs -czf "$frontend_path" .); then
  frontend_validation_result="TAR_FAILED"
  step_fail "frontend tar creation failed"
fi
chmod 600 "$frontend_path"

if validator_output="$("${SCRIPT_DIR}/validate-frontend-backup.sh" "$frontend_path")"; then
  frontend_validation_result="VALID"
  frontend_bytes="$(printf '%s\n' "$validator_output" | sed -n 's/^ARCHIVE_BYTES=//p')"
  frontend_file_count="$(printf '%s\n' "$validator_output" | sed -n 's/^FILE_COUNT=//p')"
  frontend_sha256="$(printf '%s\n' "$validator_output" | sed -n 's/^SHA256=//p')"
  has="$(printf '%s\n' "$validator_output" | sed -n 's/^HAS_HTACCESS=//p')"
  [ "$has" = "YES" ] && frontend_has_htaccess=true || frontend_has_htaccess=false
else
  frontend_validation_result="INVALID: $(printf '%s\n' "$validator_output" | sed -n 's/^INVALID: //p')"
  step_fail "frontend backup failed validation — $frontend_validation_result"
fi
printf '%s\n' "$frontend_sha256" > "${backup_dir}/${frontend_filename}.sha256"
chmod 600 "${backup_dir}/${frontend_filename}.sha256"

gate_result="PASS"
write_manifest
echo "BACKUP_GATE=PASS"
echo "BACKUP_DIR=$backup_dir"
echo "DB_SHA256=$db_sha256"
echo "FRONTEND_SHA256=$frontend_sha256"
exit 0
