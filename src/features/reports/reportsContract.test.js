import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/ReportsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/reports.js', import.meta.url), 'utf8')
const model = readFileSync(new URL('./reportModel.js', import.meta.url), 'utf8')

test('seven sections are reachable through semantic tab navigation', () => {
  assert.match(page, /role="tablist"/); assert.match(page, /role="tab"/); assert.match(page, /aria-selected=/)
  for (const label of ['Overview', 'Counter / Usage', 'Machine Cost', 'Component / Replacement', 'Incident / Error / Waste', 'Operator Activity', 'Inventory Consumption']) assert.match(model, new RegExp(label.replaceAll('/', '\\/')))
})
test('global filters persist across section changes', () => { assert.match(page, /usePersistentUIState/); assert.match(page, /feature: 'reports-filters'/); assert.match(page, /setFilters\(\(current\) => \(\{ \.\.\.current, tab:/) })
test('canonical RPCs remain the reporting authority', () => { for (const rpc of ['get_report_overview', 'get_report_machine_economics', 'get_report_daily_clicks', 'get_report_component_consumption', 'get_report_error_waste']) assert.match(service, new RegExp(rpc)); assert.doesNotMatch(page, /get_machine_economics_period|supabase\.rpc/) })
test('unknown and zero-click semantics are explicit in presentation', () => { assert.match(page, /value == null \? '—'/); assert.match(page, /Unavailable when period clicks are zero/); assert.match(page, /Consumed Cost/) })
test('operator and PIC are separate report fields', () => { assert.match(page, /data-label="Operator"/); assert.match(page, /data-label="PIC Terlibat"/); assert.match(page, /Recorded Usage/) })
test('section-specific empty states and pagination are present', () => { for (const phrase of ['No counter readings in this period', 'No replacement activity in this period', 'No incidents in this period', 'No operator activity in this period', 'No inventory consumption in this period']) assert.match(page, new RegExp(phrase)); assert.match(page, /usePagination/); assert.match(page, /Pagination/) })
