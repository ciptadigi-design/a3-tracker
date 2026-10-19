import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8')
const condition = read('./MachineConditionSection.jsx')
const ticketDetail = read('../maintenance/MaintenanceTicketDetailPage.jsx')
const maintenancePage = read('../../pages/MaintenancePage.jsx')
const api = read('../../lib/api/downtime.js')

test('Kondisi Mesin renders linked ticket reference, status and navigation for active downtime', () => {
  assert.match(condition, /item\.maintenance_ticket\.id\.slice/)
  assert.match(condition, /ticketStatusLabels\[item\.maintenance_ticket\.status\]/)
  assert.match(condition, />Lihat Ticket</)
  assert.match(condition, /\/maintenance\/tickets\/\$\{item\.maintenance_ticket\.id\}/)
})

test('downtime history explicitly preserves rows without a linked ticket', () => {
  assert.match(condition, /Belum ada ticket terkait/)
  assert.match(condition, /event\.maintenance_ticket/)
  assert.match(condition, /ticketStatusLabels\[event\.maintenance_ticket\.status\]/)
})

test('ticket list and detail identify downtime source and expose the independent downtime lifecycle', () => {
  assert.match(maintenancePage, /ticket\.downtime_event \? ' · Sumber: Downtime'/)
  assert.match(ticketDetail, /ticket\.downtime_event \? ' · Sumber: Downtime'/)
  for (const field of ['cause', 'actual_start_at', 'actual_end_at', 'elapsed_minutes', 'error_code_text']) {
    assert.match(ticketDetail, new RegExp(`downtime_event\\.${field}`))
  }
  assert.match(ticketDetail, /\/maintenance\/machine-condition\?machine=/)
})

test('reporting downtime remains one frontend request, never a second ticket request', () => {
  assert.match(api, /start: \(machineId, payload\) => apiClient\.post\(`\/downtime\/machines\/\$\{machineId\}\/start`, payload\)/)
  assert.doesNotMatch(condition, /createMaintenanceTicket|laravelMaintenance/)
})
