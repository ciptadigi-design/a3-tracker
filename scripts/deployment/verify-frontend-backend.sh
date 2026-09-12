#!/usr/bin/env bash
# M2.17.5.6: pre-deploy gate. Refuses to let a Production frontend artifact
# ship unless build-frontend.sh's build-manifest.json - written directly
# alongside the artifact at build time, from the same process that ran
# `vite build`, not re-derived from the deploying operator's shell - proves
# the artifact was built with VITE_DATA_BACKEND=laravel and the correct
# Production API base URL.
#
# Exists because "VITE_DATA_BACKEND=laravel is the only Production backend"
# was previously only a runbook checklist bullet, never enforced anywhere -
# Production silently ran on Supabase for the lifetime of every retained
# release as a result. A missing manifest is refused rather than assumed
# innocent: no manifest means the artifact was not built via the canonical
# build-frontend.sh path, which is itself grounds to reject it.
#
# Deliberately does NOT grep the bundle for the presence/absence of the
# string "supabase" - the Supabase adapter legitimately remains in the
# bundle as a split/dead chunk (the DEV/staging behavioral oracle), so its
# presence proves nothing about which backend Production traffic resolves to.
#
# Usage: verify-frontend-backend.sh <dist-dir>
# Exit codes:
#   0  BACKEND_VERIFIED=laravel
#   1  manifest missing, unparseable, or asserts a non-laravel/incorrect contract
#   2  usage error
set -euo pipefail

dist_dir="${1:-}"
[ -n "$dist_dir" ] || { echo "usage: verify-frontend-backend.sh <dist-dir>" >&2; exit 2; }
[ -d "$dist_dir" ] || { echo "DIST_DIR_NOT_FOUND: $dist_dir" >&2; exit 2; }

manifest="$dist_dir/build-manifest.json"
[ -f "$dist_dir/index.html" ] || { echo "FRONTEND_BACKEND_VERIFICATION_FAILED: no index.html in $dist_dir - not a real build output" >&2; exit 1; }
[ -f "$manifest" ] || { echo "FRONTEND_BACKEND_VERIFICATION_FAILED: no build-manifest.json in $dist_dir (was this built with scripts/deployment/build-frontend.sh?)" >&2; exit 1; }

backend="$(node -e 'try { console.log(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).dataBackend || "") } catch { console.log("") }' "$manifest")"
api_base_url="$(node -e 'try { console.log(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).apiBaseUrl || "") } catch { console.log("") }' "$manifest")"

if [ "$backend" != "laravel" ]; then
  echo "FRONTEND_BACKEND_VERIFICATION_FAILED: build-manifest.json records dataBackend='${backend:-(missing/unparseable)}', expected 'laravel'" >&2
  exit 1
fi
if [ "$api_base_url" != "/api/v1" ]; then
  echo "FRONTEND_BACKEND_VERIFICATION_FAILED: build-manifest.json records apiBaseUrl='${api_base_url:-(missing/unparseable)}', expected '/api/v1'" >&2
  exit 1
fi

echo "BACKEND_VERIFIED=laravel"
echo "API_BASE_URL_VERIFIED=/api/v1"
