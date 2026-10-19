import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { describeOwnerKpis, NO_RECOVERY_LABEL } from './downtimeModel.js'
import { buildReportExport } from '../reports/reportExport.js'
import { reportTabs, validReportFilters } from '../reports/reportModel.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const overview = read('../../pages/OverviewPage.jsx')
const reports = read('../../pages/ReportsPage.jsx')
const reportTab = read('../reports/DowntimeReportTab.jsx')
const section = read('./DowntimeSection.jsx')
const detail = read('../machines/MachineDetailPage.jsx')
const css = read('../../App.css')

test('KPI labels: missing schedule is "Belum dikonfigurasi", never a fabricated percentage', () => {
  const kpi = describeOwnerKpis({ schedule_status: 'not_configured', availability_percent: null, production_downtime_minutes: null, event_count: 2, average_recovery_minutes: null })
  assert.equal(kpi.scheduleMissing, true)
  assert.equal(kpi.availability, 'Belum dikonfigurasi')
  assert.equal(kpi.productionDowntime, 'Belum dikonfigurasi')
  assert.equal(kpi.eventCount, '2')
  assert.equal(kpi.recovery, NO_RECOVERY_LABEL)
})

test('KPI labels: configured values come straight from the server payload', () => {
  const kpi = describeOwnerKpis({ schedule_status: 'configured', availability_percent: 99.3, production_downtime_minutes: 120, event_count: 3, ongoing_event_count: 1, completed_event_count: 2, average_recovery_minutes: 90 })
  assert.equal(kpi.availability, '99.3%')
  assert.equal(kpi.productionDowntime, '2j 0m')
  assert.equal(kpi.recovery, '1j 30m')
  assert.equal(kpi.ongoingCount, 1)
  assert.equal(kpi.completedCount, 2)
})

test('KPI labels: empty data keeps zero events without inventing a recovery time', () => {
  const kpi = describeOwnerKpis({ schedule_status: 'configured', availability_percent: 100, production_downtime_minutes: 0, event_count: 0, average_recovery_minutes: null })
  assert.equal(kpi.eventCount, '0')
  assert.equal(kpi.recovery, NO_RECOVERY_LABEL)
  assert.equal(describeOwnerKpis(null).scheduleMissing, true)
})

test('KPI math is never duplicated in the frontend: no availability or recovery arithmetic in the UI', () => {
  for (const source of [overview, reportTab, read('./downtimeModel.js')]) assert.doesNotMatch(source, /scheduled_minutes\s*[-/*]|production_downtime_minutes\s*\/|\/\s*scheduled/)
  assert.doesNotMatch(reportTab, /\.reduce\(|Math\.round\(/)
})

test('Overview Kesehatan Mesin shows the five concise indicators for the selected machine, after Daily Click Performance', () => {
  for (const label of ['Status Saat Ini', 'Ketersediaan', 'Downtime Produksi', 'Jumlah Downtime', 'Rata-rata Pemulihan']) assert.match(overview, new RegExp(label))
  assert.match(overview, /describeOwnerKpis\(overview\)/)
  assert.match(overview, /machineId: machine\.id/)
  assert.ok(overview.indexOf('<DailyClickPerformanceChart') < overview.indexOf('<MachineHealthSection key='))
  assert.doesNotMatch(overview, /machineConditionPath|StartDowntimeDialog|Lapor Gangguan/, 'no duplicated Maintenance controls')
})

test('Reports gets a permission-gated Downtime section that reuses the page filters and export', () => {
  assert.ok(reportTabs.some((tab) => tab.id === 'downtime'))
  assert.equal(validReportFilters({ tab: 'downtime', preset: 'this_month', machineId: '', customStart: '', customEnd: '', errorCategory: '', errorStatus: '' }), true)
  assert.match(reports, /tab\.id !== 'downtime' \|\| can\('downtime\.view'\)/)
  assert.match(reports, /<DowntimeReportTab account=\{account\} branch=\{branch\} machineId=\{filters\.machineId\} period=\{period\}/)
  assert.match(reports, /report: activeTab === 'downtime' \? downtime : report/)
  assert.match(reportTab, /detail: 1/)
})

test('Reports > Downtime covers trend, machine ranking, frequent causes and recovery with empty states', () => {
  for (const title of ['Tren Downtime Produksi', 'Mesin dengan Downtime Produksi Tertinggi', 'Penyebab Paling Sering', 'Kode Error Paling Sering', 'Rata-rata Pemulihan', 'Tidak ada downtime pada periode ini', 'Belum dikonfigurasi']) assert.match(reportTab, new RegExp(title))
})

test('downtime CSV export mirrors the on-screen server figures and keeps unconfigured values explicit', () => {
  const data = { schedule_status: 'not_configured', availability_percent: null, production_downtime_minutes: null, event_count: 1, ongoing_event_count: 0, completed_event_count: 1, average_recovery_minutes: 45, trend: [{ bucket: '2026-10', production_downtime_minutes: null }], ranking: [], causes: [{ cause: 'jam', count: 1 }], error_codes: [] }
  const result = buildReportExport({ tab: 'downtime', report: data, periodStart: '2026-10-01', periodEnd: '2026-10-31', branchLabel: 'MAIN' })
  assert.match(result.filename, /downtime-analytics-main-2026-10-01-to-2026-10-31\.csv$/)
  assert.ok(result.rows.some((row) => row.Item === 'Ketersediaan Mesin (%)' && row.Value === 'Belum dikonfigurasi'))
  assert.ok(result.rows.some((row) => row.Section === 'Penyebab' && row.Item === 'jam'))
})

test('Machine Detail is status-only: one "Kelola di Maintenance" action, no Lapor Gangguan, no placeholder modules', () => {
  assert.match(section, /Kelola di Maintenance/)
  assert.doesNotMatch(section, /Lapor Gangguan|Kelola Downtime/)
  assert.doesNotMatch(detail, /future-module|Coming later|Connected workflows will arrive later|Operational modules/)
  assert.doesNotMatch(css, /future-module/)
  assert.match(section, /machineConditionPath\(machine\.id\)/)
})

test('Mobile layout: stacked headers keep the content-height fix and the Overview KPI card stays compact', () => {
  assert.match(css, /\.page-header-copy \{ flex: 0 0 auto; min-width: 0; \}/)
  assert.match(css, /\.machine-health-card \.overview-target-metrics \{ grid-template-columns: repeat\(5, minmax\(0, 1fr\)\); \}/)
  assert.match(css, /@media \(max-width: 1100px\) \{ \.machine-health-card \.overview-target-metrics \{ grid-template-columns: repeat\(3/)
  assert.match(css, /\.machine-health-card \.overview-target-metrics \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); \}/)
  assert.match(css, /\.report-two-column \{[^}]*repeat\(2, minmax\(0, 1fr\)\)/)
})
