import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/inventory.js', import.meta.url), 'utf8')

test('Inventory keeps item masters account-owned and projects physical evidence through Branch locations', () => {
  assert.match(service, /inventory_items[\s\S]+eq\('account_id', accountId\)/)
  assert.match(service, /inventory_locations[\s\S]+eq\('branch_id', branchId\)/)
  for (const projection of ['inventory_movement_history', 'inventory_purchase_summary', 'inventory_receipt_history', 'inventory_cost_position']) {
    assert.match(service, new RegExp(`${projection}[\\s\\S]+eq\\('branch_id', branchId\\)`))
  }
  assert.match(service, /quantityByItem/)
})

test('Branch switch invalidates Inventory children and Location creation cannot target another Branch', () => {
  assert.match(page, /loadedData\.branchId === branch\?\.id/)
  assert.match(page, /values: \{ \.\.\.values, branchId: branch\.id \}/)
  assert.match(page, /<MovementPanel key=\{branch\.id\}/)
  assert.doesNotMatch(page, /branches=\{branches\}/)
})
