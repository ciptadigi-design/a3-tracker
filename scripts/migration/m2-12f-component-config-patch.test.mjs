import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { buildPlans, evaluateCompareAndSet, EXPECTED_NEW_SHA256, EXPECTED_PRODUCTION_SHA256 } from './m2-12f-component-config-patch.mjs'

const capture = name => new URL(`../../.migration-private/m2-12f/20260903T094556Z/${name}`, import.meta.url)
const source = JSON.parse(await readFile(capture('new-supabase.json'), 'utf8'))
const production = JSON.parse(await readFile(capture('production-mariadb.json'), 'utf8'))
const { patch, rollback } = buildPlans(source, production)

test('COMPONENT_PATCH_UPDATE_ONLY_TEST=PASS', () => {
  assert.equal(patch.updates.length, 126)
  assert.equal(patch.update_counts.distinct_rows, 60)
  assert.ok(patch.updates.every(row => row.operation === 'UPDATE'))
  assert.deepEqual([...new Set(patch.updates.map(row => row.domain))].sort(), ['component_catalogs', 'machine_components', 'model_profile_slots'])
  assert.deepEqual(
    patch.row_reviews.reduce((counts, row) => ({ ...counts, [row.domain]: (counts[row.domain] ?? 0) + 1 }), {}),
    { component_catalogs: 4, model_profile_slots: 28, machine_components: 28 },
  )
  assert.ok(patch.row_reviews.every(row => row.fields.length > 0 && row.fields.every(field => field.classification && field.decision && field.reason)))
})

test('NO_COMPONENT_CREATE_TEST=PASS', () => {
  assert.deepEqual(patch.invariants.creates, { component_catalogs: 0, model_profile_slots: 0, machine_components: 0, component_lifecycles: 0 })
  assert.doesNotMatch(JSON.stringify(patch.updates), /"operation":"(?:CREATE|INSERT)"/)
})

test('NO_LIFECYCLE_MUTATION_TEST=PASS', () => {
  assert.ok(patch.updates.every(row => row.domain !== 'component_lifecycles'))
  assert.deepEqual(patch.invariants.before, patch.invariants.expected_after)
})

test('NO_DEV_UUID_WRITE_TEST=PASS', () => {
  const referenceIds = new Set(Object.values(source.tables).flatMap(rows => rows.flatMap(row => Object.entries(row)
    .filter(([key, value]) => (key === 'id' || key.endsWith('_id')) && typeof value === 'string')
    .map(([, value]) => value))))
  for (const update of patch.updates) {
    assert.ok(!referenceIds.has(update.production_id), `reference UUID used as Production ID: ${update.production_id}`)
    if (typeof update.proposed_value === 'string' && /^[0-9a-f-]{36}$/i.test(update.proposed_value)) assert.ok(!referenceIds.has(update.proposed_value))
  }
  assert.doesNotMatch(JSON.stringify(patch), /created_by|updated_by|auth_user/)
})

test('COMPARE_AND_SET_PRECONDITION_TEST=PASS', () => {
  for (const update of patch.updates) {
    assert.equal(update.precondition.expected_current_value, update.current_value)
    assert.match(update.compare_and_set.sql_template, /^UPDATE (component_catalogs|model_profile_slots|machine_components) SET [a-z_]+ = \? WHERE id = \? AND [a-z_]+ <=> \?$/)
    assert.deepEqual(update.compare_and_set.parameters, [update.proposed_value, update.production_id, update.current_value])
    assert.equal(update.compare_and_set.required_affected_rows, 1)
    assert.equal(evaluateCompareAndSet(update.current_value, update), 'APPLY_COMPARE_AND_SET')
    assert.equal(evaluateCompareAndSet(update.proposed_value, update), 'ALREADY_APPLIED_NO_OP')
  }
})

test('UNEXPECTED_PRODUCTION_STATE_FAIL_CLOSED_TEST=PASS', () => {
  for (const update of patch.updates) {
    const unexpected = typeof update.current_value === 'number' ? -999 : '__unexpected__'
    assert.equal(evaluateCompareAndSet(unexpected, update), 'FAIL_CLOSED_UNEXPECTED_PRODUCTION_STATE')
    assert.equal(update.precondition.unexpected_state_action, 'FAIL_CLOSED')
  }
})

test('COMPONENT_PATCH_ROLLBACK_PLAN_TEST=PASS', () => {
  assert.equal(rollback.plan_only, true)
  assert.equal(rollback.executed, false)
  assert.equal(rollback.updates.length, patch.updates.length)
  const forward = new Map(patch.updates.map(row => [`${row.domain}:${row.production_id}:${row.field}`, row]))
  for (const undo of rollback.updates) {
    const original = forward.get(`${undo.domain}:${undo.production_id}:${undo.field}`)
    assert.ok(original)
    assert.equal(undo.current_value, original.proposed_value)
    assert.equal(undo.proposed_value, original.current_value)
    assert.equal(undo.precondition.unexpected_state_action, 'FAIL_CLOSED')
    assert.equal(undo.compare_and_set.required_affected_rows, 1)
  }
})

test('COMPONENT_ROW_COUNTS_UNCHANGED_TEST=PASS', () => {
  assert.equal(production.tables.component_catalogs.length, 28)
  assert.equal(production.tables.model_profiles.length, 1)
  assert.equal(production.tables.model_profile_slots.length, 28)
  assert.equal(production.tables.machine_components.length, 28)
  assert.equal(production.tables.component_lifecycles.length, 47)
  assert.equal(production.tables.component_lifecycles.filter(row => row.status === 'active').length, 0)
  assert.equal(production.tables.component_lifecycles.filter(row => row.status === 'closed').length, 47)
  const latestCounter = production.tables.counter_readings
    .filter(row => row.status === 'effective')
    .sort((left, right) => String(right.observed_at).localeCompare(String(left.observed_at)))[0]
  assert.equal(Number(latestCounter.reading_value), 1441597)
  assert.equal(patch.invariants.expected_row_count_change, 0)
})

test('capture identities and classification totals remain fixed', () => {
  assert.equal(patch.capture_identity.new_supabase_sha256, EXPECTED_NEW_SHA256)
  assert.equal(patch.capture_identity.production_mariadb_sha256, EXPECTED_PRODUCTION_SHA256)
  assert.deepEqual(patch.classification_counts, { RUNTIME_REQUIRED: 96, CANONICAL_METADATA: 30, REPRESENTATIONAL_ONLY: 0, UNSAFE_OR_AMBIGUOUS: 0 })
  assert.deepEqual(patch.update_counts.by_domain_field_updates, { component_catalogs: 4, model_profile_slots: 47, machine_components: 75 })
})

test('generator source is offline-only', async () => {
  const script = await readFile(new URL('./m2-12f-component-config-patch.mjs', import.meta.url), 'utf8')
  assert.doesNotMatch(script, /\bfetch\s*\(/)
  assert.doesNotMatch(script, /node:(?:net|http|https|tls|child_process)/)
  assert.doesNotMatch(script, /\b(?:mysql|mariadb|postgres|supabase|ssh)\s*\(/i)
})
