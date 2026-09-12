#!/usr/bin/env bash
# M2.18.2 (closes M2.18 audit H9): links a fresh release's backend/.env to the
# canonical shared/.env - the same pattern link-shared-storage.sh already
# established for session storage. Before this script existed, every new
# release required an operator to run `ln -sfn` by hand during the actual
# M2.17.5.6 and M2.18.1 deploys - undocumented, unscripted tribal knowledge
# that a future deploy could easily forget, leaving a release with no
# environment configuration at all.
#
# Usage:
#   link-shared-env.sh <release_dir> <shared_dir>
# Example:
#   link-shared-env.sh \
#     /home/u777904340/a3-production-app/releases/<sha> \
#     /home/u777904340/a3-production-app/shared
#
# Safety:
#   - Never reads or prints the contents of the .env file - only paths.
#   - Refuses to link if the shared .env does not exist yet.
#   - Refuses obviously unsafe paths (empty or "/").
#   - Refuses to clobber a release-local backend/.env that is a REAL file
#     (not already a symlink) - that could be intentional and must not be
#     silently overwritten.
#   - Idempotent: safe to re-run against an already-linked release.
#
# Exit codes:
#   0  success (ENV_LINK_OK=<release>/backend/.env -> <resolved shared path>)
#   1  shared .env missing, unsafe path, existing real file, or verification failed
#   2  usage error
set -euo pipefail

release_dir="${1:-}"
shared_dir="${2:-}"

if [ -z "$release_dir" ] || [ -z "$shared_dir" ]; then
  echo "usage: link-shared-env.sh <release_dir> <shared_dir>" >&2
  exit 2
fi

case "$release_dir" in
  "/"|"") echo "ENV_LINK_FAIL: unsafe release_dir: '$release_dir'" >&2; exit 1 ;;
esac
case "$shared_dir" in
  "/"|"") echo "ENV_LINK_FAIL: unsafe shared_dir: '$shared_dir'" >&2; exit 1 ;;
esac

[ -d "$release_dir/backend" ] || { echo "ENV_LINK_FAIL: no backend directory at $release_dir/backend" >&2; exit 1; }

shared_env="$shared_dir/.env"
[ -f "$shared_env" ] || { echo "ENV_LINK_FAIL: shared .env not found at $shared_env" >&2; exit 1; }

target_env="$release_dir/backend/.env"

if [ -e "$target_env" ] && [ ! -L "$target_env" ]; then
  echo "ENV_LINK_FAIL: $target_env already exists and is a real file, not a symlink - refusing to overwrite" >&2
  exit 1
fi

ln -sfn "$shared_env" "$target_env"

resolved="$(readlink -f "$target_env" 2>/dev/null || true)"
expected="$(readlink -f "$shared_env" 2>/dev/null || true)"
if [ -z "$resolved" ] || [ "$resolved" != "$expected" ]; then
  echo "ENV_LINK_FAIL: post-link verification failed (resolved to '$resolved', expected '$expected')" >&2
  exit 1
fi
[ -f "$target_env" ] || { echo "ENV_LINK_FAIL: target does not resolve to a readable file" >&2; exit 1; }

echo "ENV_LINK_OK=$target_env -> $resolved"
