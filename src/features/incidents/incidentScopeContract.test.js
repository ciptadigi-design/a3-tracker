import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const errorsPage = fs.readFileSync(new URL('../../pages/ErrorsPage.jsx', import.meta.url), 'utf8')
const incidentForm = fs.readFileSync(new URL('./IncidentFormDialog.jsx', import.meta.url), 'utf8')
const incidentHistory = fs.readFileSync(new URL('./IncidentHistory.jsx', import.meta.url), 'utf8')
const incidentDetail = fs.readFileSync(new URL('./IncidentDetailPage.jsx', import.meta.url), 'utf8')

test('Error totals state their all-history branch scope and support explicit machine filtering', () => {
  assert.match(errorsPage, /Machine Cost/)
  assert.match(errorsPage, /all recorded history/)
  assert.match(errorsPage, /All Machines \/ Branch/)
  assert.match(errorsPage, /effectiveMachineFilter === 'branch' \? incident\.machine_id == null/)
  assert.match(errorsPage, /All assessed operational loss in this branch/)
})

test('Error entry never defaults to a machine and offers branch-only attribution', () => {
  assert.match(incidentForm, /machineId: incident\.machine_id \?\? ''/)
  assert.match(incidentForm, /Branch \/ No specific machine/)
  assert.match(incidentForm, /Select the production machine explicitly, or keep branch scope when no machine is attributable\./)
})

test('Error history and detail expose machine attribution directly', () => {
  assert.match(incidentHistory, /<HistoryValue label="Machine">/)
  assert.match(incidentHistory, /Branch \/ No specific machine/)
  assert.match(incidentDetail, /label="Machine"/)
  assert.match(incidentDetail, /Branch \/ No specific machine/)
})
