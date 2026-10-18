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

test('Machine Detail derives status from describeMachineStatus and never keeps a closed event as active', () => {
  assert.match(section, /describeMachineStatus\(active, timezone\)/)
  assert.match(section, /await endDowntime\(active\.id, payload\)\s*\n\s*setActive\(null\)/)
  assert.match(section, /disabled=\{!status\.canComplete\}/)
})

test('navigation: no standalone Downtime item; Kondisi Mesin reuses the 4-column KPI grid with the four required metrics', () => {
  assert.doesNotMatch(sidebar, /path: '\/downtime'/)
  assert.match(condition, /report-kpi-grid compact/)
  for (const label of ['Ketersediaan Mesin', 'Downtime Produksi', 'Jumlah Gangguan', 'Mesin Berhenti', 'Mesin Perlu Perhatian']) assert.match(condition, new RegExp(label))
})
