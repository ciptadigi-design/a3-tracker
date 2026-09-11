import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const purchasingDialogs = readFileSync(new URL('./PurchasingDialogs.jsx', import.meta.url), 'utf8')
const purchasingPanel = readFileSync(new URL('./PurchasingPanel.jsx', import.meta.url), 'utf8')
const inventoryPage = readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const movementDetailModel = readFileSync(new URL('./movementDetailModel.js', import.meta.url), 'utf8')

// M2.17.5.1: a user review found "Remaining" in Purchase Detail read as current
// warehouse stock rather than supplier-fulfillment progress - the underlying
// calculation (Ordered - Received) was always correct and stays a locked domain
// invariant; only the label was ambiguous. This proves both surfaces (Purchase
// Detail and the Receive Goods dialog) now use the explicit label, and that no
// on-hand/live-inventory value was coupled into either component to make numbers
// "look synchronized" (the mission's explicit default: don't).
test('Purchase Detail labels supplier-fulfillment remaining explicitly, not just "Remaining"', () => {
  assert.match(purchasingDialogs, /<dt>Remaining to receive<\/dt>/)
})

test('Receive Goods dialog uses the same explicit remaining-to-receive label', () => {
  const occurrences = purchasingDialogs.match(/<dt>Remaining to receive<\/dt>/g) ?? []
  assert.ok(occurrences.length >= 2, 'expected the label in both Purchase Detail and the Receive Goods per-line breakdown')
})

test('Purchase Detail is not wired to any live Inventory On Hand value - it only ever reads purchase-line fields', () => {
  assert.doesNotMatch(purchasingDialogs, /on_hand|onHand|inventory_balance|data\.balances|data\.totals/i)
})

// M2.17.5 already fixed the Receiving list and the movement detail modal to show
// "Not recorded" for a genuinely null PIC; the Movements list itself (InventoryPage.
// jsx's MovementPanel) was the one remaining surface still rendering raw
// `undefined`/null as blank whitespace - reproducing exactly what pre-M2.17.5
// historical receipt movements still look like today.
test('Movements list shows "Not recorded" for a null PIC, matching the Receiving list and movement detail modal', () => {
  assert.match(inventoryPage, /row\.operational_person_name_snapshot \|\| 'Not recorded'/)
  assert.match(purchasingPanel, /line\.operational_person_name_snapshot \|\| 'Not recorded'/)
  assert.match(movementDetailModel, /row\.operational_person_name_snapshot \|\| 'Not recorded'/)
})

test('none of the three PIC presentation sites fabricate a person identity - no hardcoded name as a fallback', () => {
  for (const source of [inventoryPage, purchasingPanel, movementDetailModel]) {
    assert.doesNotMatch(source, /operational_person_name_snapshot \|\| '(?!Not recorded)[A-Z][a-z]+ [A-Z]/)
  }
})
