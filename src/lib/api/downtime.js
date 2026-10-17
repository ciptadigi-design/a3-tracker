import { apiClient } from './apiClient.js'

// M2 Downtime & Maintenance V1 - a net-new domain with no Supabase history,
// so it talks to Laravel directly (see lib/api/maintenance.js for the same
// pattern).
export const laravelDowntime = {
  overview: (params = '') => apiClient.get(`/downtime/overview${params ? `?${params}` : ''}`),
  events: (params = '') => apiClient.get(`/downtime/events${params ? `?${params}` : ''}`),
  active: (machineId) => apiClient.get(`/downtime/machines/${machineId}/active`),
  start: (machineId, payload) => apiClient.post(`/downtime/machines/${machineId}/start`, payload),
  addNote: (eventId, payload) => apiClient.post(`/downtime/events/${eventId}/notes`, payload),
  end: (eventId, payload) => apiClient.post(`/downtime/events/${eventId}/end`, payload),

  branchSchedule: (branchId) => apiClient.get(`/operations/branches/${branchId}/production-schedule`),
  putBranchSchedule: (branchId, days) => apiClient.put(`/operations/branches/${branchId}/production-schedule`, { days }),
  machineOverride: (machineId) => apiClient.get(`/operations/machines/${machineId}/production-schedule-override`),
  putMachineOverride: (machineId, days) => apiClient.put(`/operations/machines/${machineId}/production-schedule-override`, { days }),
}
