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

sessions_dir="$release_dir/backend/storage/framework/sessions"
shared_sessions_dir="$shared_dir/storage/framework/sessions"

mkdir -p "$shared_sessions_dir"

if [ -L "$sessions_dir" ]; then
  echo "Already linked: $sessions_dir -> $(readlink "$sessions_dir")"
  exit 0
fi

if [ -d "$sessions_dir" ]; then
  # Preserve any session files this release already wrote (e.g. re-running
  # this script against a release that has been serving traffic).
  find "$sessions_dir" -maxdepth 1 -type f -exec cp -n {} "$shared_sessions_dir/" \;
  rm -rf "$sessions_dir"
fi

ln -s "$shared_sessions_dir" "$sessions_dir"
echo "Linked: $sessions_dir -> $shared_sessions_dir"
