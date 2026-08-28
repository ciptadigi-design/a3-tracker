import assert from 'node:assert/strict'
import test from 'node:test'
import { discoverPurchaseItems, inventoryItemLabel } from './purchaseItemDiscovery.js'

const components = [
  { id: 'toner-c', code: 'TONER-C', name: 'Toner Cyan', is_active: true },
  { id: 'drum-c', code: 'DRUM-C', name: 'Drum Cyan', is_active: true },
  { id: 'archived', code: 'OLD', name: 'Archived component', is_active: false },
]
const items = [
  { id: 'oem', component_id: 'toner-c', sku: 'TONER-OEM-C', name: 'OEM Cyan', is_active: true, components: components[0] },
  { id: 'compatible', component_id: 'toner-c', sku: 'TONER-COMP-C', name: 'Compatible Cyan', is_active: true, components: components[0] },
  { id: 'canonical-drum', component_id: 'drum-c', sku: null, name: 'Drum Cyan', is_active: true, components: components[1] },
  { id: 'archived-item', component_id: 'drum-c', sku: 'OLD-DRUM', name: 'Old Drum', is_active: false, components: components[1] },
]

test('purchase discovery searches active items by SKU and item name', () => {
  assert.deepEqual(discoverPurchaseItems(items, components, 'COMP').itemResults.map((item) => item.id), ['compatible'])
  assert.equal(inventoryItemLabel(items[0]), 'TONER-OEM-C · OEM Cyan')
})

test('purchase discovery finds multiple SKUs through their linked Component name', () => {
  assert.deepEqual(discoverPurchaseItems(items, components, 'Toner Cyan').itemResults.map((item) => item.id), ['oem', 'compatible'])
})

test('SKU-less canonical item remains searchable and renders without an awkward prefix', () => {
  const result = discoverPurchaseItems(items, components, 'Drum Cyan')
  assert.deepEqual(result.itemResults.map((item) => item.id), ['canonical-drum'])
  assert.equal(inventoryItemLabel(result.itemResults[0]), 'Drum Cyan')
  assert.deepEqual(result.missingComponents, [])
})

test('archived components and inventory items are ineligible', () => {
  assert.deepEqual(discoverPurchaseItems(items, components, 'Archived'), { itemResults: [], missingComponents: [] })
})
