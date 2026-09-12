#!/usr/bin/env bash
# M2.18.2 (closes M2.18 audit H8): the deterministic, auditable rollback tool
# for the current releases/<sha> + current symlink architecture.
#
# Deliberately does NOT restore the database and does NOT run any migration,
# forward or down - see docs/production/ROLLBACK_RUNBOOK.md for the decision
# tree that determines when a DB restore is actually required instead of an
# application-only rollback. This script only ever performs the deterministic
# filesystem/release-identity part: pointing `current` at an already-verified
# prior release and re-syncing the public frontend surface from that
# release's own already-built dist/ - both fully reversible, neither one
# touches business data.
#
# It never invents a schema-compatibility answer it cannot prove: it only
# diffs migration FILE LISTS between the live release and the rollback
# target. A target missing migration files that the live release has is
# reported as a warning and blocks the swap unless the operator explicitly
# passes --confirm-db-compatible after reviewing it - this is the "explicit
# operator decision gate" the rollback is required to have, not an
# automatic judgment about whether those migrations were safely additive.
#
# Usage:
#   rollback-release.sh <target_sha> <app_root> <public_html_dir> [--dry-run] [--confirm-db-compatible]
# Example:
#   rollback-release.sh 2920c9ab1d357314b8162e9de43cc858ab42d6f7 \
#     /home/u777904340/a3-production-app \
#     /home/u777904340/domains/a3.ciptagrafika.com/public_html \
#     --dry-run
#
# Exit codes:
#   0  success (or a clean --dry-run report)
#   1  a validation check failed - nothing was changed
#   2  usage error
#   3  database compatibility could not be proven and was not confirmed - nothing was changed
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

dry_run=false
confirm_db_compatible=false
positional=()
for arg in "$@"; do
  case "$arg" in
    --dry-run) dry_run=true ;;
    --confirm-db-compatible) confirm_db_compatible=true ;;
    *) positional+=("$arg") ;;
  esac
done

target_sha="${positional[0]:-}"
app_root="${positional[1]:-}"
public_html_dir="${positional[2]:-}"

if [ -z "$target_sha" ] || [ -z "$app_root" ] || [ -z "$public_html_dir" ]; then
  echo "usage: rollback-release.sh <target_sha> <app_root> <public_html_dir> [--dry-run] [--confirm-db-compatible]" >&2
  exit 2
fi
if ! [[ "$target_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "ROLLBACK_FAILED: '$target_sha' is not an exact 40-character lowercase-hex commit SHA" >&2
  exit 2
fi

releases_root="$app_root/releases"
shared_dir="$app_root/shared"
current_link="$app_root/current"
target_release="$releases_root/$target_sha"

[ -d "$target_release" ] || { echo "ROLLBACK_FAILED: target release not found: $target_release" >&2; exit 1; }
[ -d "$public_html_dir" ] || { echo "ROLLBACK_FAILED: public_html directory not found: $public_html_dir" >&2; exit 1; }

echo "=== Validating target release $target_sha ==="
if ! "$SCRIPT_DIR/verify-release.sh" "$target_release" "$shared_dir" "$target_sha"; then
  echo "ROLLBACK_FAILED: target release did not pass verify-release.sh - it may predate the current deployment contract (missing shared .env/session links or a pre-M2.17.5.6 frontend build), or its links/identity have gone stale. Do not retrofit the old release to force this to pass." >&2
  exit 1
fi

echo "=== Checking migration file compatibility (informational only - never touches the database) ==="
db_compat="TARGET_RELEASE_HAS_ALL_LIVE_MIGRATIONS"
live_backend="$current_link/backend"
if [ -d "$live_backend/database/migrations" ] && [ -d "$target_release/backend/database/migrations" ]; then
  # Process substitution (`<(...)`) depends on /dev/fd, which is not mounted
  # in this Hostinger SSH environment (confirmed live during M2.18.2) - a
  # plain `comm -13 <(...) <(...)` fails outright here. Use temp files
  # instead, which work on every target.
  target_list="$(mktemp)"
  live_list="$(mktemp)"
  ls "$target_release/backend/database/migrations" 2>/dev/null | sort > "$target_list"
  ls "$live_backend/database/migrations" 2>/dev/null | sort > "$live_list"
  missing_in_target="$(comm -13 "$target_list" "$live_list")"
  rm -f "$target_list" "$live_list"
  if [ -n "$missing_in_target" ]; then
    db_compat="TARGET_RELEASE_PREDATES_MIGRATIONS_ALREADY_APPLIED"
    echo "WARNING: the target release's migration files do not include migrations present in the live release:" >&2
    echo "$missing_in_target" | sed 's/^/  - /' >&2
    echo "This is usually safe ONLY if every migration listed above is purely additive (nullable/defaulted columns, no drops/renames) - see docs/production/RELEASE_PROCEDURE.md. This script cannot prove that automatically." >&2
  fi
else
  db_compat="UNKNOWN_COULD_NOT_COMPARE_MIGRATION_DIRECTORIES"
  echo "WARNING: could not compare migration directories (live release or target release migrations directory not found)." >&2
fi
echo "DATABASE_COMPATIBILITY=$db_compat"

if [ "$dry_run" = true ]; then
  echo "DRY_RUN=YES"
  echo "Would repoint $current_link -> $target_release"
  echo "Would re-sync $public_html_dir from $target_release/dist (preserving its inode and .htaccess)"
  echo "No changes were made."
  exit 0
fi

if [ "$db_compat" != "TARGET_RELEASE_HAS_ALL_LIVE_MIGRATIONS" ] && [ "$confirm_db_compatible" != true ]; then
  echo "ROLLBACK_FAILED: database compatibility could not be proven and --confirm-db-compatible was not passed. Review the warning above, confirm those migrations are safe for the target release to run against (or that a DB restore is the correct path instead - see docs/production/ROLLBACK_RUNBOOK.md), then re-run with --confirm-db-compatible." >&2
  exit 3
fi

echo "=== Preserving public_html identity before mutation ==="
pre_inode="$(stat -c '%i' "$public_html_dir")"
pre_htaccess="$(sha256sum "$public_html_dir/.htaccess" | awk '{print $1}')"

echo "=== Re-syncing frontend from target release (never replaces public_html itself) ==="
"$SCRIPT_DIR/sync-public-html.sh" "$target_release/dist" "$public_html_dir"

post_inode="$(stat -c '%i' "$public_html_dir")"
post_htaccess="$(sha256sum "$public_html_dir/.htaccess" | awk '{print $1}')"
[ "$pre_inode" = "$post_inode" ] || { echo "ROLLBACK_FAILED: public_html inode changed during sync - aborting before symlink swap" >&2; exit 1; }
[ "$pre_htaccess" = "$post_htaccess" ] || { echo "ROLLBACK_FAILED: .htaccess changed during sync - aborting before symlink swap" >&2; exit 1; }

echo "=== Atomic current symlink swap ==="
ln -sfn "$target_release" "$current_link"

echo "ROLLBACK_OK=$target_sha"
echo "PUBLIC_HTML_INODE_PRESERVED=YES"
echo "HTACCESS_UNCHANGED=YES"
echo "NEXT_STEP=reset the web OPcache now via the established one-shot reset-opcache.php upload/invoke/delete sequence (see docs/production/RELEASE_PROCEDURE.md), then run the smoke tests and confirm GET /api/v1/version reports $target_sha exactly."
