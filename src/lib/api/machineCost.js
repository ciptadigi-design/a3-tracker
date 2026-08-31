import { apiClient } from './apiClient.js'

export const laravelMachineCost = {
  period: (machineId, periodStart, periodEnd) => apiClient.get(`/machines/${machineId}/cost?period_start=${encodeURIComponent(periodStart)}&period_end=${encodeURIComponent(periodEnd)}`),
}
