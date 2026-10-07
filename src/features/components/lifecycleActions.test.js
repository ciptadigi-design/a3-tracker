import test from 'node:test'
import assert from 'node:assert/strict'
import { eligibleCompatibleInventoryItems, lifecycleActionFor, resolveReplacementInventorySource } from './lifecycleActions.js'

test('unknown lifecycle exposes Initialize and never Replace', () => {
  assert.equal(lifecycleActionFor({ lifecycleStatus: 'unknown', canInitialize: true, canReplace: true }), 'initialize')
})

for (const healthStatus of ['healthy', 'watch', 'overdue']) {
  test(`active ${healthStatus} lifecycle exposes Replace`, () => {
    assert.equal(lifecycleActionFor({ lifecycleStatus: 'active', healthStatus, canInitialize: true, canReplace: true }), 'replace')
  })
}

test('replacement availability does not depend on remaining percentage', () => {
  for (const remainingPercent of [80, 40, 17, -20, -145.5]) {
    assert.equal(lifecycleActionFor({ lifecycleStatus: 'active', healthStatus: 'healthy', remainingPercent, canReplace: true }), 'replace')
  }
})

test('permissions remain authoritative for lifecycle actions', () => {
  assert.equal(lifecycleActionFor({ lifecycleStatus: 'unknown', canInitialize: false, canReplace: true }), null)
  assert.equal(lifecycleActionFor({ lifecycleStatus: 'active', canInitialize: true, canReplace: false }), null)
})

test('Inventory is the default while an explicit persisted External source is preserved', () => {
  assert.equal(resolveReplacementInventorySource(undefined), 'inventory')
  assert.equal(resolveReplacementInventorySource(''), 'inventory')
  assert.equal(resolveReplacementInventorySource('inventory'), 'inventory')
  assert.equal(resolveReplacementInventorySource('external_untracked'), 'external_untracked')
})

// 16. A generic item compatible with the component being replaced appears in
// the dropdown (e.g. one "Charging Corona" item mapped to the K position).
test('eligibleCompatibleInventoryItems includes a generic item with an active compatibility row for this component', () => {
  const generic = { id: 'item-generic', name: 'Charging Corona' }
  const compatibilities = [{ inventory_item_id: 'item-generic', component_id: 'component-k' }]
  assert.deepEqual(eligibleCompatibleInventoryItems([generic], compatibilities, 'component-k'), [generic])
})

// 17. An item with no compatibility row for this component is excluded, even
// if present in the account's full inventory item list - never a
// component_id equality fallback.
test('eligibleCompatibleInventoryItems excludes an item with no mapping to this component', () => {
  const unrelated = { id: 'item-unrelated', name: 'Staple Cartridge' }
  const compatibilities = [{ inventory_item_id: 'item-unrelated', component_id: 'component-c' }]
  assert.deepEqual(eligibleCompatibleInventoryItems([unrelated], compatibilities, 'component-k'), [])
})

// One item mapped to several components (CMYK) is selectable for each of them.
test('eligibleCompatibleInventoryItems supports one item mapped to multiple components', () => {
  const generic = { id: 'item-generic', name: 'Charging Corona' }
  const compatibilities = [
    { inventory_item_id: 'item-generic', component_id: 'component-k' },
    { inventory_item_id: 'item-generic', component_id: 'component-c' },
  ]
  assert.deepEqual(eligibleCompatibleInventoryItems([generic], compatibilities, 'component-k'), [generic])
  assert.deepEqual(eligibleCompatibleInventoryItems([generic], compatibilities, 'component-c'), [generic])
  assert.deepEqual(eligibleCompatibleInventoryItems([generic], compatibilities, 'component-m'), [])
})

// 18. No compatibility mapping at all for this component -> empty result,
// which is what drives the dialog's "No compatible Inventory Item is
// configured for this component." empty state.
test('eligibleCompatibleInventoryItems returns empty when no mapping exists for this component at all', () => {
  const item = { id: 'item-1', name: 'Some Item' }
  assert.deepEqual(eligibleCompatibleInventoryItems([item], [], 'component-k'), [])
})
