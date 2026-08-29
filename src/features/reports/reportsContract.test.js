import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/ReportsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/reports.js', import.meta.url), 'utf8')
const detail = readFileSync(new URL('./ReportDetailDialog.jsx', import.meta.url), 'utf8')
const blockingDialog = readFileSync(new URL('../../components/ui/BlockingDialog.jsx', import.meta.url), 'utf8')
const shell = readFileSync(new URL('../../app/AppShell.jsx', import.meta.url), 'utf8')
const sidebar = readFileSync(new URL('../../components/layout/Sidebar.jsx', import.meta.url), 'utf8')
const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const migration = readFileSync(new URL('../../../supabase/migrations/20260828001300_reports_foundation.sql', import.meta.url), 'utf8')

test('Reports is a live route while Maintenance remains excluded', () => {
  assert.match(shell, /path === '\/reports'\) page = <ReportsPage/)
  assert.match(sidebar, /path: '\/reports'.+active: true/)
  assert.match(sidebar, /path: '\/maintenance'.+Wrench }/)
  assert.doesNotMatch(page, /maintenance/i)
})
test('report filters reuse the global Branch and preserve subordinate Machine and period controls', () => {
  assert.match(page, /machineCostPeriodPresets, resolveMachineCostPeriod/)
  assert.match(page, /usePersistentUIState/)
  assert.match(page, /feature: 'reports-filters'/)
  for (const label of ['All Machines in', 'Period', 'Start', 'End']) assert.match(page, new RegExp(label))
  assert.doesNotMatch(page, /<span>Branch<\/span>|All Branches/)
  assert.match(page, /loadMachines\(\{ accountId: account\.id, branchId: branch\.id \}\)/)
  assert.match(page, /branchId: branch\.id/)
})

test('report tabs are semantic and keyboard-native', () => {
  assert.match(page, /role="tablist"/)
  assert.match(page, /role="tab"/)
  assert.match(page, /aria-selected=/)
})

test('Overview has exactly six primary KPI instances and explicit terminology', () => {
  const overview = page.slice(page.indexOf("filters.tab === 'overview'"), page.indexOf("filters.tab === 'performance'"))
  assert.equal((overview.match(/<ReportKpi/g) ?? []).length, 6)
  assert.match(overview, /Estimated Machine Revenue/)
  assert.match(overview, /Estimated Contribution/)
  assert.match(overview, /not invoice revenue/)
  assert.match(overview, /not net profit/)
})

test('PostgreSQL RPCs remain the report aggregation authority', () => {
  for (const rpc of ['get_report_overview', 'get_report_machine_performance', 'get_report_machine_economics', 'get_report_daily_clicks', 'get_report_component_consumption', 'get_report_error_waste', 'get_report_inventory_analytics', 'get_report_purchase_lines', 'get_report_inventory_stock', 'get_report_period_comparison', 'get_report_machine_comparison', 'get_report_component_ranking', 'get_report_error_summary']) assert.match(service, new RegExp(rpc))
  assert.match(service, /get_report_purchase_lines[\s\S]+target_branch_id: branchId/)
  assert.doesNotMatch(page, /\.reduce\(/)
})

test('machine economics projects the accepted M2.5C contract', () => {
  assert.match(migration, /cross join lateral public\.get_machine_economics_period/)
  assert.match(page, /standard_contribution_status/)
  assert.match(page, /advanced_enabled &&/)
  assert.match(page, /Full:/)
})

test('daily clicks use only effective authoritative counter usage', () => {
  assert.match(migration, /public\.machine_counter_history/)
  assert.match(migration, /history\.status='effective'/)
  assert.match(migration, /lower\(btrim\(history\.counter_type_code\)\)='total_impressions'/)
  assert.match(page, /Daily Click Trend/)
  assert.doesNotMatch(page.slice(page.indexOf('function DailyClicksChart'), page.indexOf('function ReportList')), /revenue|contribution|cost line|second axis/i)
})

test('component, incident, inventory, and purchase sections preserve evidence boundaries', () => {
  assert.match(page, /unknown_cost_events/)
  assert.match(page, /Branch only/)
  assert.match(page, /Purchase creation does not change stock/)
  assert.match(page, /never become Machine Cost until consumed/)
})

test('read-only detail follows Eye to BlockingDialog convention', () => {
  assert.match(page, /aria-label="View purchase detail"/)
  assert.match(page, /aria-label="View Error \/ Waste detail"/)
  assert.match(detail, /<BlockingDialog/)
  assert.match(detail, /aria-label="Close report detail"/)
  assert.doesNotMatch(detail, /dialog-actions|>Close<\/button>/)
  assert.match(blockingDialog, /event\.key === 'Escape'/)
  assert.match(blockingDialog, /previouslyFocused\.focus\(\)/)
  assert.doesNotMatch(page, />Edit<|>Delete</)
})

test('report services and UI expose no operational mutation', () => {
  assert.doesNotMatch(service, /\.insert\(|\.update\(|\.delete\(/)
  assert.doesNotMatch(page, /createPurchase|receiveInventory|replaceMachine|createIncident/)
})

test('responsive hierarchy contracts cover desktop, tablet, and 390px-class mobile', () => {
  assert.match(css, /\.report-kpi-grid[^\n]+repeat\(3,minmax\(0,1fr\)\)/)
  assert.match(css, /@media \(max-width: 1180px\)[\s\S]+\.report-kpi-grid \{ grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(css, /@media \(max-width: 520px\)[\s\S]+\.report-kpi-grid.+minmax\(0,1fr\)/)
  assert.match(css, /\.report-table-row[^\n]+overflow-wrap: anywhere/)
})

test('zero-data and partial-evidence presentations are explicit', () => {
  assert.match(page, /No machine performance data/)
  assert.match(page, /No component consumption/)
  assert.match(page, /No Error \/ Waste evidence/)
  assert.match(page, /No purchases in period/)
  assert.match(page, /StatusBadge status=/)
})
