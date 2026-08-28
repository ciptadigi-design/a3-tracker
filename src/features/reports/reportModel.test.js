import assert from 'node:assert/strict'
import test from 'node:test'
import { priceEvidence, reportStatus, reportTabs, validReportFilters } from './reportModel.js'

const filters = { tab: 'overview', branchId: '', machineId: '', preset: 'this_month', customStart: '', customEnd: '', errorCategory: '', errorStatus: '' }

test('Reports exposes the six bounded operational sections', () => {
  assert.deepEqual(reportTabs.map((tab) => tab.label), ['Overview', 'Machine Performance', 'Machine Economics', 'Component Consumption', 'Error / Waste', 'Inventory / Purchasing'])
})
test('persistent report filters accept every shared period preset', () => {
  for (const preset of ['today', 'this_week', 'this_month', 'last_month', 'this_year', 'custom']) assert.equal(validReportFilters({ ...filters, preset }), true)
})

test('persistent report filters reject unknown tabs and malformed scopes', () => {
  assert.equal(validReportFilters({ ...filters, tab: 'maintenance' }), false)
  assert.equal(validReportFilters({ ...filters, branchId: null }), false)
  assert.equal(validReportFilters({ ...filters, errorStatus: null }), false)
})

test('completeness statuses remain concise and explicit', () => {
  assert.deepEqual(reportStatus('COMPLETE'), ['Complete', 'success'])
  assert.deepEqual(reportStatus('PARTIAL_COST'), ['Partial cost evidence', 'warning'])
  assert.deepEqual(reportStatus('PARTIAL_PRICE'), ['Partial price evidence', 'warning'])
  assert.deepEqual(reportStatus('NO_COUNTER_DATA'), ['Counter data incomplete', 'neutral'])
  assert.deepEqual(reportStatus('NO_DATA'), ['No data', 'neutral'])
})

test('price evidence distinguishes absent, single, and historical prices', () => {
  const money = (value) => `Rp${Number(value)}`
  assert.equal(priceEvidence({ revenue_status: 'NO_PRICE' }, money), 'Not configured')
  assert.equal(priceEvidence({ period_price_count: 1, period_end_selling_price_per_click: 800 }, money), 'Rp800')
  assert.equal(priceEvidence({ period_price_count: 2, period_end_selling_price_per_click: 850 }, money), '2 historical prices')
})
