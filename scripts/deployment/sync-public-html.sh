#!/usr/bin/env bash
# M2.17.4.2: the atomic release process built `releases/<sha>/dist` and swapped the
# `current` symlink, but never actually copied the built SPA into `public_html` - the
# directory Apache/.htaccess really serves. Production kept silently serving whatever
# frontend bundle the LAST successful sync left behind (M2.17.4's, not M2.17.4.1's),
# even though the backend API had already cut over to the new release. This script is
# the missing step: it copies the new release's dist/ output into public_html WITHOUT
# touching .htaccess, .a3-active, or index.php (the Laravel front-controller) - none of
# which live in dist/ - and without replacing the public_html directory itself, so its
# inode is preserved.
#
# Usage:
#   sync-public-html.sh <release_dist_dir> <public_html_dir>
# Example:
#   sync-public-html.sh \
#     /home/USER/a3-production-app/releases/<sha>/dist \
#     /home/USER/domains/example.com/public_html
#
# Exit codes:
#   0  success
#   1  usage error or missing source
set -euo pipefail

dist_dir="${1:?usage: sync-public-html.sh <release_dist_dir> <public_html_dir>}"
public_html_dir="${2:?usage: sync-public-html.sh <release_dist_dir> <public_html_dir>}"

if [ ! -d "$dist_dir" ]; then
  echo "SYNC_FAIL: dist directory not found: $dist_dir" >&2
  exit 1
fi
if [ ! -f "$public_html_dir/.htaccess" ]; then
  echo "SYNC_FAIL: refusing to sync into a directory with no .htaccess (wrong target?): $public_html_dir" >&2
  exit 1
fi

# Remove only previously-synced SPA assets, never the protected infra files.
find "$public_html_dir" -mindepth 1 -maxdepth 1 \
  ! -name '.htaccess' ! -name '.a3-active' ! -name 'index.php' \
  -exec rm -rf {} +

cp -a "$dist_dir"/. "$public_html_dir"/

echo "SYNC_OK"
echo "index.html asset reference:"
grep -o 'assets/index-[^"]*\.js' "$public_html_dir/index.html" || true
