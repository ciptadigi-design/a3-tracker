import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const overview = read('../../pages/OverviewPage.jsx')
const sidebar = read('../../components/layout/Sidebar.jsx')
const service = read('../../services/downtime.js')
const section = read('./DowntimeSection.jsx')
const condition = read('./MachineConditionSection.jsx')

test('Overview order: Machine Health renders after Daily Click Performance, never above the click target', () => {
  const chart = overview.indexOf('<DailyClickPerformanceChart')
  const health = overview.indexOf('<MachineHealthSection key=')
  const hero = overview.indexOf('overview-target-hero glass-surface')
  assert.ok(chart > 0 && health > chart, 'Machine Health must follow the Daily Click Performance chart')
  assert.ok(hero > 0 && hero < chart)
  assert.equal(overview.split('<MachineHealthSection ').length - 1, 1, 'exactly one Machine Health section')
})

test('Overview Machine Health is scoped to the selected machine and uses the shared summary logic', () => {
  assert.match(overview, /machineId: machine\.id/)
  assert.match(overview, /summarizeMachineHealth\(/)
})

test('active-downtime loading goes through normalizeActiveDowntime, not the generic unwrapData fallback', () => {
  assert.match(service, /loadActiveDowntime = async \(machineId\) => normalizeActiveDowntime\(/)
})

test('Machine Detail derives status from describeMachineStatus and stays read-only (mutations live in Maintenance)', () => {
  assert.match(section, /describeMachineStatus\(active, timezone\)/)
  assert.doesNotMatch(section, /endDowntime|startDowntime|addDowntimeNote|StartDowntimeDialog|EndDowntimeDialog/)
})

test('a closed event can never stay shown as active: Kondisi Mesin re-reads the server list after every mutation', () => {
  for (const call of ['startDowntime', 'addDowntimeNote', 'endDowntime']) assert.match(condition, new RegExp(`await ${call}\\([^\\n]*\\)\\s*\\n[^\\n]*\\n?\\s*await refresh\\(\\)`))
})

test('navigation: no standalone Downtime item; Kondisi Mesin reuses the 4-column KPI grid with the four required metrics', () => {
  assert.doesNotMatch(sidebar, /path: '\/downtime'/)
  assert.match(condition, /report-kpi-grid compact/)
  for (const label of ['Ketersediaan Mesin', 'Downtime Produksi', 'Jumlah Gangguan', 'Mesin Berhenti', 'Downtime Aktif', 'Riwayat Downtime']) assert.match(condition, new RegExp(label))
})
