#!/usr/bin/env bash
# M2.17.5.3: proves the version identity chain for one release, end to end -
# after that release's config is cached, config('release.git_sha') (what
# VersionController actually reports) must equal the exact SHA that was
# deployed, not the SHA on the operator's machine, not origin/develop HEAD,
# not any other release's identity.
#
# Run this AFTER `php artisan config:cache` for the release being verified,
# from the release's own backend directory - a stale or wrong cache is exactly
# the failure mode this exists to catch before Production acceptance.
#
# Usage:
#   verify-release-identity.sh <release-backend-dir> <expected-40-hex-sha>
#
# Exit codes:
#   0  RELEASE_IDENTITY_VERIFIED=<sha>
#   1  mismatch or malformed SHA
#   2  usage error
set -euo pipefail

backend_dir="${1:-}"
expected_sha="${2:-}"

if [ -z "$backend_dir" ] || [ -z "$expected_sha" ]; then
  echo "usage: verify-release-identity.sh <release-backend-dir> <expected-40-hex-sha>" >&2
  exit 2
fi
[ -d "$backend_dir" ] || { echo "RELEASE_DIR_NOT_FOUND: $backend_dir" >&2; exit 2; }
if ! [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "INVALID_EXPECTED_SHA: '$expected_sha' is not an exact 40-character lowercase-hex Git commit SHA" >&2
  exit 2
fi

actual_sha="$(cd "$backend_dir" && php artisan tinker --execute="echo config('release.git_sha');" 2>/dev/null | tr -d '[:space:]')"

if ! [[ "$actual_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "RELEASE_IDENTITY_MALFORMED: config('release.git_sha') returned '${actual_sha}', not a valid 40-hex SHA" >&2
  exit 1
fi
if [ "$actual_sha" != "$expected_sha" ]; then
  echo "RELEASE_IDENTITY_MISMATCH: config('release.git_sha')='${actual_sha}' expected='${expected_sha}'" >&2
  exit 1
fi

echo "RELEASE_IDENTITY_VERIFIED=$actual_sha"
