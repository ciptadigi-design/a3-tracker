import { apiClient } from './apiClient.js'

export const laravelReports = {
  load: ({ accountId, branchId, machineId, periodStart, periodEnd, errorCategory, errorStatus }) => apiClient.get(`/reports?account_id=${encodeURIComponent(accountId)}&branch_id=${encodeURIComponent(branchId || '')}&machine_id=${encodeURIComponent(machineId || '')}&period_start=${encodeURIComponent(periodStart)}&period_end=${encodeURIComponent(periodEnd)}${errorCategory ? `&category=${encodeURIComponent(errorCategory)}` : ''}${errorStatus ? `&status=${encodeURIComponent(errorStatus)}` : ''}`),
}
