import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import test from 'node:test'
import { IDENTITY } from './lib/m2-10a-engine.mjs'
import { M211_TARGET, assertM211Target, buildM211Plan, stockApplySql, validateCutoverGates, validateStockManifest } from './lib/m2-11-engine.mjs'

const privateSourceUrl = new URL('../../.migration-private/m2-10b/958bb2b3-3110-410f-9c03-d8355a2f9be7/legacy-snapshot.json', import.meta.url)
const source = existsSync(privateSourceUrl) ? JSON.parse(readFileSync(privateSourceUrl)).tables : null
const sourceTest = source ? test : test.skip
const rehearsal = JSON.parse(readFileSync(new URL('./reconciliation/m2-11/6a8f4bb8-1f31-4e03-a9db-fdd9821e8d78/rehearsal-manifest.json', import.meta.url)))
const row = { inventory_item_id:'10000000-0000-4000-8000-000000000001', counted_quantity:2, unit:'pcs', cost_status:'UNKNOWN', unit_cost:null, counted_by:'Fixture', verified_by:'Fixture Reviewer', counted_at:'2026-08-30T00:00:00Z' }
const fixture = { classification:'NON_PRODUCTION_STOCK_OPNAME_FIXTURE', execution_uuid:'10000000-0000-4000-8000-000000000002', target:{ type:M211_TARGET, project_ref:null, account_id:IDENTITY.accountId, branch_id:IDENTITY.branchId, location_id:'b6296488-5479-4dd0-9463-091891b4cbe4' }, approval:{ approved:true, reviewed_by:'Fixture Reviewer' }, rows:[row] }
const stockCli = readFileSync(new URL('./apply-opening-stock.mjs', import.meta.url), 'utf8')

test('M2.11 blocks production, DEV, hosted, and unknown targets', () => {
  assert.equal(assertM211Target({ targetType:M211_TARGET, targetContainer:'supabase_db_konica-tracker-next' }), true)
  assert.throws(() => assertM211Target({ targetType:'PRODUCTION', targetContainer:'supabase_db_local' }), /only DISPOSABLE_REHEARSAL/)
  assert.throws(() => assertM211Target({ targetType:M211_TARGET, targetContainer:'supabase_db_production' }), /hard blocked/)
  assert.throws(() => assertM211Target({ targetType:M211_TARGET, targetContainer:'supabase_db_local', targetProjectRef:'sxitqjxljoqsnpepymrl' }), /hosted project/)
  assert.throws(() => assertM211Target({ targetType:M211_TARGET, targetContainer:'supabase_db_local', productionMode:true }), /unavailable/)
})

sourceTest('Production-like empty target reclassifies DEV merge as deterministic import', () => {
  const plan = buildM211Plan(source)
  assert.deepEqual(plan.dispositionTotals, { IMPORT:477, MERGE:0, SKIP_DUPLICATE:2, ARCHIVE_ONLY:45, APPROVED_EXCLUDE:2, MANUAL_REVIEW:0 })
  assert.equal(plan.operations.counters.length, 180)
  assert.equal(plan.operations.purchases.length, 161)
  assert.equal(plan.unexplainedRemainder, 0)
})

test('cutover gates require fingerprints, backup restore, freeze, stock, and approvals', () => {
  assert.throws(() => validateCutoverGates({}), /execution_uuid/)
  const gates = { execution_uuid:'x', source_fingerprints:{a:'b'}, source_freeze:{confirmed:true}, target_fingerprint:'x', backup_proof:{verified_restore:true}, stock_manifest:{approved:true,classification:'NON_PRODUCTION_STOCK_OPNAME_FIXTURE'}, approvals:{owner:'x'} }
  assert.equal(validateCutoverGates(gates), true)
  assert.throws(() => validateCutoverGates({...gates,source_freeze:{confirmed:false}}), /freeze confirmation/)
})

test('opening stock requires approved explicit manifest and preserves unknown cost', () => {
  assert.equal(validateStockManifest(fixture), true)
  const sql = stockApplySql(fixture)
  assert.match(sql, /initialize_inventory_stock_costed/)
  assert.match(sql, /,null\)\)\.id/)
  assert.throws(() => validateStockManifest({...fixture,classification:'PRODUCTION'}), /rehearsal stock fixtures only/)
  assert.throws(() => validateStockManifest({...fixture,rows:[{...row,unit_cost:0}]}), /must remain null/)
  assert.match(stockCli, /apply:false/)
  assert.match(stockCli, /expected_target_fingerprint/)
  assert.match(stockCli, /execution_uuid !== args\.execution_id/)
})

sourceTest('legacy migration SQL does not create opening stock from legacy snapshot or purchases', async () => {
  const { buildApplySql } = await import('./lib/m2-10a-engine.mjs')
  const sql = buildApplySql(buildM211Plan(source))
  assert.doesNotMatch(sql, /initialize_inventory_stock|insert into public\.inventory_movements|insert into public\.inventory_receipts/)
})

sourceTest('Production Account Branch Machine mapping and Graha denylist remain exact', () => {
  assert.deepEqual({account:IDENTITY.accountId,tuparev:IDENTITY.branchId,graha:IDENTITY.grahaId,machine:IDENTITY.machineId}, {
    account:'357e420a-c9ea-4404-9da4-f254c5dce5ef', tuparev:'76d3c7ab-55c3-40f7-b133-0ef54a448893',
    graha:'9f753339-0d54-42c9-9bb6-afe2461803f8', machine:'b4ca07ee-c588-404d-abcf-b6a029e68776',
  })
  const plan = buildM211Plan(source)
  assert.ok(plan.crosswalk.every((entry) => entry.target_id !== IDENTITY.grahaId))
})

sourceTest('archive-only and exclusion evidence is preserved with full source accounting', () => {
  const plan = buildM211Plan(source)
  assert.equal(plan.crosswalk.length, 526)
  assert.equal(plan.crosswalk.filter((row) => row.disposition === 'ARCHIVE_ONLY').length, 45)
  assert.equal(plan.crosswalk.filter((row) => row.disposition === 'APPROVED_EXCLUDE').length, 2)
  assert.equal(plan.crosswalk.filter((row) => row.disposition === 'SKIP_DUPLICATE').length, 2)
  assert.equal(plan.eligibleAccounted, 526)
})

sourceTest('deterministic plan and migration IDs survive a full re-plan', () => {
  const first = buildM211Plan(source)
  const second = buildM211Plan(source)
  assert.deepEqual(first, second)
  assert.equal(new Set(first.crosswalk.filter((row) => row.target_id).map((row) => row.target_id)).size,
    first.crosswalk.filter((row) => row.target_id).length)
})

test('committed rehearsal evidence remains fully reconciled and restore-proven without private source data', () => {
  assert.deepEqual(rehearsal.plan.disposition_totals, { IMPORT:477, MERGE:0, SKIP_DUPLICATE:2, ARCHIVE_ONLY:45, APPROVED_EXCLUDE:2, MANUAL_REVIEW:0 })
  assert.deepEqual(rehearsal.plan.operation_counts, { people:10, counters:180, lifecycles:47, suppliers:9, items:22, purchases:161, incidents:89 })
  assert.equal(rehearsal.reconciliation.source, 526)
  assert.equal(rehearsal.reconciliation.unexplained, 0)
  assert.equal(rehearsal.reconciliation.graha_leakage, 0)
  assert.equal(rehearsal.backup.restore_verified, true)
  assert.equal(rehearsal.backup.matches_baseline, true)
  assert.equal(rehearsal.checks.production_mutated, false)
  assert.equal(rehearsal.checks.dev_mutated, false)
})
