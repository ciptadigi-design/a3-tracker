import test from 'node:test'
import assert from 'node:assert/strict'
import { componentCompositionPresentation, costPerClickPresentation, costStatusPresentation, counterEvidencePresentation, economicsStatusPresentation, inventoryContextPresentation, knownConsumptionPresentation, purchaseContextPresentation } from './machineCostPresentation.js'

const money = (value) => `Rp${Number(value)}`

test('complete known cost is presented as complete', () => {
  assert.deepEqual(costStatusPresentation({ cost_status: 'COMPLETE' }), ['Complete', 'All consumption events in this period have known cost.'])
})

test('zero consumption remains a real zero rather than unknown', () => {
  assert.equal(knownConsumptionPresentation({ known_consumption_cost: 0, total_consumption_events: 0, unknown_consumption_events: 0 }, money).value, 'Rp0')
})

test('unknown-only consumption never appears as zero monetary cost', () => {
  const result = knownConsumptionPresentation({ known_consumption_cost: 0, known_consumption_events: 0, unknown_consumption_events: 1 }, money)
  assert.equal(result.value, '—')
  assert.match(result.hint, /unknown acquisition cost/)
})

test('mixed known and unknown consumption labels the known portion', () => {
  assert.deepEqual(knownConsumptionPresentation({ known_consumption_cost: 5700000, known_consumption_events: 2, unknown_consumption_events: 1 }, money), { value: 'Rp5700000 known', hint: '2 known · 1 unknown' })
})

test('missing counter boundaries explain start, end, and both cases', () => {
  assert.match(counterEvidencePresentation({ counter_status: 'INSUFFICIENT_START' }).hint, /Start-of-period/)
  assert.match(counterEvidencePresentation({ counter_status: 'INSUFFICIENT_END' }).hint, /End-of-period/)
  assert.match(counterEvidencePresentation({ counter_status: 'NO_DATA' }).hint, /No start or end/)
})

test('zero clicks are distinct from incomplete evidence', () => {
  assert.match(costPerClickPresentation({ counter_status: 'COMPLETE', total_clicks: 0, known_component_cost_per_click: null }, money).hint, /genuinely zero/)
})

test('NO_DATA remains an honest empty state', () => {
  assert.deepEqual(costStatusPresentation({ cost_status: 'NO_DATA' }), ['No data', 'No relevant machine data exists for this period.'])
})

test('unknown-only component composition reports replacement and unknown basis', () => {
  assert.deepEqual(componentCompositionPresentation({ total_events: 1, unknown_cost_events: 1, known_consumption_cost: 0 }, money), { value: 'Cost basis unknown', meta: '1 replacement', showPercent: false })
})

test('purchase and inventory context retain separate presentation semantics', () => {
  assert.match(purchaseContextPresentation().hint, /does not enter machine cost\/click until the stock is consumed/)
  assert.equal(inventoryContextPresentation({ ending_unknown_inventory_quantity_context: 3 }).label, 'Known Inventory Cost Basis · Branch')
  assert.equal(inventoryContextPresentation({ ending_unknown_inventory_quantity_context: 0 }).label, 'Ending Inventory Cost Basis · Branch')
})
test('machine economics completeness remains explicit', () => {
  assert.match(economicsStatusPresentation({ economics_status: 'PARTIAL' })[1], /excludes/)
  assert.match(economicsStatusPresentation({ economics_status: 'INSUFFICIENT_COUNTER_DATA' })[1], /cannot be calculated/)
})
