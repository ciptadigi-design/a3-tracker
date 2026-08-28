import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const page = readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')
const summaryMarkup = page.slice(page.indexOf("summary && activeTab === 'summary'"), page.indexOf('{costDialog'))

test('default Summary preserves four Cost KPIs and adds four Business KPIs', () => {
  for (const label of ['Total Clicks', 'Component Consumption', 'Error / Waste', 'Cost / Click']) {
    assert.match(summaryMarkup, new RegExp(`label="${label.replace('/', '\\/')}"`))
  }
  for (const label of ['Selling Price / Click', 'Estimated Revenue', 'Contribution / Click', 'Estimated Contribution']) {
    assert.match(summaryMarkup, new RegExp(`label="${label.replace('/', '\\/')}"`))
  }
  assert.equal((summaryMarkup.match(/<SummaryCard/g) ?? []).length, 8)
})

test('default Summary removes redundant standalone KPI cards', () => {
  for (const label of ['Data Status', 'Known Consumption Cost', 'Known Cost / Click', 'Unknown Cost Events']) {
    assert.doesNotMatch(summaryMarkup, new RegExp(`label="${label}"`))
  }
  assert.doesNotMatch(summaryMarkup, /label="Standard Machine Cost"/)
})

test('analytical Cost Details content is removed while Operating Costs remains separate', () => {
  for (const removed of ['Cost Details', 'Cost Evidence', 'ComponentBreakdown', 'Component consumption', 'Known Inventory Cost Basis', 'Purchase Cost', 'Completed lifecycles', 'LifecycleEvidence']) {
    assert.doesNotMatch(page, new RegExp(removed))
  }
  assert.match(page, />Operating Costs</)
})

test('primary partial-cost currencies never receive a known suffix', () => {
  assert.doesNotMatch(summaryMarkup, /\$\{[^}]+\} known/)
  assert.match(summaryMarkup, /consumptionDisplay\.value/)
  assert.match(summaryMarkup, /primaryCostPerClickDisplay\.value/)
  assert.match(page, /SummaryStatusBadge/)
})

test('tabs expose semantic selection state', () => {
  assert.match(page, /role="tablist"/)
  assert.equal((page.match(/role="tab"/g) ?? []).length, 2)
  assert.match(page, /aria-selected=/)
})
