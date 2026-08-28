import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const page = readFileSync(new URL('../../pages/MachineCostPage.jsx', import.meta.url), 'utf8')
const dialog = readFileSync(new URL('./SellingPriceDialog.jsx', import.meta.url), 'utf8')
const history = readFileSync(new URL('./SellingPriceHistoryDialog.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/machineCost.js', import.meta.url), 'utf8')
const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

test('Machine Cost owns set/change/history selling-price workflow', () => {
  assert.match(page, /Set selling price/)
  assert.match(page, /'Change'/)
  assert.match(page, />History</)
  assert.match(service, /create_machine_selling_price/)
  assert.match(service, /void_machine_selling_price/)
})

test('mutation controls are owner/admin only while economics remain readable', () => {
  assert.match(page, /canManageCosts && <button type="button" onClick=\{\(\) => setPriceDialog\(true\)\}/)
  assert.match(page, /canManage=\{canManageCosts\}/)
  assert.doesNotMatch(page, /canManageCosts && <section className="machine-economics-summary-grid business/)
})

test('selling-price form reuses BlockingDialog and persistent machine-scoped drafts', () => {
  assert.match(dialog, /<BlockingDialog/)
  assert.match(dialog, /usePersistentDraft/)
  assert.match(dialog, /feature: 'machine-selling-price'/)
  assert.match(dialog, /busy=\{saving\}/)
  assert.match(dialog, /Save Selling Price/)
})

test('history is human-readable and correction requires a reason', () => {
  assert.match(history, /Created by/)
  assert.doesNotMatch(history, />\{price\.id\}</)
  assert.match(history, /Correction \/ Void Reason \*/)
  assert.match(history, /Correction \/ void reason is required/)
})

test('responsive hierarchy has no horizontal-only mobile contract', () => {
  assert.match(css, /\.selling-price-history-list article[^\n]+grid-template-columns:/)
  assert.match(css, /@media \(max-width: 760px\)[\s\S]+\.selling-price-history-list article[^\n]+grid-template-columns: minmax\(0,1fr\)/)
  assert.match(css, /overflow-y: auto/)
})

test('Daily Click Trend remains clicks-only after M2.5C', () => {
  const chart = page.slice(page.indexOf('function DailyTrendChart'), page.indexOf('export function MachineCostPage'))
  assert.match(chart, /Daily Click Trend/)
  assert.doesNotMatch(chart, /revenue|contribution|cost line|second axis/i)
})
