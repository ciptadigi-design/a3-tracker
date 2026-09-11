import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { buildMovementDetail } from './movementDetailModel.js'

const inventoryPage = readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const componentsPage = readFileSync(new URL('../../pages/ComponentsPage.jsx', import.meta.url), 'utf8')

// M2.17.5 Part D: a real Component Replacement's outbound movement
// (movement_type='replacement_consumption') rendered with no visible label at all in
// both the Movements list and the detail modal, because `replacement_consumption` was
// simply missing from both files' `movementLabels` maps.
test('Movements list and detail modal both label a replacement_consumption movement as Component Replacement', () => {
  assert.match(inventoryPage, /replacement_consumption:\s*'Component Replacement'/)
  const detail = buildMovementDetail(
    { quantity: -1, unit_snapshot: 'pcs', location_name: 'CG Digital Print', operational_person_name_snapshot: 'Akmal Fauzan', occurred_at: '2026-09-11T09:52:26Z', reference_type: 'component_replacement', movement_type: 'replacement_consumption' },
    null,
    { formatCurrency: () => '', formatQuantity: (v) => String(v), formatTime: () => '' },
  )
  assert.equal(detail.movementType, 'Component Replacement')
})

// M2.17.5 Part C (defensive, belt-and-suspenders): the real fix is that
// ReplaceMachineComponent::execute() now always sets a real installed_counter
// baseline (proven server-side in M2_17_5_InventoryReceivingAndReplacementIntegrity
// Test.php). This is the narrow remaining display-side guard for the case where no
// counter data exists at all (e.g. a machine with zero counter readings) - the exact
// numeric coercion (Number(null).toFixed(1) === "0.0") that turned a real Production
// replacement into a misleading "0.0%" instead of an honest "Unknown".
test('ComponentsPage never coerces a null remaining_percent into a numeric 0.0%', () => {
  const guardedOccurrences = componentsPage.match(/row\.remaining_percent == null \? 'Unknown'/g) ?? []
  assert.ok(guardedOccurrences.length >= 2, 'expected both the compact and detailed remaining-percent displays to guard against null')
})
