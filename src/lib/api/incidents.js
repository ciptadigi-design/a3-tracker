import { apiClient } from './apiClient.js'

export const laravelIncidents = {
  list: (accountId, branchId) => apiClient.get(`/accounts/${accountId}/branches/${branchId}/incidents`),
  create: (accountId, branchId, payload) => apiClient.post(`/accounts/${accountId}/branches/${branchId}/incidents`, payload),
  detail: (accountId, branchId, incidentId) => apiClient.get(`/accounts/${accountId}/branches/${branchId}/incidents/${incidentId}`),
}
