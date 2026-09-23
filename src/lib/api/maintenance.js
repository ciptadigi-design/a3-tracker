import { apiClient } from './apiClient.js'

// Maintenance is a net-new domain with no Supabase history, so it talks to
// Laravel directly rather than going through the dual-backend dispatcher
// (services/dataBackend.js) that older domains still use for Supabase parity.
export const laravelMaintenance = {
  documents: (params = '') => apiClient.get(`/maintenance/documents${params ? `?${params}` : ''}`),
  document: (id) => apiClient.get(`/maintenance/documents/${id}`),
  createDocument: (payload) => apiClient.post('/maintenance/documents', payload),
  updateDocument: (id, payload) => apiClient.patch(`/maintenance/documents/${id}`, payload),
  deleteDocument: (id) => apiClient.delete(`/maintenance/documents/${id}`),
  addDocumentReference: (documentId, payload) => apiClient.post(`/maintenance/documents/${documentId}/references`, payload),
  deleteDocumentReference: (documentId, referenceId) => apiClient.delete(`/maintenance/documents/${documentId}/references/${referenceId}`),
  uploadDocumentFile: (documentId, formData) => apiClient.upload(`/maintenance/documents/${documentId}/upload`, formData),
  deleteDocumentFile: (documentId) => apiClient.delete(`/maintenance/documents/${documentId}/file`),

  errorCodes: (params = '') => apiClient.get(`/maintenance/error-codes${params ? `?${params}` : ''}`),
  createErrorCode: (payload) => apiClient.post('/maintenance/error-codes', payload),
  updateErrorCode: (id, payload) => apiClient.put(`/maintenance/error-codes/${id}`, payload),
  setErrorCodeStatus: (id, isActive) => apiClient.patch(`/maintenance/error-codes/${id}/status`, { is_active: isActive }),
  addErrorCodeSolution: (errorCodeId, payload) => apiClient.post(`/maintenance/error-codes/${errorCodeId}/solutions`, payload),
  updateErrorCodeSolution: (errorCodeId, solutionId, payload) => apiClient.put(`/maintenance/error-codes/${errorCodeId}/solutions/${solutionId}`, payload),
  deleteErrorCodeSolution: (errorCodeId, solutionId) => apiClient.delete(`/maintenance/error-codes/${errorCodeId}/solutions/${solutionId}`),

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
