import assert from 'node:assert/strict'
import test from 'node:test'
import { buildReportExport, createCsv } from './reportExport.js'

const report = {
  machineComparison: [{ machine_code: 'RPT-A1', machine_name: 'Alpha', branch_code: 'JKT', branch_name: 'Jakarta', total_clicks: 120,
    standard_cost_per_click: 10, error_waste_cost: 100, estimated_machine_revenue: 96000, estimated_standard_contribution: 94800,
    standard_contribution_margin_percent: 98.75, comparison_status: 'COMPLETE' }],
  performance: [{ machine_code: 'RPT-A1', machine_name: 'Alpha', branch_code: 'JKT', branch_name: 'Jakarta', total_clicks: 120,
    active_days: 2, daily_average_clicks: 60, latest_counter: 1120, last_input_at: '2026-08-20T03:00:00Z', counter_status: 'COMPLETE' }],
  economics: [{ machine_code: 'RPT-A1', machine_name: 'Alpha', branch_code: 'JKT', branch_name: 'Jakarta', total_clicks: 120,
    component_consumption_cost: 1000, error_waste_cost: 100, standard_machine_cost: 1100, standard_cost_per_click: 9.1667,
    priced_clicks: 120, unpriced_clicks: 0, estimated_machine_revenue: 96000, revenue_status: 'COMPLETE',
    estimated_standard_contribution: 94900, standard_contribution_margin_percent: 98.8542, standard_contribution_status: 'COMPLETE',
    advanced_enabled: false, full_machine_operating_cost: null, estimated_full_contribution: null }],
  components: [{ component_code: 'TONER_C', component_name: 'Toner Cyan', component_category: 'Toner', machine_code: 'RPT-A1',
    machine_name: 'Alpha', branch_name: 'Jakarta', replacement_count: 1, known_consumed_cost: 1000, unknown_cost_events: 0, average_observed_yield: 120 }],
  errors: [{ occurred_at: '2026-08-18T03:00:00Z', branch_code: 'JKT', branch_name: 'Jakarta', attribution_scope: 'BRANCH_ONLY',
    machine_code: null, machine_name: null, category: 'bahan', incident_type: 'human', responsible_name: null, status: 'open',
    material_loss: 100, service_loss: 0, assessed_loss: 100, description: 'Comma, quote " safe' }],
  purchases: [{ purchase_number: 'PO-01', item_sku: null, item_name: 'Toner Cyan', supplier_name: 'Supplier A', purchase_date: '2026-08-10',
    status: 'partially_received', ordered_quantity: 4, unit: 'bottle', unit_price: 1000, line_total: 4000, received_quantity: 2, remaining_quantity: 2 }],
  stock: [{ sku: null, item_name: 'Toner Cyan', component_name: 'Toner Cyan', status: 'LOW_STOCK', total_stock: 2, unit: 'bottle',
    location_breakdown: [{ location_name: 'Main Warehouse', quantity: 2 }] }],
}

test('CSV escapes commas and quotes and never serializes undefined', () => {
  const csv = createCsv([{ Name: 'A, "quoted" value', Optional: undefined }])
  assert.match(csv, /"A, ""quoted"" value"/)
  assert.doesNotMatch(csv, /undefined|\[object Object\]/)
})

for (const tab of ['overview','performance','economics','comparison','components','errors','inventory']) {
  test(`${tab} export uses human-readable authorized report projection`, () => {
    const exported = buildReportExport({ tab, report, periodStart: '2026-08-01', periodEnd: '2026-08-31', branchLabel: 'JKT' })
    assert.match(exported.filename, new RegExp(`a3-tracker-.+-jkt-2026-08-01-to-2026-08-31\\.csv`))
    assert.doesNotMatch(exported.csv, /client_request_id|inventory_item_id|incident_id|machine_id/i)
    assert.doesNotMatch(exported.csv, /undefined|\[object Object\]/)
  })
}

test('inventory/purchasing export keeps optional SKU and location context readable', () => {
  const exported = buildReportExport({ tab: 'inventory', report, periodStart: '2026-08-01', periodEnd: '2026-08-31' })
  assert.match(exported.csv, /Purchasing/)
  assert.match(exported.csv, /Inventory/)
  assert.match(exported.csv, /No SKU/)
  assert.match(exported.csv, /Main Warehouse: 2/)
})
