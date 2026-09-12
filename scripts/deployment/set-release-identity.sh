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
# M2.18.2: `sed -i` edits in place by writing a new file and renaming it over
# the original path - when that path is a symlink (exactly the shared .env
# case this script's own docstring above describes), the rename REPLACES the
# symlink with a brand-new regular file, silently breaking the release's
# link to the shared .env from that point on. This was true of every deploy
# since M2.17.5.3 and went undetected until M2.18.2's verify-release.sh
# preflight gate caught it. Fixed by resolving the symlink to its real
# target first and editing that file directly, so the symlink itself is
# never touched.
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

real_file="$(readlink -f "$env_file" 2>/dev/null || echo "$env_file")"

if grep -q '^APP_GIT_SHA=' "$real_file"; then
  sed -i.bak "s/^APP_GIT_SHA=.*/APP_GIT_SHA=\"${sha}\"/" "$real_file"
  rm -f "${real_file}.bak"
else
  printf '\nAPP_GIT_SHA="%s"\n' "$sha" >> "$real_file"
fi

echo "RELEASE_IDENTITY_SET=$sha"
