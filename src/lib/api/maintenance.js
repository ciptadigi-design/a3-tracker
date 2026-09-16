import { apiClient } from './apiClient.js'

// Maintenance is a net-new domain with no Supabase history, so it talks to
// Laravel directly rather than going through the dual-backend dispatcher
// (services/dataBackend.js) that older domains still use for Supabase parity.
export const laravelMaintenance = {
  documents: (params = '') => apiClient.get(`/maintenance/documents${params ? `?${params}` : ''}`),
  createDocument: (payload) => apiClient.post('/maintenance/documents', payload),
  setDocumentStatus: (id, isActive) => apiClient.patch(`/maintenance/documents/${id}/status`, { is_active: isActive }),

  errorCodes: (params = '') => apiClient.get(`/maintenance/error-codes${params ? `?${params}` : ''}`),
  createErrorCode: (payload) => apiClient.post('/maintenance/error-codes', payload),
  updateErrorCode: (id, payload) => apiClient.put(`/maintenance/error-codes/${id}`, payload),
  setErrorCodeStatus: (id, isActive) => apiClient.patch(`/maintenance/error-codes/${id}/status`, { is_active: isActive }),

  tickets: (params = '') => apiClient.get(`/maintenance/tickets${params ? `?${params}` : ''}`),
  ticket: (id) => apiClient.get(`/maintenance/tickets/${id}`),
  createTicket: (payload) => apiClient.post('/maintenance/tickets', payload),
  updateTicket: (id, payload) => apiClient.patch(`/maintenance/tickets/${id}`, payload),
  assignTicket: (id, assignedTo) => apiClient.patch(`/maintenance/tickets/${id}/assign`, { assigned_to: assignedTo }),
  transitionTicket: (id, status) => apiClient.patch(`/maintenance/tickets/${id}/status`, { status }),
  createAction: (ticketId, payload) => apiClient.post(`/maintenance/tickets/${ticketId}/actions`, payload),

  knowledge: (params = '') => apiClient.get(`/maintenance/knowledge${params ? `?${params}` : ''}`),
  submitKnowledge: (payload) => apiClient.post('/maintenance/knowledge', payload),
  reviewKnowledge: (id, approvalStatus) => apiClient.patch(`/maintenance/knowledge/${id}/review`, { approval_status: approvalStatus }),
}
