#!/usr/bin/env bash
# Non-destructive Production frontend/public_html backup validator.
#
# Classifies a frontend backup artifact as VALID or INVALID. Extracts to a
# disposable temp directory only (never over any live path) to inspect
# contents, then removes that temp directory. Never mutates the artifact
# itself or any Production path.
#
# Usage:
#   validate-frontend-backup.sh <path-to-archive.tar.gz>
#
# Exit codes:
#   0  VALID
#   1  INVALID
#   2  usage error
set -euo pipefail

MIN_FILE_COUNT=5
MIN_ARCHIVE_BYTES=1024

path="${1:-}"
if [ -z "$path" ]; then
  echo "usage: validate-frontend-backup.sh <path-to-archive.tar.gz>" >&2
  exit 2
fi

fail() {
  printf 'INVALID: %s\n' "$1"
  printf 'CLASSIFICATION=INVALID\n'
  printf 'PATH=%s\n' "$path"
  exit 1
}

file_size() {
  stat -c%s "$1" 2>/dev/null || stat -f%z "$1" 2>/dev/null
}

[ -f "$path" ] || fail "artifact does not exist at this path"

size="$(file_size "$path")"
[ -n "$size" ] || fail "could not determine file size"
[ "$size" -ge "$MIN_ARCHIVE_BYTES" ] || fail "archive is only ${size} bytes (< ${MIN_ARCHIVE_BYTES} byte floor) — too small to be a real frontend build"

tar -tzf "$path" >/dev/null 2>&1 || fail "archive failed tar integrity listing (corrupt/truncated)"

file_count="$(tar -tzf "$path" 2>/dev/null | grep -vc '/$' || true)"
[ "$file_count" -ge "$MIN_FILE_COUNT" ] || fail "archive contains only ${file_count} files (< ${MIN_FILE_COUNT} floor)"

listing="$(tar -tzf "$path" 2>/dev/null)"

echo "$listing" | grep -qE '(^|/)index\.html$' || fail "archive has no index.html"
echo "$listing" | grep -qE '(^|/)assets/' || fail "archive has no assets/ directory"
echo "$listing" | grep -qE '\.js$' || fail "archive contains no .js asset"

has_htaccess="NO"
echo "$listing" | grep -qE '(^|/)\.htaccess$' && has_htaccess="YES"

has_css="NO"
echo "$listing" | grep -qE '\.css$' && has_css="YES"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
tar -xzf "$path" -C "$tmp_dir" 2>/dev/null || fail "extraction to disposable temp directory failed"

index_path="$(find "$tmp_dir" -maxdepth 2 -name index.html | head -1)"
[ -n "$index_path" ] || fail "index.html not found after extraction"
[ -s "$index_path" ] || fail "extracted index.html is empty"
grep -qi "<html" "$index_path" || fail "extracted index.html does not look like HTML"

sha256="$(sha256sum "$path" 2>/dev/null | awk '{print $1}')"
if [ -z "$sha256" ]; then
  sha256="$(shasum -a 256 "$path" 2>/dev/null | awk '{print $1}')"
fi
[ -n "$sha256" ] || fail "could not calculate SHA256"

echo "CLASSIFICATION=VALID"
echo "PATH=$path"
echo "ARCHIVE_BYTES=$size"
echo "FILE_COUNT=$file_count"
echo "HAS_HTACCESS=$has_htaccess"
echo "HAS_CSS=$has_css"
echo "SHA256=$sha256"
exit 0
