import test from 'node:test'
import assert from 'node:assert/strict'
import { lifecycleActionFor, resolveReplacementInventorySource } from './lifecycleActions.js'

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
