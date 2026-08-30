import { apiClient } from './apiClient.js'

// Explicit Laravel adapter for the operational slice. Feature code must opt in
// via VITE_DATA_BACKEND=laravel; the Supabase runtime remains unchanged.
export const laravelOperations = {
  machines: (branchId, params = '') => apiClient.get(`/branches/${branchId}/machines${params ? `?${params}` : ''}`),
  machine: (machineId) => apiClient.get(`/machines/${machineId}`),
  people: (branchId) => apiClient.get(`/branches/${branchId}/operational-people`),
  counters: (machineId, params = '') => apiClient.get(`/machines/${machineId}/counters${params ? `?${params}` : ''}`),
  recordCounter: (machineId, payload) => apiClient.post(`/machines/${machineId}/counters`, payload),
  periodUsage: (machineId, from, to) => apiClient.get(`/machines/${machineId}/counters/period?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`),
}
