import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const daily = read('../../features/counters/CounterHistory.jsx')
const inventory = `${read('../../pages/InventoryPage.jsx')}\n${read('../../features/inventory/PurchasingPanel.jsx')}`
const errors = read('../../features/incidents/IncidentHistory.jsx')
const reports = read('../../pages/ReportsPage.jsx')
const css = read('../../App.css')

test('Daily, Inventory, Errors, and Reports share one pagination architecture', () => {
  for (const source of [daily, inventory, errors, reports]) {
    assert.match(source, /usePagination/)
    assert.match(source, /<Pagination/)
  }
  assert.equal((inventory.match(/usePagination/g) ?? []).length >= 4, true)
})

test('Branch, Machine, filters, and tabs contribute reset keys before pagination', () => {
  assert.match(daily,/resetKey/)
  assert.match(inventory,/data\.branchId/)
  assert.match(errors,/resetKey/)
  assert.match(reports,/branch\?\.id[\s\S]*filters\.machineId[\s\S]*filters\.preset/)
})

test('Reports summaries remain outside page-local ReportList slicing', () => {
  const reportList = reports.slice(reports.indexOf('function ReportList'), reports.indexOf('function PeriodComparison'))
  assert.match(reportList,/rows\.slice/)
  assert.doesNotMatch(reportList,/reduce\(|total_clicks|assessed_loss|purchase_value/)
  assert.match(reports,/report\?\.overview/)
})

test('mobile pagination uses compact page context with no page-number strip', () => {
  const mobile = css.slice(css.indexOf('@media (max-width: 430px)'))
  assert.match(mobile,/\.pagination-pages \{ display: none;/)
  assert.match(mobile,/\.pagination-mobile-page \{ display: inline;/)
  assert.match(mobile,/\.initialize-label-full \{ display: none;/)
})
