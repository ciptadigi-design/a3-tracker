import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const page = readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')
const summaryMarkup = page.slice(page.indexOf("activeTab === 'summary'"), page.indexOf(": summary && activeTab === 'details'"))
const detailsMarkup = page.slice(page.indexOf("activeTab === 'details'"), page.indexOf('{costDialog'))

test('default Summary contains exactly the four launch-facing KPI labels', () => {
  for (const label of ['Total Clicks', 'Component Consumption', 'Error / Waste', 'Cost / Click']) {
    assert.match(summaryMarkup, new RegExp(`label="${label.replace('/', '\\/')}"`))
  }
  assert.equal((summaryMarkup.match(/<SummaryCard/g) ?? []).length, 4)
})

test('default Summary removes redundant standalone KPI cards', () => {
  for (const label of ['Data Status', 'Known Consumption Cost', 'Known Cost / Click', 'Unknown Cost Events']) {
    assert.doesNotMatch(summaryMarkup, new RegExp(`label="${label}"`))
  }
  assert.doesNotMatch(summaryMarkup, /label="Standard Machine Cost"/)
})

test('Cost Details retains audit evidence while Operating Costs remains a separate tab', () => {
  for (const evidence of ['Component Cost / Click', 'Unknown component events', 'purchaseDisplay', 'inventoryDisplay', 'ComponentBreakdown', 'LifecycleEvidence']) {
    assert.match(detailsMarkup, new RegExp(evidence))
  }
  assert.match(page, />Cost Details</)
  assert.match(page, />Operating Costs</)
})

test('tabs expose semantic selection state', () => {
  assert.match(page, /role="tablist"/)
  assert.equal((page.match(/role="tab"/g) ?? []).length, 3)
  assert.match(page, /aria-selected=/)
})
