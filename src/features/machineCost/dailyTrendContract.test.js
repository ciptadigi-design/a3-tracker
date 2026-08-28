import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const page = fs.readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const service = fs.readFileSync(new URL('../../services/supabase/machineCost.js', import.meta.url), 'utf8')

test('Summary uses a responsive 2 by 2 primary grid and retains four intended cards', () => {
  assert.match(css, /\.machine-economics-summary-grid[^\n]+grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(css, /@media \(max-width: 760px\)[\s\S]+\.machine-economics-summary-grid[^\n]+grid-template-columns: minmax\(0,1fr\)/)
  assert.equal((page.match(/<SummaryCard/g) ?? []).length, 4)
})

test('daily operational chart labels independent click and rupiah scales', () => {
  assert.match(page, /Daily Click &amp; Cost Trend/)
  assert.match(page, /Bars use the left clicks axis/)
  assert.match(page, /Known cost \(IDR\)/)
  assert.match(page, /Known Daily Cost/)
})

test('daily trend is loaded from the database RPC and missing cost is not plotted', () => {
  assert.match(service, /get_machine_cost_daily_trend/)
  assert.match(page, /row\.knownCost == null \? null/)
  assert.match(page, /Unknown evidence remains explicit and is not converted to zero/)
})
