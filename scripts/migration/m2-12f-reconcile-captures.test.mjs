import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import {
  classifyDomain,
  verifySha,
  writePlan,
} from './m2-12f-reconcile-captures.mjs'

const fixtureUrl = new URL('./fixtures/m2-12f-reconciliation-cases.json', import.meta.url)
const fixtures = JSON.parse(await readFile(fixtureUrl, 'utf8'))

const normalize = row => ({
  test_dummy: false,
  legacy: false,
  post_freeze: false,
  dev_only: false,
  deferred: false,
  invalid_for_write: false,
  ...row,
})

const classify = fixture => classifyDomain(
  fixture.domain,
  fixture.source.map(normalize),
  fixture.production.map(normalize),
)

const emptyDomains = () => Object.fromEntries([
  'accounts', 'branches', 'machine_models', 'machines', 'component_catalogs',
  'model_profiles', 'model_profile_slots', 'machine_components',
  'component_lifecycles', 'counters', 'operational_people',
  'operational_person_branch_assignments', 'inventory_locations',
  'inventory_items', 'purchases', 'receipts', 'inventory_movements_fifo',
  'incidents_errors', 'machine_cost_source_facts', 'operational_policies',
  'counter_types', 'settings_configuration', 'auth_identity_references',
].map(name => [name, { classifications: [] }]))

test('SHA input validation accepts exact content and fails closed on mismatch', () => {
  const buffer = Buffer.from('offline-capture\n')
  const expected = createHash('sha256').update(buffer).digest('hex')
  assert.equal(verifySha(buffer, expected, 'fixture'), expected)
  assert.throws(() => verifySha(buffer, '0'.repeat(64), 'fixture'), /SHA256 mismatch/)
})

test('exact and semantic matches do not depend on UUID equality', () => {
  assert.equal(classify(fixtures.exact_match).matched, 1)
  const semantic = classify(fixtures.semantic_match_different_uuid)
  assert.equal(semantic.matched, 1)
  assert.equal(semantic.planned_create, 0)
})

test('legacy duplicate lifecycle is skipped and never planned', () => {
  const result = classify(fixtures.legacy_duplicate_lifecycle)
  assert.equal(result.already_migrated_legacy, 1)
  assert.equal(result.duplicate, 1)
  assert.equal(result.planned_create, 0)
})

test('canonical config and missing inventory config are deterministic create candidates', () => {
  for (const name of ['supabase_only_canonical_config', 'missing_production_inventory_config']) {
    const result = classify(fixtures[name])
    assert.equal(result.supabase_only_canonical, 1)
    assert.equal(result.planned_create, 1)
    assert.equal(result.requires_user_approval, 1)
  }
})

test('DEV/test record is excluded', () => {
  const result = classify(fixtures.dev_test_exclusion)
  assert.equal(result.test_dummy, 1)
  assert.equal(result.planned_exclude, 1)
  assert.equal(result.planned_create, 0)
})

test('ambiguous person fails closed', () => {
  const result = classify(fixtures.ambiguous_person_match)
  assert.equal(result.ambiguous, 1)
  assert.equal(result.requires_user_approval, 1)
  assert.equal(result.planned_create, 0)
})

test('derived machine-cost source facts match without migrating totals', () => {
  const result = classify(fixtures.derived_machine_cost_parity)
  assert.equal(result.matched, 1)
  assert.equal(result.planned_create, 0)
})

test('28-slot Components parity is a no-op', () => {
  const fixture = fixtures.noop_components_config_parity
  const source = Array.from({ length: fixture.source_count }, (_, index) => normalize({ semantic_key: `c1070:slot-${index + 1}`, signature: { ...fixture.signature, order: index + 1 } }))
  const production = Array.from({ length: fixture.production_count }, (_, index) => normalize({ semantic_key: `c1070:slot-${index + 1}`, signature: { ...fixture.signature, order: index + 1 } }))
  const result = classifyDomain(fixture.domain, source, production)
  assert.equal(result.matched, 28)
  assert.equal(result.planned_create, 0)
  assert.equal(result.ambiguous, 0)
})

test('write plan never copies DEV UUIDs or auth identities', () => {
  const domains = emptyDomains()
  domains.counter_types.classifications.push({ classification: 'SUPABASE_ONLY_CANONICAL', semantic_key: 'sheets', planned_write: true, reason: 'fixture' })
  domains.auth_identity_references.classifications.push({ classification: 'SUPABASE_ONLY_CANONICAL', semantic_key: '11111111-1111-4111-8111-111111111111', planned_write: true, reason: 'fixture' })
  const plan = writePlan(domains)
  assert.equal(plan.auth_user_copy, false)
  assert.equal(plan.writes.length, 1)
  assert.equal(plan.writes[0].domain, 'counter_types')
  assert.doesNotMatch(JSON.stringify(plan), /11111111-1111-4111-8111-111111111111/)
})

test('offline reconciler source has no network, database, SSH, or child-process API', async () => {
  const source = await readFile(new URL('./m2-12f-reconcile-captures.mjs', import.meta.url), 'utf8')
  assert.doesNotMatch(source, /\bfetch\s*\(/)
  assert.doesNotMatch(source, /node:(?:net|http|https|tls|child_process)/)
  assert.doesNotMatch(source, /\b(?:mysql|mariadb|postgres|supabase|ssh)\s*\(/i)
})
