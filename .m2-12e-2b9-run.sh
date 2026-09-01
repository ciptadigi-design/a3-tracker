#!/usr/bin/env bash
set -euo pipefail

# M2.12E 2B-9 safety guard.
#
# This runner intentionally performs local, read-only validation only.  The
# repository currently has no approved Laravel/MySQL Production apply command.
# The canonical migrate.mjs path generates PostgreSQL SQL and hard-blocks
# non-disposable targets, so silently substituting another importer would be an
# unsafe second migration engine.  This file must remain a hard stop until an
# approved Production apply entry point is added and audited.

ROOT="$(cd "$(dirname "$0")" && pwd)"
SOURCE="/var/folders/44/bzxc_f7n7r72ht1vgnh43fxm0000gn/T//a3-m212e-source.8SIKM9/final-frozen-source.json"
EVIDENCE="$ROOT/.m2-12e-2b8-crosswalk-dry-run.json"
PLAN="$ROOT/.m2-12e-2b8-plan.mjs"

fail() { printf 'STOP: %s\n' "$1" >&2; exit 78; }
test -f "$SOURCE" || fail 'authoritative frozen snapshot missing'
test -f "$EVIDENCE" || fail '2B-8 evidence artifact missing'
test -f "$PLAN" || fail '2B-8 planner missing'
node --check "$PLAN" >/dev/null || fail '2B-8 planner syntax invalid'

node --input-type=module - "$SOURCE" "$EVIDENCE" <<'NODE' || fail 'frozen source or 2B-8 evidence validation failed'
import { readFileSync } from 'node:fs'
import { fingerprintRows } from './scripts/migration/legacy-audit.mjs'
const sourcePath = process.argv[2]
const evidencePath = process.argv[3]
const snapshot = JSON.parse(readFileSync(sourcePath, 'utf8'))
const evidence = JSON.parse(readFileSync(evidencePath, 'utf8'))
const source = snapshot.tables ?? snapshot
const expectedCounts = { click_history:185, part_replacements:70, error_logs:91, inventory_parts:22, part_purchases:161 }
const expectedFingerprints = {
  click_history:'a577d071b32150c7ad13fd4b70c0fe6d9c4d3a7be08c27ad3efb8679cdeca537',
  part_replacements:'2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e',
  error_logs:'65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b',
  inventory_parts:'ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632',
  part_purchases:'6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306',
}
const counts = Object.fromEntries(Object.keys(expectedCounts).map((k) => [k, source[k]?.length ?? 0]))
const fingerprints = Object.fromEntries(Object.keys(expectedFingerprints).map((k) => [k, fingerprintRows(source[k])]))
if (JSON.stringify(counts) !== JSON.stringify(expectedCounts)) throw new Error('source count mismatch')
if (JSON.stringify(fingerprints) !== JSON.stringify(expectedFingerprints)) throw new Error('source fingerprint mismatch')
if (evidence.source?.total !== 529 || JSON.stringify(evidence.source?.fingerprints) !== JSON.stringify(expectedFingerprints)) throw new Error('2B-8 source evidence mismatch')
if (evidence.production?.account_id !== '4b26a0ee-e06f-4563-a6cc-9dfc7fbc0e0c' || evidence.production?.branch_id !== '94051ab9-235c-455f-b7ce-63f255cda3f6' || evidence.production?.machine_id !== '708e199e-7f77-4219-b278-37d0b94821d4') throw new Error('Production crosswalk mismatch')
if (evidence.validation?.production_schema_set !== 'PASS' || evidence.validation?.production_database_mutated !== false || evidence.validation?.legacy_import !== 'NOT_RUN' || evidence.validation?.public_activation !== 'NOT_RUN') throw new Error('2B-8 safety markers mismatch')
if (JSON.stringify(evidence.disposition) !== JSON.stringify({IMPORT:480,MERGE:0,SKIP_DUPLICATE:2,ARCHIVE_ONLY:45,APPROVED_EXCLUDE:2,MANUAL_REVIEW:0})) throw new Error('disposition mismatch')
console.log('source_identity=PASS')
console.log('source_count=529')
console.log('source_fingerprints=PASS')
console.log('dry_run_evidence=PASS')
console.log('production_crosswalk=PASS')
NODE

printf 'CHECKPOINT_2B_9_OPERATOR_SCRIPT=BLOCKED\n'
printf 'database_mutation=NOT_RUN\n'
printf 'public_activation=NOT_RUN\n'
printf 'STOP: no audited Laravel/MySQL Production apply path exists; no import was attempted\n' >&2
exit 78
