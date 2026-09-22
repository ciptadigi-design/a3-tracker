#!/usr/bin/env bash
# V1.10 - safe A3-only release retention. Prunes only OLD, completed release directories under
# <releases_root>, keeping the newest N plus the currently active release (wherever it happens to
# sit in that ordering). Never touches anything outside <releases_root>, never follows a symlink
# as if it were a release, and defaults to DRY RUN: a bare invocation reports exactly what it
# would remove and removes nothing.
#
# This exists because the real V1.9 deployment hit a genuine Hostinger ACCOUNT-level disk quota
# (separate from the physical filesystem, which had 8.6T free) after 56 release directories had
# accumulated with no retention mechanism at all (~4.5G). The oldest 46 were pruned by hand, with
# explicit operator approval, mid-deployment. This script makes that same operation routine,
# bounded, and safe to run unattended - but only ever AFTER activation + smoke + acceptance have
# already passed for a NEW release, never before, and never as part of activation itself (see
# docs/production/RELEASE_PROCEDURE.md).
#
# Usage:
#   prune-old-releases.sh <releases_root> <current_symlink> <keep_count> [--apply]
#
# Without --apply: DRY RUN (the default). Prints exactly what would be kept/removed. Removes
# nothing, regardless of any other argument.
# With --apply: actually removes the identified old release directories, one at a time, with a
# second safety check immediately before each removal.
#
# Exit codes:
#   0  success (dry run or apply)
#   1  a safety check failed - nothing was removed
#   2  usage error
set -euo pipefail

releases_root="${1:-}"
current_symlink="${2:-}"
keep_count="${3:-}"
apply=false
if [ "${4:-}" = "--apply" ]; then
  apply=true
fi

if [ -z "$releases_root" ] || [ -z "$current_symlink" ] || [ -z "$keep_count" ]; then
  echo "usage: prune-old-releases.sh <releases_root> <current_symlink> <keep_count> [--apply]" >&2
  exit 2
fi
if ! [[ "$keep_count" =~ ^[0-9]+$ ]] || [ "$keep_count" -lt 1 ]; then
  echo "PRUNE_FAIL: keep_count must be a positive integer" >&2
  exit 1
fi

# Fail closed: releases_root must exist and resolve to a real directory whose path shape is
# exactly what this tool is for - never an arbitrary operator-supplied path, and never a path
# reached only via a symlink hop this script did not itself control.
resolved_root="$(cd "$releases_root" 2>/dev/null && pwd -P)" || { echo "PRUNE_FAIL: releases root does not exist: $releases_root" >&2; exit 1; }
case "$resolved_root" in
  */a3-production-app/releases) ;;
  *) echo "PRUNE_FAIL: releases root does not look like an a3-production-app releases directory: $resolved_root" >&2; exit 1 ;;
esac
releases_root="$resolved_root"

[ -L "$current_symlink" ] || { echo "PRUNE_FAIL: current_symlink is not a symlink: $current_symlink" >&2; exit 1; }
active_target="$(readlink -f "$current_symlink")" || { echo "PRUNE_FAIL: could not resolve current_symlink" >&2; exit 1; }
case "$active_target" in
  "$releases_root"/*) ;;
  *) echo "PRUNE_FAIL: active release target is not inside releases root: $active_target" >&2; exit 1 ;;
esac
active_name="$(basename "$active_target")"

# Candidate release directories: plain directories directly under releases_root, ordered
# newest-first by modification time. Symlinks, non-directories and anything else malformed are
# reported and skipped, never treated as a release to keep or remove.
kept=()
removed=()
count=0
active_kept=false
while IFS= read -r name; do
  [ -n "$name" ] || continue
  full="$releases_root/$name"
  if [ -L "$full" ] || [ ! -d "$full" ]; then
    echo "PRUNE_SKIP_MALFORMED: $name" >&2
    continue
  fi
  if [ "$name" = "$active_name" ]; then
    kept+=("$name")
    active_kept=true
    count=$((count + 1))
    continue
  fi
  if [ "$count" -lt "$keep_count" ]; then
    kept+=("$name")
    count=$((count + 1))
  else
    removed+=("$name")
  fi
done < <(cd "$releases_root" && ls -1dt -- */ 2>/dev/null | sed 's#/$##')

if [ "$active_kept" = false ]; then
  # The active release was not found among the listed directories at all - this should never
  # happen. Refuse outright rather than risk it ending up in the remove list some other way.
  echo "PRUNE_FAIL: active release $active_name was not found among release directories in $releases_root" >&2
  exit 1
fi

echo "RELEASES_ROOT=$releases_root"
echo "ACTIVE_RELEASE=$active_name"
echo "KEEP_COUNT=$keep_count"
echo "KEPT_COUNT=${#kept[@]}"
# "${array[@]}" on a zero-element array is an unbound-variable error under `set -u` on bash 3.2
# (macOS's default /bin/bash) - guard every expansion with a length check rather than assume a
# newer bash is available wherever this runs, including on Production.
if [ "${#kept[@]}" -gt 0 ]; then
  for n in "${kept[@]}"; do echo "KEEP: $n"; done
fi
echo "REMOVE_COUNT=${#removed[@]}"
if [ "${#removed[@]}" -gt 0 ]; then
  for n in "${removed[@]}"; do echo "REMOVE: $n"; done
fi

if [ "$apply" = false ]; then
  echo "PRUNE_MODE=DRY_RUN"
  echo "PRUNE_RESULT=DRY_RUN_OK"
  exit 0
fi

if [ "${#removed[@]}" -gt 0 ]; then
  for name in "${removed[@]}"; do
    full="$releases_root/$name"
    # Re-checked immediately before every single removal, not just once at the top: never the
    # active release, never a path outside releases_root, never a symlink.
    if [ "$name" = "$active_name" ]; then
      echo "PRUNE_FAIL: refusing to remove the active release" >&2
      exit 1
    fi
    case "$full" in
      "$releases_root"/*) ;;
      *) echo "PRUNE_FAIL: computed path escapes releases root: $full" >&2; exit 1 ;;
    esac
    if [ -L "$full" ] || [ ! -d "$full" ]; then
      echo "PRUNE_SKIP_MALFORMED_AT_APPLY: $name" >&2
      continue
    fi
    rm -rf -- "$full"
    echo "REMOVED: $name"
  done
fi

echo "PRUNE_MODE=APPLY"
echo "PRUNE_RESULT=PASS"
