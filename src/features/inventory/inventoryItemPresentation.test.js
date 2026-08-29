import assert from 'node:assert/strict'
import test from 'node:test'
import { inventoryItemLabel, inventoryItemScope } from './inventoryItemPresentation.js'

test('SKU-bearing item renders SKU and name', () => {
  assert.equal(inventoryItemLabel({ sku: ' TN-C1070-C ', name: 'Toner Cyan' }), 'TN-C1070-C · Toner Cyan')
})

test('SKU-less item renders only its clean name', () => {
  assert.equal(inventoryItemLabel({ sku: null, name: 'Toner Cyan' }), 'Toner Cyan')
  assert.equal(inventoryItemLabel({ sku: '   ', name: 'Drum Cyan' }), 'Drum Cyan')
})

test('linked Component is the secondary item context', () => {
  assert.equal(inventoryItemScope({ components: { name: 'Toner Cyan' } }), 'Component: Toner Cyan')
})

test('unlinked legacy acquisition remains valid without inventing a component', () => {
  assert.equal(inventoryItemScope({ name: 'Other Part', component_id: null, notes: 'LEGACY_IMPORT acquisition-only item; no opening stock' }), 'Legacy item · Unspecified component')
})
