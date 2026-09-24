#!/usr/bin/env bash
set -euo pipefail

python_executable="${1:-}"
if [ -z "$python_executable" ]; then
  echo "usage: verify-official-python-runtime.sh <absolute-private-python-executable>" >&2
  exit 2
fi
case "$python_executable" in
  /*) ;;
  *) echo "OFFICIAL_PYTHON_RUNTIME_FAIL: executable must be absolute" >&2; exit 1 ;;
esac
[ -f "$python_executable" ] || { echo "OFFICIAL_PYTHON_RUNTIME_FAIL: executable is not a regular file" >&2; exit 1; }
[ -x "$python_executable" ] || { echo "OFFICIAL_PYTHON_RUNTIME_FAIL: executable is not executable" >&2; exit 1; }

identity="$($python_executable -I -c 'import cryptography,json,sys,pypdf; print(json.dumps({"python": [sys.version_info.major, sys.version_info.minor, sys.version_info.micro], "pypdf": pypdf.__version__, "cryptography": cryptography.__version__}))')" || {
  echo "OFFICIAL_PYTHON_RUNTIME_FAIL: interpreter/module preflight failed" >&2
  exit 1
}
python_version="$(printf '%s' "$identity" | "$python_executable" -I -c 'import json,sys; value=json.load(sys.stdin); print(".".join(map(str,value["python"])))')"
python_major_minor="$(printf '%s' "$identity" | "$python_executable" -I -c 'import json,sys; value=json.load(sys.stdin); print(".".join(map(str,value["python"][:2])))')"
pypdf_version="$(printf '%s' "$identity" | "$python_executable" -I -c 'import json,sys; print(json.load(sys.stdin)["pypdf"])')"
cryptography_version="$(printf '%s' "$identity" | "$python_executable" -I -c 'import json,sys; print(json.load(sys.stdin)["cryptography"])')"
[ "$python_major_minor" = "3.11" ] || { echo "OFFICIAL_PYTHON_RUNTIME_FAIL: Python 3.11.x required" >&2; exit 1; }
[ "$pypdf_version" = "6.14.2" ] || { echo "OFFICIAL_PYTHON_RUNTIME_FAIL: pypdf 6.14.2 required" >&2; exit 1; }
[ "$cryptography_version" = "50.0.1" ] || { echo "OFFICIAL_PYTHON_RUNTIME_FAIL: cryptography 50.0.1 required" >&2; exit 1; }

echo "OFFICIAL_PYTHON_RUNTIME=PASS"
echo "PYTHON_VERSION=$python_version"
echo "PYPDF_VERSION=$pypdf_version"
echo "CRYPTOGRAPHY_VERSION=$cryptography_version"
