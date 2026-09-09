import { apiClient, unwrapData } from '../../lib/api/apiClient.js'

export async function loadClickTargetProjection({ machineId, year, month }) {
  return unwrapData(await apiClient.get(`/machines/${machineId}/click-target?year=${year}&month=${month}`))
}

export async function saveMachineClickTarget({ machineId, targetYear, targetMonth, monthlyClickTarget, reason, clientRequestId }) {
  return unwrapData(await apiClient.put(`/machines/${machineId}/click-target`, { target_year: targetYear, target_month: targetMonth, monthly_click_target: monthlyClickTarget, reason: reason || null, client_request_id: clientRequestId }))
}

export async function loadClickTargetHistory({ machineId, year, month }) {
  const query = new URLSearchParams()
  if (year) query.set('year', year)
  if (month) query.set('month', month)
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return unwrapData(await apiClient.get(`/machines/${machineId}/click-target/history${suffix}`))
}

export async function loadCalendarExceptions({ machineId, year, month }) {
  return unwrapData(await apiClient.get(`/machines/${machineId}/click-target/calendar-exceptions?year=${year}&month=${month}`))
}

export async function createCalendarException({ machineId, calendarDate, exceptionType, notes, clientRequestId }) {
  return unwrapData(await apiClient.post(`/machines/${machineId}/click-target/calendar-exceptions`, { calendar_date: calendarDate, exception_type: exceptionType, notes: notes || null, client_request_id: clientRequestId }))
}

export async function removeCalendarException({ exceptionId }) {
  return unwrapData(await apiClient.delete(`/calendar-exceptions/${exceptionId}`))
}
