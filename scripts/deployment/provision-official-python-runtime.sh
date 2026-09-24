#!/usr/bin/env bash
set -euo pipefail

base_python="${1:-}"
runtime_root="${2:-}"
requirements="${3:-}"
runtime_identity="python311-pypdf-6.14.2-crypto-50.0.1-v1"

if [ -z "$base_python" ] || [ -z "$runtime_root" ] || [ -z "$requirements" ]; then
  echo "usage: provision-official-python-runtime.sh <absolute-python-3.11> <versioned-runtime-root> <requirements.txt>" >&2
  exit 2
fi
for path in "$base_python" "$runtime_root" "$requirements"; do
  case "$path" in /*) ;; *) echo "OFFICIAL_PYTHON_PROVISION_FAIL: all paths must be absolute" >&2; exit 1 ;; esac
done
[ "$(basename "$runtime_root")" = "$runtime_identity" ] || {
  echo "OFFICIAL_PYTHON_PROVISION_FAIL: runtime root must end in $runtime_identity" >&2
  exit 1
}
[ -f "$base_python" ] && [ -x "$base_python" ] || { echo "OFFICIAL_PYTHON_PROVISION_FAIL: invalid base interpreter" >&2; exit 1; }
[ -f "$requirements" ] || { echo "OFFICIAL_PYTHON_PROVISION_FAIL: requirements file missing" >&2; exit 1; }
grep -Eq '^pypdf==6\.14\.2[[:space:]]*\\$' "$requirements" || { echo "OFFICIAL_PYTHON_PROVISION_FAIL: exact pypdf pin missing" >&2; exit 1; }
grep -Eq '^[[:space:]]*--hash=sha256:3f07891af76dc002657e04993ab9b4de81de29f9013b9761d0b7968bff12e946$' "$requirements" || {
  echo "OFFICIAL_PYTHON_PROVISION_FAIL: reviewed pypdf wheel hash missing" >&2
  exit 1
}
grep -Eq '^cryptography==50\.0\.1[[:space:]]*\\$' "$requirements" || { echo "OFFICIAL_PYTHON_PROVISION_FAIL: exact cryptography pin missing" >&2; exit 1; }
[ "$($base_python -I -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')" = "3.11" ] || {
  echo "OFFICIAL_PYTHON_PROVISION_FAIL: base interpreter must be Python 3.11.x" >&2
  exit 1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -e "$runtime_root" ]; then
  [ -d "$runtime_root" ] && [ ! -L "$runtime_root" ] || { echo "OFFICIAL_PYTHON_PROVISION_FAIL: existing runtime is not a real directory" >&2; exit 1; }
  "$script_dir/verify-official-python-runtime.sh" "$runtime_root/bin/python"
  echo "OFFICIAL_PYTHON_RUNTIME_ALREADY_PROVISIONED=$runtime_root"
  exit 0
fi

parent="$(dirname "$runtime_root")"
mkdir -p "$parent"
temporary="$(mktemp -d "$parent/.${runtime_identity}.tmp.XXXXXX")"
cleanup() { [ -n "${temporary:-}" ] && [ -d "$temporary" ] && rm -rf "$temporary"; }
trap cleanup EXIT

"$base_python" -m venv "$temporary"
"$temporary/bin/python" -m pip install --disable-pip-version-check --require-hashes -r "$requirements"
"$script_dir/verify-official-python-runtime.sh" "$temporary/bin/python"
mv "$temporary" "$runtime_root"
temporary=""

echo "OFFICIAL_PYTHON_RUNTIME_PROVISIONED=$runtime_root"
