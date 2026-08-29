import assert from 'node:assert/strict'
import test from 'node:test'
import { deltaPresentation, priceEvidence, removePersistedReportBranch, reportStatus, reportTabs, validReportFilters } from './reportModel.js'

const filters = { tab: 'overview', machineId: '', preset: 'this_month', customStart: '', customEnd: '', errorCategory: '', errorStatus: '' }

test('Reports exposes the foundation sections plus bounded machine comparison', () => {
  assert.deepEqual(reportTabs.map((tab) => tab.label), ['Overview', 'Machine Performance', 'Machine Economics', 'Machine Comparison', 'Component Consumption', 'Error / Waste', 'Inventory / Purchasing'])
})

test('database delta states render invalid denominators and partial evidence without fabricated percentages', () => {
  assert.deepEqual(deltaPresentation({ delta_status: 'NEW' }), { label: 'New vs previous', tone: 'positive' })
  assert.deepEqual(deltaPresentation({ delta_status: 'PARTIAL' }), { label: 'Partial evidence', tone: 'warning' })
  assert.deepEqual(deltaPresentation({ delta_status: 'NO_COMPARISON' }), { label: 'No comparison', tone: 'neutral' })
  assert.deepEqual(deltaPresentation({ delta_status: 'COMPLETE', delta_percent: 12.44 }), { label: '+12.4% vs previous', tone: 'positive' })
  assert.deepEqual(deltaPresentation({ delta_status: 'COMPLETE', delta_percent: -8.06 }), { label: '-8.1% vs previous', tone: 'negative' })
})
test('persistent report filters accept every shared period preset', () => {
  for (const preset of ['today', 'this_week', 'this_month', 'last_month', 'this_year', 'custom']) assert.equal(validReportFilters({ ...filters, preset }), true)
})

test('persistent report filters reject unknown tabs and malformed scopes', () => {
  assert.equal(validReportFilters({ ...filters, tab: 'maintenance' }), false)
  assert.equal(validReportFilters({ ...filters, machineId: null }), false)
  assert.equal(validReportFilters({ ...filters, errorStatus: null }), false)
})

test('legacy page-level Branch selection is removed without losing other report filters', () => {
  assert.deepEqual(removePersistedReportBranch({ ...filters, branchId: 'tuparev', preset: 'this_year' }), { ...filters, preset: 'this_year' })
  assert.equal(Object.hasOwn(removePersistedReportBranch(filters), 'branchId'), false)
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
