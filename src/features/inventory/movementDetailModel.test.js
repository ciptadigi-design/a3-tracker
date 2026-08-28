import test from 'node:test'
import assert from 'node:assert/strict'
import { buildMovementDetail } from './movementDetailModel.js'

const formatters = { formatCurrency: (value) => `Rp${Number(value)}`, formatQuantity: (value) => String(Number(value)), formatTime: () => '28 Aug 2026, 11:00' }
const base = { movement_id: 'raw-movement-uuid', reference_id: 'raw-reference-uuid', item_name: 'Toner Cyan', movement_type: 'opening_balance', reference_type: 'opening_balance', quantity: 1, unit_snapshot: 'bottle', location_name: 'CG Digital Print', operational_person_name_snapshot: 'Akmal Fauzan', occurred_at: '2026-08-28T04:00:00Z', created_by_name_snapshot: 'Admin' }
const fields = (detail, section) => Object.fromEntries(detail.sections.find((item) => item.title === section).fields)

test('opening balance exposes readable movement and audit evidence without raw identifiers', () => {
  const detail = buildMovementDetail({ ...base, inbound_cost_layer_count: 1, inbound_cost_is_complete: true, inbound_known_acquisition_cost: 250000 }, null, formatters)
  assert.equal(detail.movementType, 'Opening Balance')
  assert.equal(fields(detail, 'Cost Evidence')['Cost basis'], 'Known')
  assert.doesNotMatch(JSON.stringify(detail), /raw-movement-uuid|raw-reference-uuid/)
})

test('purchase receipt exposes supplier, purchase, receipt, price, and acquisition evidence', () => {
  const detail = buildMovementDetail({ ...base, movement_type: 'receipt', reference_type: 'purchase_receipt', receipt_supplier_name: 'PT Supplier', receipt_purchase_number: 'PO-2026-001', receipt_number: 'RCV-001', receipt_unit_price: 200000, receipt_acquisition_value: 400000, inbound_cost_layer_count: 1, inbound_cost_is_complete: true, inbound_known_acquisition_cost: 400000 }, null, formatters)
  assert.deepEqual(fields(detail, 'Reference'), { Type: 'Purchase Receipt', Supplier: 'PT Supplier', 'Purchase Number': 'PO-2026-001', Receipt: 'RCV-001', 'Unit Acquisition Price': 'Rp200000 / bottle' })
  assert.equal(fields(detail, 'Cost Evidence')['Receipt acquisition value'], 'Rp400000')
})

test('component replacement issue exposes machine, component, context, and partial FIFO cost', () => {
  const detail = buildMovementDetail({ ...base, movement_type: 'issue', reference_type: 'component_replacement', quantity: -1, replacement_machine_code: 'CG-TUP-A3-01', replacement_machine_name: 'Konica C1070', replacement_component_name: 'Toner Cyan', cost_layer_count: 2, cost_is_complete: false, allocated_cost: 180000, unknown_cost_quantity: 0.25 }, null, formatters)
  assert.equal(fields(detail, 'Reference').Machine, 'CG-TUP-A3-01 · Konica C1070')
  assert.equal(fields(detail, 'Reference').Component, 'Toner Cyan')
  assert.equal(fields(detail, 'Cost Evidence')['Cost basis'], 'Partially known')
})

test('adjustment shows only relevant reason and known inbound cost evidence', () => {
  const detail = buildMovementDetail({ ...base, movement_type: 'adjustment_in', reference_type: 'manual_adjustment', reason: 'Physical count correction', inbound_cost_layer_count: 1, inbound_cost_is_complete: true, inbound_known_acquisition_cost: 50000 }, null, formatters)
  assert.equal(fields(detail, 'Reference').Type, 'Manual Adjustment')
  assert.equal(fields(detail, 'Audit').Reason, 'Physical count correction')
  assert.equal(fields(detail, 'Cost Evidence')['Known acquisition cost'], 'Rp50000')
})

test('transfer pairs source and destination names without exposing transfer identity', () => {
  const outbound = { ...base, movement_type: 'transfer_out', reference_type: 'stock_transfer', quantity: -1, location_name: 'Warehouse A', cost_layer_count: 1, cost_is_complete: true, allocated_cost: 100000 }
  const inbound = { ...base, movement_type: 'transfer_in', reference_type: 'stock_transfer', location_name: 'Machine Floor' }
  const detail = buildMovementDetail(outbound, inbound, formatters)
  assert.equal(fields(detail, 'Reference').Source, 'Warehouse A')
  assert.equal(fields(detail, 'Reference').Destination, 'Machine Floor')
  assert.doesNotMatch(JSON.stringify(detail), /transfer_id|uuid/)
})
