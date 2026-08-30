#!/usr/bin/env node
import { mkdir, writeFile } from 'node:fs/promises'
import { readFileSync } from 'node:fs'
import { deterministicFingerprint } from './legacy-audit.mjs'
import { buildApplySql, calculateSourceFingerprints, reconcileTarget, runPsql, targetPreflight, verifySource, IDENTITY } from './lib/m2-10a-engine.mjs'
import { M211_LEDGER, M211_TARGET, applyStockFixture, assertM211Target, buildM211Plan, sha256File } from './lib/m2-11-engine.mjs'

const argv = Object.fromEntries(process.argv.slice(2).map((value, index, all) => value.startsWith('--') ? [value.slice(2), all[index + 1]] : null).filter(Boolean))
for (const name of ['source-snapshot','output-dir','execution-id','expected-target-fingerprint','backup-data','backup-schema']) if (!argv[name]) throw new Error(`--${name} is required`)
const container = argv['target-container'] ?? 'supabase_db_konica-tracker-next'
assertM211Target({ targetType:M211_TARGET, targetContainer:container })
const sourceFile = argv['source-snapshot']
const source = (JSON.parse(readFileSync(sourceFile)).tables ?? JSON.parse(readFileSync(sourceFile)))
const sourceFingerprints = verifySource(source)
const plan = buildM211Plan(source)
const before = targetPreflight(container)
if (before.state.schema_ledger !== M211_LEDGER) throw new Error(`Unexpected ledger ${before.state.schema_ledger}`)
if (before.fingerprint !== argv['expected-target-fingerprint']) throw new Error(`Target fingerprint mismatch: ${before.fingerprint}`)

let failureInjection = 'NOT_RUN'
try { runPsql(container, buildApplySql(plan, { injectFailure:true })) } catch { failureInjection = targetPreflight(container).fingerprint === before.fingerprint ? 'PASS_ROLLED_BACK' : 'FAIL_TARGET_CHANGED' }
if (failureInjection !== 'PASS_ROLLED_BACK') throw new Error(failureInjection)
runPsql(container, buildApplySql(plan, { dryRun:true }))
if (targetPreflight(container).fingerprint !== before.fingerprint) throw new Error('Dry run mutated target')
runPsql(container, buildApplySql(plan))
const first = targetPreflight(container)
const reconciliation = reconcileTarget(container, plan)
runPsql(container, buildApplySql(plan))
const second = targetPreflight(container)
if (first.fingerprint !== second.fingerprint) throw new Error('Second apply was not idempotent')

const stockRows = plan.operations.items.slice(0, 2).map((item, index) => ({
  inventory_item_id:item.id, display_name:item.name, sku:item.sku, component_id:item.componentId,
  counted_quantity:index + 1, unit:'pcs', cost_status:index === 0 ? 'KNOWN' : 'UNKNOWN', unit_cost:index === 0 ? 125000 : null,
  operational_person_id:'c89d9c9c-b892-4794-b69c-f2680908a068', counted_by:'NON_PRODUCTION_FIXTURE', verified_by:'NON_PRODUCTION_FIXTURE_REVIEWER', counted_at:'2026-08-30T03:00:00.000Z', notes:'Synthetic quantity for M2.11 rehearsal only',
}))
const stockManifest = {
  version:'m2.11-stock-fixture-v1', classification:'NON_PRODUCTION_STOCK_OPNAME_FIXTURE', execution_uuid:argv['execution-id'],
  target:{ type:M211_TARGET, project_ref:null, account_id:IDENTITY.accountId, branch_id:IDENTITY.branchId, location_id:'b6296488-5479-4dd0-9463-091891b4cbe4' },
  approval:{ approved:true, reviewed_by:'NON_PRODUCTION_FIXTURE_REVIEWER', production_approval:false }, rows:stockRows,
}
applyStockFixture(container, stockManifest)
applyStockFixture(container, stockManifest)
const stockEvidence = JSON.parse(runPsql(container, `\\pset tuples_only on\n\\pset format unaligned\nselect jsonb_build_object('movements',(select count(*) from public.inventory_movements where notes like 'NON_PRODUCTION_STOCK_OPNAME_FIXTURE%'),'lots',(select count(*) from public.inventory_cost_lots lot join public.inventory_movements m on m.id=lot.inbound_movement_id where m.notes like 'NON_PRODUCTION_STOCK_OPNAME_FIXTURE%'),'known_cost_quantity',(select coalesce(sum(source_quantity),0) from public.inventory_cost_lots lot join public.inventory_movements m on m.id=lot.inbound_movement_id where m.notes like 'NON_PRODUCTION_STOCK_OPNAME_FIXTURE%' and lot.unit_cost is not null),'unknown_cost_quantity',(select coalesce(sum(source_quantity),0) from public.inventory_cost_lots lot join public.inventory_movements m on m.id=lot.inbound_movement_id where m.notes like 'NON_PRODUCTION_STOCK_OPNAME_FIXTURE%' and lot.unit_cost is null),'graha_leakage',(select count(*) from public.inventory_movements m join public.inventory_locations l on l.id=m.location_id where l.branch_id='${IDENTITY.grahaId}'))::text;`).trim())
const afterStock = targetPreflight(container)
const artifact = {
  version:'m2.11-rehearsal-v1', execution_uuid:argv['execution-id'], classification:'NON_PRODUCTION_REHEARSAL',
  source:{ classification:'NON_FROZEN_ACCEPTED_REHEARSAL_SNAPSHOT', project_ref:IDENTITY.sourceProject, fingerprints:sourceFingerprints, aggregate_fingerprint:deterministicFingerprint(sourceFingerprints) },
  target:{ type:M211_TARGET, hosted_project_ref:null, ledger:M211_LEDGER, baseline_fingerprint:before.fingerprint, post_apply_fingerprint:first.fingerprint, post_stock_fingerprint:afterStock.fingerprint },
  backup:{ data_sha256:sha256File(argv['backup-data']), schema_sha256:sha256File(argv['backup-schema']), private_artifact:true, restore_verified:false },
  plan:{ disposition_totals:plan.dispositionTotals, operation_counts:Object.fromEntries(Object.entries(plan.operations).map(([key, rows]) => [key, rows.length])), unexplained_remainder:plan.unexplainedRemainder, hash:deterministicFingerprint(plan) },
  checks:{ failure_injection:failureInjection, dry_run_unchanged:true, first_apply:'PASS', second_apply_idempotent:first.fingerprint === second.fingerprint, dev_mutated:false, production_mutated:false },
  reconciliation, stock_fixture:stockEvidence,
}
await mkdir(argv['output-dir'], { recursive:true })
await writeFile(`${argv['output-dir']}/rehearsal-manifest.json`, `${JSON.stringify(artifact,null,2)}\n`)
await writeFile(`${argv['output-dir']}/crosswalk.json`, `${JSON.stringify({version:plan.version,rows:plan.crosswalk},null,2)}\n`)
await writeFile(`${argv['output-dir']}/reconciliation.json`, `${JSON.stringify(reconciliation,null,2)}\n`)
await writeFile(`${argv['output-dir']}/stock-fixture-evidence.json`, `${JSON.stringify({manifest:stockManifest,result:stockEvidence,production_approval:false},null,2)}\n`)
process.stdout.write(`${JSON.stringify(artifact,null,2)}\n`)
