import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  HOSTED_DEV_REF, IDENTITY, assertTargetSafety, buildApplySql, expectedSourceFingerprints,
  idFor, slotByLabel,
} from './lib/m2-10a-engine.mjs'

const register = JSON.parse(readFileSync(new URL('./source-row-dispositions.json', import.meta.url)))
const counters = JSON.parse(readFileSync(new URL('./counter-decisions.json', import.meta.url)))
const engineSource = readFileSync(new URL('./migrate.mjs', import.meta.url), 'utf8')

test('CLI defaults to dry-run and mutation requires explicit apply', () => {
  assert.match(engineSource, /mode: 'dry-run', apply: false/)
  assert.match(engineSource, /value === '--apply'/)
  assert.match(engineSource, /dryRun: !args\.apply/)
})

test('M2.10A target allowlist fails closed for hosted, Production, and unknown targets', () => {
  assert.equal(assertTargetSafety({ targetType: 'DISPOSABLE', targetContainer: 'supabase_db_m210a', apply: true }), true)
  for (const targetType of ['DEV','PRODUCTION',undefined]) assert.throws(() => assertTargetSafety({ targetType, targetContainer: 'supabase_db_m210a', apply: true }), /not DISPOSABLE/)
  assert.throws(() => assertTargetSafety({ targetType: 'DISPOSABLE', targetContainer: `supabase_db_${HOSTED_DEV_REF}`, apply: true }), /hosted DEV/)
  assert.throws(() => assertTargetSafety({ targetType: 'DISPOSABLE', targetContainer: 'supabase_db_production', apply: true }), /hard blocked/)
})

test('locked identity and Graha denylist are immutable engine constants', () => {
  assert.equal(IDENTITY.accountId, '357e420a-c9ea-4404-9da4-f254c5dce5ef')
  assert.equal(IDENTITY.branchId, '76d3c7ab-55c3-40f7-b133-0ef54a448893')
  assert.equal(IDENTITY.machineId, 'b4ca07ee-c588-404d-abcf-b6a029e68776')
  assert.equal(IDENTITY.grahaId, '9f753339-0d54-42c9-9bb6-afe2461803f8')
})

test('source fingerprints and row dispositions are exact and fully reconciled', () => {
  assert.equal(Object.keys(expectedSourceFingerprints).length, 5)
  assert.equal(register.row_count, 526)
  assert.deepEqual(register.counts, { IMPORT: 476, MERGE: 1, SKIP_DUPLICATE: 2, SKIP_DERIVED: 0, ARCHIVE_ONLY: 18, MANUAL_REVIEW: 29 })
  assert.equal(register.m2_10a_eligible_count, 497)
  assert.equal(register.unexplained_remainder, 0)
})

test('counter merge, uncertain overlaps, daily clicks, and synthetic evidence stay explicit', () => {
  assert.equal(counters.decisions.filter((row) => row.disposition === 'MERGE').length, 1)
  assert.deepEqual(counters.decisions.filter((row) => row.disposition === 'MANUAL_REVIEW').map((row) => row.legacy_id).sort(), [
    '54da82a6-b723-442c-880f-19215e1f35bb','82d3b425-7273-415d-8c49-338d92405962',
  ])
  assert.equal(counters.decisions.filter((row) => row.timestamp_evidence === 'MIGRATION_SYNTHETIC_TIME').length, 27)
  assert.ok(counters.decisions.every((row) => row.daily_clicks_treatment === 'SKIP_DERIVED'))
})

test('UUIDv5 row and request identities are stable and separated', () => {
  assert.equal(idFor('row','click_history','abc'), idFor('row','click_history','abc'))
  assert.notEqual(idFor('row','click_history','abc'), idFor('request','click_history','abc'))
})

test('ambiguous replacement rows remain excluded and Other Part has no TEST_COMPONENT mapping', () => {
  assert.equal(register.rows.filter((row) => row.legacy_table === 'part_replacements' && row.disposition === 'MANUAL_REVIEW').length, 5)
  assert.equal(slotByLabel['other part'], undefined)
  assert.ok(!Object.values(slotByLabel).includes('TEST_COMPONENT'))
})

test('SQL contract creates acquisition evidence but never receipt, movement, FIFO, or replacement fiction', () => {
  const sql = buildApplySql({ operations: { people: [], counters: [], lifecycles: [], suppliers: [], items: [], purchases: [{ id:'00000000-0000-4000-8000-000000000001',lineId:'00000000-0000-4000-8000-000000000002',requestId:'00000000-0000-4000-8000-000000000003',sourceId:'1',supplier:{id:'00000000-0000-4000-8000-000000000004',code:'LEG',name:'Supplier'},item:{id:'00000000-0000-4000-8000-000000000005',sku:'LEG-I',name:'Other Part'},date:'2026-01-01',qty:1,unitPrice:2,sourceTotal:2 }], incidents: [] } }, { dryRun: true })
  assert.match(sql, /inventory_purchases/)
  assert.match(sql, /RECEIPT_UNKNOWN_NOT_REPRESENTED/)
  assert.doesNotMatch(sql, /insert into public\.inventory_receipts|insert into public\.inventory_movements|inventory_cost_lots|component_replacement_events/)
  assert.match(sql, /rollback;/)
})

test('first-boundary evidence stays archive-only and UNKNOWN is never synthesized by the engine', () => {
  assert.equal(register.rows.filter((row) => row.legacy_table === 'part_replacements' && row.disposition === 'ARCHIVE_ONLY').length, 18)
  assert.ok(register.rows.filter((row) => row.disposition === 'ARCHIVE_ONLY').every((row) => row.reason.includes('does not prove predecessor installation')))
  assert.doesNotMatch(engineSource, /installed_counter\s*=\s*created_at|status='unknown'/)
})

test('incident duplicate IDs remain exact high-confidence skips', () => {
  assert.deepEqual(register.rows.filter((row) => row.disposition === 'SKIP_DUPLICATE').map((row) => row.legacy_id).sort(), [
    '3436509c-f729-4f78-b9b7-33d0d41f6837','76c2f97c-cae5-44a8-ac0c-29af462b5994',
  ])
})
