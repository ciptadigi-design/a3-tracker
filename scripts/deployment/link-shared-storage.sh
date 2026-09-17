#!/usr/bin/env bash
# M2.17.4: every atomic release directory is a fresh `git clone`, so
# backend/storage/framework/sessions starts empty on each deploy - any file-based
# session that was active in the previous release becomes unreadable the instant
# the `current` symlink swaps over, forcing every open browser tab into a stale
# CSRF token (419) or a dropped login (401). `shared/storage` already exists on
# the server for exactly this purpose but was never wired up. This script makes
# session storage persist across releases by replacing the release's
# storage/framework/sessions directory with a symlink into the persistent
# shared/ tree, merging in any session files the release directory already has
# so an in-flight session isn't dropped by running this against a live release.
#
# V1.4: the same fresh-clone-loses-everything problem applies to uploaded PDF
# documents (DocumentStorageService, storage/app/private/maintenance-documents on
# the `local` disk) - without this, every previously uploaded document's physical
# file would silently 404 on the next deploy even though its database row still
# points at it. Both directories use the identical idempotent merge-then-symlink
# treatment below.
#
# Usage (run on the server, from the deploy user):
#   scripts/deployment/link-shared-storage.sh <release_dir> <shared_dir>
# Example:
#   scripts/deployment/link-shared-storage.sh \
#     /home/u777904340/a3-production-app/releases/<sha> \
#     /home/u777904340/a3-production-app/shared
#
# Idempotent: safe to re-run against a release that is already linked.
set -euo pipefail

release_dir="${1:?usage: link-shared-storage.sh <release_dir> <shared_dir>}"
shared_dir="${2:?usage: link-shared-storage.sh <release_dir> <shared_dir>}"

link_into_shared() {
  local release_subpath="$1" shared_subpath="$2"
  local release_target="$release_dir/backend/$release_subpath"
  local shared_target="$shared_dir/$shared_subpath"

  mkdir -p "$shared_target"

  if [ -L "$release_target" ]; then
    echo "Already linked: $release_target -> $(readlink "$release_target")"
    return 0
  fi

  if [ -d "$release_target" ]; then
    # Preserve any files this release already wrote (e.g. re-running this
    # script against a release that has been serving traffic).
    find "$release_target" -maxdepth 1 -type f -exec cp -n {} "$shared_target/" \;
    rm -rf "$release_target"
  fi

  mkdir -p "$(dirname "$release_target")"
  ln -s "$shared_target" "$release_target"
  echo "Linked: $release_target -> $shared_target"
}

link_into_shared "storage/framework/sessions" "storage/framework/sessions"
link_into_shared "storage/app/private/maintenance-documents" "storage/app/private/maintenance-documents"
