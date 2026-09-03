import assert from 'node:assert/strict'
import test from 'node:test'
import { projectLaravelMachineComponents, projectLaravelReplacementHistory } from './laravelComponentProjection.js'

function productionFixture() {
  let lifecycleIndex = 0
  return Array.from({ length: 28 }, (_, index) => ({
    id: `assignment-${index + 1}`,
    machine_id: '708e199e-7f77-4219-b278-37d0b94821d4',
    component_id: `component-${index + 1}`,
    component: { code: `C1070-${index + 1}`, name: `Component ${index + 1}`, tracking_method: 'counter_based' },
    profile_slot: { baseline_expected_clicks: 100000, tracking_method: 'counter_based' },
    slot_code: `SLOT-${String(index + 1).padStart(2, '0')}`,
    source_type: 'inherited',
    status: 'configured',
    display_order: index,
    baseline_expected_clicks: 100000,
    latest_effective_counter: '1441597.0000',
    lifecycles: Array.from({ length: index < 19 ? 2 : 1 }, (_, historyIndex) => {
      lifecycleIndex += 1
      const installed = 1000000 + index * 1000 + historyIndex * 100
      return { id: `lifecycle-${lifecycleIndex}`, status: 'closed', installed_counter: String(installed), removed_counter: String(installed + 50000), actual_usage: '50000', source: 'legacy_import', created_at: `2026-08-${String((lifecycleIndex % 28) + 1).padStart(2, '0')}T00:00:00Z` }
    }),
  }))
}

test('C1070 28 configured assignments remain visible with zero active lifecycles and canonical latest counter', () => {
  const rows = projectLaravelMachineComponents(productionFixture())
  assert.equal(rows.length, 28)
  assert.equal(rows.filter((row) => row.lifecycle_status === 'active').length, 0)
  assert.ok(rows.every((row) => row.lifecycle_status === 'unknown' && row.lifecycle_id === null))
  assert.ok(rows.every((row) => row.latest_effective_counter === 1441597))
})

test('47 closed lifecycle rows project once into replacement history without fabricating current lifecycle state', () => {
  const fixture = productionFixture()
  fixture[0].lifecycles.push({ ...fixture[0].lifecycles[0] })
  const history = projectLaravelReplacementHistory(fixture)
  assert.equal(history.length, 47)
  assert.equal(new Set(history.map((row) => row.previous_lifecycle_id)).size, 47)
  assert.ok(history.every((row) => row.machine_id === '708e199e-7f77-4219-b278-37d0b94821d4'))
  assert.ok(projectLaravelMachineComponents(fixture).every((row) => row.lifecycle_status === 'unknown'))
})
