import assert from 'node:assert/strict'
import test from 'node:test'
import { errorCodeSeverityLabels, knowledgeStatusLabels, mapMaintenanceError, nextTicketStatuses, ticketPriorityLabels, ticketStatusLabels, ticketTypeLabels } from './maintenanceUtils.js'

test('ticket status workflow only allows OPEN -> IN_PROGRESS/CANCELLED and IN_PROGRESS -> DONE/CANCELLED', () => {
  assert.deepEqual(nextTicketStatuses.OPEN, ['IN_PROGRESS', 'CANCELLED'])
  assert.deepEqual(nextTicketStatuses.IN_PROGRESS, ['DONE', 'CANCELLED'])
  assert.deepEqual(nextTicketStatuses.DONE, [])
  assert.deepEqual(nextTicketStatuses.CANCELLED, [])
})

test('every ticket status/type/priority has a display label, matching the backend contract', () => {
  for (const status of ['OPEN', 'IN_PROGRESS', 'DONE', 'CANCELLED']) assert.ok(ticketStatusLabels[status], `missing label for ${status}`)
  for (const type of ['breakdown', 'preventive', 'inspection']) assert.ok(ticketTypeLabels[type], `missing label for ${type}`)
  for (const priority of ['low', 'normal', 'high', 'urgent']) assert.ok(ticketPriorityLabels[priority], `missing label for ${priority}`)
  for (const severity of ['info', 'warning', 'critical']) assert.ok(errorCodeSeverityLabels[severity], `missing label for ${severity}`)
})

test('knowledge review workflow labels cover DRAFT, REVIEW, PUBLISHED', () => {
  assert.deepEqual(Object.keys(knowledgeStatusLabels), ['DRAFT', 'REVIEW', 'PUBLISHED'])
})

test('mapMaintenanceError translates known HTTP statuses into actionable copy', () => {
  assert.match(mapMaintenanceError({ status: 409 }), /different state/)
  assert.match(mapMaintenanceError({ status: 403 }), /not authorized/)
  assert.match(mapMaintenanceError({ status: 422 }), /highlighted fields/)
  assert.match(mapMaintenanceError({ status: 500 }), /could not be completed/)
})
