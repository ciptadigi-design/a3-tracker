import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const page = fs.readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const service = fs.readFileSync(new URL('../../services/supabase/machineCost.js', import.meta.url), 'utf8')

test('Summary uses responsive 2 by N Cost and Business grids with eight intended cards', () => {
  assert.match(css, /\.machine-economics-summary-grid[^\n]+grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(css, /@media \(max-width: 760px\)[\s\S]+\.machine-economics-summary-grid[^\n]+grid-template-columns: minmax\(0,1fr\)/)
  assert.equal((page.match(/<SummaryCard/g) ?? []).length, 8)
})

test('daily operational chart presents only daily clicks', () => {
  assert.match(page, /<h2>Daily Click Trend<\/h2>/)
  assert.match(page, /Daily machine usage for the selected period/)
  assert.match(page, /<text className="trend-axis-title"[^>]*>Clicks<\/text>/)
  assert.doesNotMatch(page, /Known cost \(IDR\)/)
  assert.doesNotMatch(page, /Known Daily Cost/)
  assert.doesNotMatch(page, /trend-cost-line|trend-cost-point|trend-unknown-marker|Partial evidence/)
})

test('daily click trend retains the authoritative database RPC', () => {
  assert.match(service, /get_machine_cost_daily_trend/)
  assert.match(page, /summary\.daily_trend/)
})

test('positive bars show exact formatted clicks and tooltips contain date and clicks only', () => {
  assert.match(page, /Number\(row\.clicks \?\? 0\) <= 0 \? null/)
  assert.match(page, /className="trend-click-value"/)
  assert.match(page, /formatDailyClicks\(row\.clicks\)/)
  assert.match(page, /Clicks: \$\{formatDailyClicks\(row\.clicks\)\}/)
  assert.doesNotMatch(page, /Known Cost:|Cost evidence:/)
})

test('chart has honest empty state, responsive tick density, and no duplicate Total Clicks card', () => {
  assert.match(page, /No recorded click activity in this period/)
  assert.match(page, /new ResizeObserver/)
  assert.match(page, /Math\.floor\(plotWidth \/ 70\)/)
  assert.match(page, /denseLabels \? `rotate\(-55/)
  assert.equal((page.match(/label="Total Clicks"/g) ?? []).length, 1)
  assert.match(css, /\.machine-cost-chart \{ width: 100%; height: auto; display: block;/)
})
