#!/usr/bin/env bash
# M2.17.5.3: deterministically injects the exact deployed Git commit SHA into a
# release's .env BEFORE `php artisan config:cache` runs. VersionController
# reads config('release.git_sha') (see config/release.php), which resolves
# env('APP_GIT_SHA') at config-cache time - this script is what makes that
# value correct and exact for the release actually being deployed, instead of
# an ad-hoc `sed` typed by hand into an SSH session each time.
#
# Refuses anything but an exact, full 40-character lowercase-hex commit SHA -
# an abbreviated SHA, a branch name, or an empty value is never an acceptable
# Production release identity.
#
# Usage:
#   set-release-identity.sh <env-file> <exact-40-hex-sha>
#
# Idempotent: replaces an existing APP_GIT_SHA line, or appends one if none
# exists yet. Run this against the release's .env (or the shared .env it is
# symlinked to) before caching config for that release.
#
# Exit codes:
#   0  success (RELEASE_IDENTITY_SET=<sha> printed)
#   1  invalid SHA format
#   2  usage error / env file not found
set -euo pipefail

env_file="${1:-}"
sha="${2:-}"

if [ -z "$env_file" ] || [ -z "$sha" ]; then
  echo "usage: set-release-identity.sh <env-file> <exact-40-hex-sha>" >&2
  exit 2
fi
[ -f "$env_file" ] || { echo "ENV_FILE_NOT_FOUND: $env_file" >&2; exit 2; }

if ! [[ "$sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "INVALID_SHA: '$sha' is not an exact 40-character lowercase-hex Git commit SHA" >&2
  exit 1
fi

if grep -q '^APP_GIT_SHA=' "$env_file"; then
  sed -i.bak "s/^APP_GIT_SHA=.*/APP_GIT_SHA=\"${sha}\"/" "$env_file"
  rm -f "${env_file}.bak"
else
  printf '\nAPP_GIT_SHA="%s"\n' "$sha" >> "$env_file"
fi

echo "RELEASE_IDENTITY_SET=$sha"
