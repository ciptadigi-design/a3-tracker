#!/usr/bin/env bash
# M2.17.5.6: the canonical Production frontend build. `npm run build` alone
# never enforced VITE_DATA_BACKEND/VITE_API_BASE_URL - a missing
# VITE_DATA_BACKEND silently compiled to the Supabase default (see
# src/services/dataBackend.js), which is how Production shipped a
# Supabase-routed frontend against a Laravel-only backend for the lifetime of
# every retained release. This wrapper makes the Production build contract
# executable instead of a runbook checklist bullet a human has to remember:
# it sets the two required variables explicitly, runs the real build, and
# stamps a build-manifest.json into dist/ recording what was actually built -
# the artifact verify-frontend-backend.sh checks before any release ships.
#
# Usage: build-frontend.sh [dist-dir]   (default: dist)
# Exit codes:
#   0  success (BUILD_MANIFEST_WRITTEN=<path> printed)
#   1  npm run build failed
set -euo pipefail

dist_dir="${1:-dist}"

export VITE_DATA_BACKEND=laravel
export VITE_API_BASE_URL=/api/v1

npm run build -- --outDir "$dist_dir"

git_sha="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
built_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

node -e '
const fs = require("fs");
const path = require("path");
const [, distDir, backend, apiBaseUrl, gitSha, builtAt] = process.argv;
const manifest = { dataBackend: backend, apiBaseUrl, gitSha, builtAt };
fs.writeFileSync(path.join(distDir, "build-manifest.json"), JSON.stringify(manifest, null, 2) + "\n");
' "$dist_dir" "$VITE_DATA_BACKEND" "$VITE_API_BASE_URL" "$git_sha" "$built_at"

echo "BUILD_MANIFEST_WRITTEN=$dist_dir/build-manifest.json"
cat "$dist_dir/build-manifest.json"
