import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const history = read('./MachineMaintenanceHistory.jsx')
const machineDetail = read('../machines/MachineDetailPage.jsx')
const service = read('../../services/maintenance.js')
const api = read('../../lib/api/maintenance.js')
const styles = read('../../App.css')

test('Machine Detail keeps existing features and exposes Maintenance History naturally', () => {
  assert.match(machineDetail, /MachineMaintenanceHistory/)
  assert.match(machineDetail, /getElementById\('maintenance-history'\)/)
  assert.match(history, /id="maintenance-history"/)
  assert.match(machineDetail, /Troubleshooting/)
  assert.match(machineDetail, /MachineFormDialog/)
  assert.match(machineDetail, /RetireMachineDialog/)
  assert.match(machineDetail, /className="detail-grid glass-surface"/)
})

test('history requests one authorized machine with server-side status pagination', () => {
  assert.match(api, /maintenance\/machines\/\$\{machineId\}\/history/)
  assert.match(service, /loadMachineMaintenanceHistory/)
  assert.match(service, /status, page, per_page: perPage/)
  assert.match(history, /loadMachineMaintenanceHistory\(\{ machineId: machine\.id, status, page, perPage: pageSize \}\)/)
  assert.match(history, /<Pagination/)
  assert.doesNotMatch(history, /\.slice\(/)
})

test('official and manual tickets render without making knowledge mandatory', () => {
  assert.match(history, /official\?\.code \|\| knownError\?\.code/)
  assert.match(history, /classification \|\| ticket\.title \|\| 'Maintenance Ticket'/)
  assert.match(history, /\{official && <button/)
  assert.match(history, /Belum ada riwayat maintenance untuk mesin ini/)
  assert.match(history, /Ticket manual dan ticket dari Troubleshooting/)
})

test('timeline links keep ticket ownership and machine-aware troubleshooting context', () => {
  assert.match(history, /`\/maintenance\/tickets\/\$\{ticket\.id\}`/)
  assert.match(history, /troubleshootingDetailUrl\(official\.id, machineId\)/)
  assert.match(history, /Lihat Ticket/)
  assert.match(history, /Lihat Penanganan/)
})

test('actual lifecycle status, concise descriptions, and operational summary are visible', () => {
  for (const status of ['OPEN', 'IN_PROGRESS', 'DONE', 'CANCELLED']) assert.match(history, new RegExp(status))
  assert.match(history, /summary\.total/)
  assert.match(history, /summary\.active/)
  assert.match(history, /summary\.completed/)
  assert.match(styles, /-webkit-line-clamp: 2/)
})

test('history uses a desktop timeline and a stacked mobile layout with large actions', () => {
  assert.match(styles, /\.maintenance-history-item \{[^}]*grid-template-columns: 165px minmax\(0, 1fr\) auto/s)
  assert.match(styles, /@media \(max-width: 680px\)[\s\S]*\.maintenance-history-item \{ grid-template-columns: minmax\(0, 1fr\)/)
  assert.match(styles, /\.maintenance-history-actions button \{ min-height: 38px/)
  assert.doesNotMatch(styles, /\.maintenance-history-(?:list|item)[^{]*\{[^}]*overflow-x:\s*(?:auto|scroll)/s)
})
