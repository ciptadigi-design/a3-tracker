import assert from 'node:assert/strict'
import test from 'node:test'
import { reportTabs, validReportFilters } from './reportModel.js'

test('Reports exposes the seven canonical sections', () => assert.deepEqual(reportTabs.map((tab) => tab.label), ['Overview', 'Counter / Usage', 'Machine Cost', 'Component / Replacement', 'Incident / Error / Waste', 'Operator Activity', 'Inventory Consumption']))
test('persistent filters accept shared periods and reject malformed scopes', () => {
  const base = { tab: 'overview', machineId: '', customStart: '', customEnd: '', errorCategory: '', errorStatus: '' }
  for (const preset of ['today', 'this_week', 'this_month', 'last_month', 'this_year', 'custom']) assert.equal(validReportFilters({ ...base, preset }), true)
  assert.equal(validReportFilters({ ...base, preset: 'this_month', machineId: null }), false)
  assert.equal(validReportFilters({ ...base, preset: 'this_month', tab: 'maintenance' }), false)
})
