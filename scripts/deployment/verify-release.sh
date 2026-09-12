#!/usr/bin/env bash
# M2.18.2: the release preflight gate. A release must pass every check here
# BEFORE `current` is ever repointed at it - closes the gap where a release
# missing shared environment linkage, or built against the wrong frontend
# backend, could become live with nothing catching it first.
#
# Delegates to the existing single-purpose scripts wherever one already
# exists (verify-release-identity.sh, verify-frontend-backend.sh) instead of
# re-implementing their checks, so each fact is verified in exactly one place.
#
# This script NEVER mutates anything: it does not run migrations, does not
# write to the database, does not touch public_html, and does not swap the
# `current` symlink.
#
# Usage:
#   verify-release.sh <release_dir> <shared_dir> <expected_sha>
#
# Exit codes:
#   0  RELEASE_PREFLIGHT=PASS
#   1  RELEASE_PREFLIGHT=FAIL (see stderr for which check failed)
#   2  usage error
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

release_dir="${1:-}"
shared_dir="${2:-}"
expected_sha="${3:-}"

if [ -z "$release_dir" ] || [ -z "$shared_dir" ] || [ -z "$expected_sha" ]; then
  echo "usage: verify-release.sh <release_dir> <shared_dir> <expected_sha>" >&2
  exit 2
fi
if ! [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "usage: expected_sha must be an exact 40-character lowercase-hex commit SHA, got '$expected_sha'" >&2
  exit 2
fi

fail() {
  echo "RELEASE_PREFLIGHT_FAILED: $1" >&2
  echo "RELEASE_PREFLIGHT=FAIL"
  exit 1
}

[ -d "$release_dir" ] || fail "release directory not found: $release_dir"
[ -f "$release_dir/backend/artisan" ] || fail "backend/artisan missing - not a real backend checkout"
[ -f "$release_dir/backend/vendor/autoload.php" ] || fail "backend/vendor/autoload.php missing - composer install not run"

# --- Shared .env link (M2.18.2 / H9) ---
target_env="$release_dir/backend/.env"
[ -L "$target_env" ] || fail "backend/.env is not a symlink - run link-shared-env.sh first"
resolved_env="$(readlink -f "$target_env" 2>/dev/null || true)"
expected_env="$(readlink -f "$shared_dir/.env" 2>/dev/null || true)"
[ -n "$expected_env" ] || fail "shared .env not found at $shared_dir/.env"
[ "$resolved_env" = "$expected_env" ] || fail "backend/.env does not resolve to the canonical shared .env (resolved '$resolved_env', expected '$expected_env')"

# --- Shared session storage link (M2.17.4) ---
sessions_link="$release_dir/backend/storage/framework/sessions"
[ -L "$sessions_link" ] || fail "storage/framework/sessions is not a symlink - run link-shared-storage.sh first"
resolved_sessions="$(readlink -f "$sessions_link" 2>/dev/null || true)"
expected_sessions="$(readlink -f "$shared_dir/storage/framework/sessions" 2>/dev/null || true)"
[ -n "$expected_sessions" ] || fail "shared storage/framework/sessions not found at $shared_dir/storage/framework/sessions"
[ "$resolved_sessions" = "$expected_sessions" ] || fail "storage/framework/sessions does not resolve to the canonical shared path"

# --- Writable directories the running application needs ---
[ -w "$release_dir/backend/storage" ] || fail "backend/storage is not writable"
[ -w "$release_dir/backend/bootstrap/cache" ] || fail "backend/bootstrap/cache is not writable"

# --- Frontend artifact: build-manifest.json + Laravel backend + /api/v1 (M2.17.5.6) ---
[ -d "$release_dir/dist" ] || fail "no dist/ directory - frontend artifact missing"
if ! "$SCRIPT_DIR/verify-frontend-backend.sh" "$release_dir/dist" >/dev/null 2>&1; then
  fail "frontend artifact failed verify-frontend-backend.sh (not Laravel-backed, wrong API base, or missing manifest)"
fi

# --- Release identity: config('release.git_sha') must equal the exact SHA being promoted (M2.17.5.3) ---
if ! "$SCRIPT_DIR/verify-release-identity.sh" "$release_dir/backend" "$expected_sha" >/dev/null 2>&1; then
  fail "release identity does not match expected SHA $expected_sha - run set-release-identity.sh then php artisan config:cache first"
fi

# --- Migration status must at least be inspectable (proves DB connectivity/config is sane). Never applies a migration. ---
if ! (cd "$release_dir/backend" && php artisan migrate:status >/dev/null 2>&1); then
  fail "php artisan migrate:status could not run - DB connectivity or config problem"
fi

echo "RELEASE_PREFLIGHT=PASS"
