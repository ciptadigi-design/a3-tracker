import test from 'node:test'
import assert from 'node:assert/strict'
import { projectCurrentComponentCards } from './componentCardProjection.js'

const lifecycle = (status, id, overrides = {}) => ({
  lifecycle_id: id,
  machine_id: 'machine-1',
  model_component_profile_id: 'profile-1',
  component_id: 'component-1',
  component_code: 'TONER_C',
  component_name: 'Toner Cyan',
  slot_code: 'TONER_C',
  lifecycle_status: status,
  display_order: 1,
  health_status: status === 'active' ? 'healthy' : 'unknown',
  ...overrides,
})

test('one unknown logical slot produces one card', () => {
  assert.deepEqual(projectCurrentComponentCards([lifecycle('unknown', 'unknown-1')]).map((row) => row.lifecycle_id), ['unknown-1'])
})

test('initialization still produces one active card', () => {
  assert.deepEqual(projectCurrentComponentCards([lifecycle('active', 'active-1')]).map((row) => row.lifecycle_id), ['active-1'])
})

for (const replacementCount of [1, 2, 8]) {
  test(`${replacementCount} replacement histories still produce one current card`, () => {
    const rows = Array.from({ length: replacementCount }, (_, index) => lifecycle('closed', `closed-${index}`))
    rows.push(lifecycle('active', 'current'))
    assert.deepEqual(projectCurrentComponentCards(rows).map((row) => row.lifecycle_id), ['current'])
  })
}

test('closed lifecycle history never becomes an UNKNOWN card', () => {
  assert.equal(projectCurrentComponentCards([lifecycle('closed', 'closed-only')]).length, 0)
})

test('card identity is machine plus normalized logical slot, independent of profile and health', () => {
  const rows = [
    lifecycle('unknown', 'stale-open', { model_component_profile_id: 'profile-old', slot_code: ' Toner_C ' }),
    lifecycle('active', 'current', { model_component_profile_id: 'profile-new', slot_code: 'toner_c', health_status: 'overdue' }),
  ]
  assert.deepEqual(projectCurrentComponentCards(rows).map((row) => row.lifecycle_id), ['current'])
})

test('toner refill and ordinary component replacement use the same slot projection', () => {
  const rows = [
    lifecycle('closed', 'toner-old'), lifecycle('active', 'toner-current'),
    lifecycle('closed', 'drum-old', { component_id: 'component-2', component_code: 'DRUM_K', component_name: 'Drum Black', slot_code: 'DRUM_K', display_order: 2 }),
    lifecycle('active', 'drum-current', { component_id: 'component-2', component_code: 'DRUM_K', component_name: 'Drum Black', slot_code: 'DRUM_K', display_order: 2 }),
  ]
  assert.deepEqual(projectCurrentComponentCards(rows).map((row) => row.lifecycle_id), ['toner-current', 'drum-current'])
})
