import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/ReportsPage.jsx', import.meta.url), 'utf8')
const detail = readFileSync(new URL('./ReportDetailDialog.jsx', import.meta.url), 'utf8')
const blockingDialog = readFileSync(new URL('../../components/ui/BlockingDialog.jsx', import.meta.url), 'utf8')
const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const migration = readFileSync(new URL('../../../supabase/migrations/20260828001400_reports_export_comparison.sql', import.meta.url), 'utf8')

test('every read-only report detail has one visible header dismissal control and no footer action', () => {
  assert.equal((detail.match(/aria-label="Close report detail"/g) ?? []).length, 1)
  assert.doesNotMatch(detail, /<footer|dialog-actions|>Close<\/button>/)
  assert.match(detail, /<BlockingDialog/)
})

test('shared dialog preserves Escape, backdrop, focus trap, scroll lock, and focus restoration', () => {
  assert.match(blockingDialog, /event\.key === 'Escape'/)
  assert.match(blockingDialog, /event\.key !== 'Tab'/)
  assert.match(blockingDialog, /previouslyFocused\.focus\(\)/)
  assert.match(blockingDialog, /document\.body\.style\.overflow = 'hidden'/)
  assert.match(blockingDialog, /closeOnBackdrop/)
})

test('comparison and ranking remain modular PostgreSQL contracts', () => {
  for (const fn of ['get_report_period_comparison','get_report_machine_comparison','get_report_component_ranking','get_report_error_summary','get_report_inventory_analytics']) assert.match(migration, new RegExp(`function public\\.${fn}`))
  assert.match(migration, /else target_period_start-v_duration/)
  assert.match(migration, /delta_status text/)
  assert.match(migration, /PARTIAL_PRICE/)
  assert.match(migration, /PARTIAL_COST/)
})

test('Reports presents current/previous, one selectable machine chart, and qualified component ranking', () => {
  assert.match(page, /Current vs Previous/)
  assert.match(page, /Period Comparison/)
  assert.match(page, /Chart metric/)
  assert.match(page, /Partial evidence remains visible but is not ranked/)
  assert.match(page, /Known cost denominator/)
  assert.match(page, /By \{dimension\.toLowerCase\(\)\}/)
})

test('export and browser print actions are filter-derived and non-mutating', () => {
  assert.match(page, /buildReportExport\(\{ tab: filters\.tab/)
  assert.match(page, /Export CSV/)
  assert.match(page, /Print \/ Save PDF/)
  assert.match(page, /window\.print\(\)/)
  assert.doesNotMatch(page, /insert\(|update\(|delete\(/)
})

test('print and responsive contracts cover light output and mobile stacking', () => {
  assert.match(css, /@media print/)
  assert.match(css, /:root\[data-theme="dark"\].+color-scheme: light/)
  assert.match(css, /\.sidebar-wrap,.topbar.+display: none !important/)
  assert.match(css, /@media \(max-width: 520px\)[\s\S]+\.report-error-summary.+minmax\(0,1fr\)/)
})
