import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { hasActiveLinkedDowntime, mapMaintenanceError, performerParts, resolutionOptionalTypes, sortActivitiesChronologically, testResultLabels, validateResolution } from './maintenanceUtils.js'
import { branchOperationalPeople } from '../operationalPeople/eligibility.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const detail = read('./MaintenanceTicketDetailPage.jsx')
const activityDialog = read('./RecordActionDialog.jsx')
const resolveDialog = read('./ResolveTicketDialog.jsx')
const responsible = read('./ResponsibleSection.jsx')
const api = read('../../lib/api/maintenance.js')

const full = { resolution_cause: 'Drum kotor', resolution_action: 'Dibersihkan', resolution_test_result: 'normal' }

test('resolution form requires cause, action and a test result', () => {
  assert.deepEqual(Object.keys(validateResolution('breakdown', {})).sort(), ['resolution_action', 'resolution_cause', 'resolution_test_result'])
  assert.deepEqual(validateResolution('breakdown', { ...full, resolution_cause: '   ' }), { resolution_cause: 'Penyebab masalah wajib diisi.' })
  assert.deepEqual(validateResolution('breakdown', full), {})
})

test('Belum Normal blocks breakdown completion; Normal and Perlu Monitoring complete', () => {
  assert.ok(validateResolution('breakdown', { ...full, resolution_test_result: 'not_normal' }).resolution_test_result)
  assert.deepEqual(validateResolution('breakdown', { ...full, resolution_test_result: 'monitoring' }), {})
  assert.deepEqual(validateResolution('preventive', { ...full, resolution_test_result: 'not_normal' }), {})
  assert.deepEqual(Object.values(testResultLabels), ['Normal', 'Perlu Monitoring', 'Belum Normal'])
  assert.deepEqual(resolutionOptionalTypes, ['preventive', 'inspection'])
})

test('activity timeline is chronological and shows internal and external performers', () => {
  const rows = [
    { id: 'b', performed_at: '2026-10-19T15:25:00Z', external_technician_name: 'Budi', vendor_name: 'Konica Minolta' },
    { id: 'a', performed_at: '2026-10-19T14:58:00Z', operator_name: 'Andi' },
    { id: 'c', performed_at: '2026-10-19T15:40:00Z', operator_name: 'Andi', external_technician_name: 'Budi' },
  ]
  assert.deepEqual(sortActivitiesChronologically(rows).map((row) => row.id), ['a', 'b', 'c'])
  assert.deepEqual(performerParts(rows[0]), [{ kind: 'external', label: 'Budi (Konica Minolta)' }])
  assert.deepEqual(performerParts(rows[2]).map((part) => part.kind), ['internal', 'external'])
  assert.deepEqual(performerParts({}), [])
})

test('PIC dropdown reuses the shared operational-people directory and branch eligibility', () => {
  const people = [
    { id: 'p1', name: 'Andi', is_active: true, operational_person_branches: [{ branch_id: 'b1', is_active: true }] },
    { id: 'p2', name: 'Cabang Lain', is_active: true, operational_person_branches: [{ branch_id: 'b2', is_active: true }] },
    { id: 'p3', name: 'Nonaktif', is_active: false, operational_person_branches: [{ branch_id: 'b1', is_active: true }] },
  ]
  assert.deepEqual(branchOperationalPeople(people, 'b1').map((person) => person.id), ['p1'])
  assert.match(detail, /useOperationalPeople\(state\.ticket\?\.account_id, state\.ticket\?\.branch_id\)/)
  assert.match(detail, /branchOperationalPeople\(people\.people, ticket\.branch_id\)/)
  assert.match(responsible, /Belum ada operator yang ditugaskan ke cabang ini/)
  assert.match(responsible, /tidak aktif/)
})

test('external technician fields are optional free text and add-activity works from the dialog', () => {
  assert.match(responsible, /external_technician_name/)
  assert.match(responsible, /external_vendor_name/)
  assert.doesNotMatch(responsible, /required/)
  assert.match(activityDialog, /Uraian aktivitas wajib diisi/)
  assert.match(activityDialog, /operator_person_id/)
  assert.match(activityDialog, /findings/)
  assert.match(detail, /recordMaintenanceAction\(ticket\.id, values\)/)
})

test('Selesaikan Ticket opens the resolution form and sends the resolution with DONE', () => {
  assert.match(detail, /if \(status === 'DONE'\) \{ setShowResolve\(true\); return \}/)
  assert.match(detail, /transitionMaintenanceTicket\(ticket\.id, 'DONE', resolution\)/)
  assert.match(resolveDialog, /Penyebab Masalah/)
  assert.match(resolveDialog, /Tindakan Penyelesaian/)
  assert.match(resolveDialog, /Hasil Pengujian/)
  assert.match(resolveDialog, /Catatan Tambahan/)
  assert.match(api, /transitionTicket: \(id, status, resolution = \{\}\) => apiClient\.patch\(`\/maintenance\/tickets\/\$\{id\}\/status`, \{ status, \.\.\.resolution \}\)/)
})

test('legacy Done tickets render safely and downtime stays independent', () => {
  assert.match(detail, /Ticket ini diselesaikan tanpa ringkasan penyelesaian/)
  assert.match(detail, /ticket\.resolution_cause \|\| ticket\.resolution_action/)
  assert.equal(hasActiveLinkedDowntime({ downtime_event: { actual_end_at: null } }), true)
  assert.equal(hasActiveLinkedDowntime({ downtime_event: { actual_end_at: '2026-10-19T10:00:00Z' } }), false)
  assert.equal(hasActiveLinkedDowntime({ downtime_event: null }), false)
  assert.match(resolveDialog, /tidak menutup downtime/)
  assert.doesNotMatch(detail, /endDowntime|downtime\/events/)
})

test('backend validation messages surface on 422', () => {
  assert.equal(mapMaintenanceError({ status: 422, errors: { resolution_test_result: ['Mesin belum normal.'] } }), 'Mesin belum normal.')
})

test('layout reuses existing responsive classes', () => {
  const css = read('../../App.css')
  assert.match(css, /\.maintenance-timeline \{/)
  assert.match(css, /\.form-warning \{/)
})
