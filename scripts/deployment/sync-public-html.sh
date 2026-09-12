#!/usr/bin/env bash
# Sync a canonical build-frontend.sh artifact in place. NEVER pass a release root.
# Usage: sync-public-html.sh <release>/dist <public_html>
# Requires PHP with DOM and the adjacent lib/ and verify-frontend-backend.sh.
set -Eeuo pipefail

fail() { printf 'SYNC_FAIL: %s\n' "$1" >&2; exit 1; }
[ "$#" -eq 2 ] && [ -n "$1" ] && [ -n "$2" ] || fail 'usage: sync-public-html.sh <release>/dist <public_html>'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
contract="$SCRIPT_DIR/lib/frontend-sync-contract.php"

# All gates below are read-only. No destination mutation, including metadata,
# occurs before the complete artifact AND canonical backend gates have passed.
paths="$(php "$contract" paths "$1" "$2")"
dist_dir="${paths%%$'\n'*}"
public_html_dir="${paths#*$'\n'}"
php "$contract" artifact "$dist_dir"
bash "$SCRIPT_DIR/verify-frontend-backend.sh" "$dist_dir"
before="$(php "$contract" invariants "$public_html_dir")"

# Mutation boundary. Every subsequent error is a partial sync, never SYNC_OK.
trap 'status=$?; printf "PARTIAL_SYNC_FAILURE: public_html may be incomplete; inspect before retrying (exit %s)\n" "$status" >&2; exit "$status"' ERR
# find does not follow links; rm removes a symlink entry, never its target.
find "$public_html_dir" -mindepth 1 -maxdepth 1 \
  ! -name '.htaccess' ! -name '.a3-active' ! -name 'index.php' \
  -exec rm -rf -- {} +

# Copy entries, not the source directory itself: preserve public_html's inode
# and permissions. Source infrastructure collisions and links were rejected.
shopt -s dotglob nullglob
for entry in "$dist_dir"/*; do
  cp -a -- "$entry" "$public_html_dir/"
done

php "$contract" public "$public_html_dir"
bash "$SCRIPT_DIR/verify-frontend-backend.sh" "$public_html_dir"
after="$(php "$contract" invariants "$public_html_dir")"
if [ "$before" != "$after" ]; then
  printf 'PARTIAL_SYNC_FAILURE: destination inode or infrastructure changed\n' >&2
  exit 1
fi
printf 'SYNC_OK\n'
