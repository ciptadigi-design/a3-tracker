import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')

test('Inventory Cost Basis reads the real backend cost-position fields, not a hardcoded empty projection', () => {
  assert.match(page, /data\.costPositions\.filter\(\(row\) => row\.inventory_item_id === item\.id\)/)
  assert.match(page, /known_cost_quantity/)
  assert.match(page, /unknown_cost_quantity/)
  assert.match(page, /known_inventory_cost/)
  assert.match(page, /cost_layer_count/)
})
