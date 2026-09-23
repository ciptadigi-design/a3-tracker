import { laravelMaintenance } from '../lib/api/maintenance.js'
import { apiBaseUrl, unwrapCollection, unwrapData } from '../lib/api/apiClient.js'

function toQueryString(params = {}) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, value)
  }
  return search.toString()
}

export const loadMachineErrorCodes = async ({ machineModelId, search } = {}) => unwrapCollection(await laravelMaintenance.errorCodes(toQueryString({ machine_model_id: machineModelId, search })))
export const createMachineErrorCode = async (payload) => unwrapData(await laravelMaintenance.createErrorCode(payload))
export const updateMachineErrorCode = async (id, payload) => unwrapData(await laravelMaintenance.updateErrorCode(id, payload))
export const setMachineErrorCodeStatus = async (id, isActive) => unwrapData(await laravelMaintenance.setErrorCodeStatus(id, isActive))
export const addErrorCodeSolution = async (errorCodeId, payload) => unwrapData(await laravelMaintenance.addErrorCodeSolution(errorCodeId, payload))
export const updateErrorCodeSolution = async (errorCodeId, solutionId, payload) => unwrapData(await laravelMaintenance.updateErrorCodeSolution(errorCodeId, solutionId, payload))
export const deleteErrorCodeSolution = async (errorCodeId, solutionId) => { await laravelMaintenance.deleteErrorCodeSolution(errorCodeId, solutionId) }

export const loadMaintenanceDocuments = async ({ machineModelId, manufacturerId, documentType, status, search } = {}) => unwrapCollection(await laravelMaintenance.documents(toQueryString({ machine_model_id: machineModelId, manufacturer_id: manufacturerId, document_type: documentType, status, search })))
export const loadMaintenanceDocument = async (id) => unwrapData(await laravelMaintenance.document(id))
export const createMaintenanceDocument = async (payload) => unwrapData(await laravelMaintenance.createDocument(payload))
export const updateMaintenanceDocument = async (id, payload) => unwrapData(await laravelMaintenance.updateDocument(id, payload))
export const deleteMaintenanceDocument = async (id) => { await laravelMaintenance.deleteDocument(id) }
export const addDocumentReference = async (documentId, payload) => unwrapData(await laravelMaintenance.addDocumentReference(documentId, payload))
export const deleteDocumentReference = async (documentId, referenceId) => { await laravelMaintenance.deleteDocumentReference(documentId, referenceId) }

export const uploadMaintenanceDocumentFile = async (documentId, file) => {
  const formData = new FormData()
  formData.append('file', file)
  return unwrapData(await laravelMaintenance.uploadDocumentFile(documentId, formData))
}
export const deleteMaintenanceDocumentFile = async (documentId) => unwrapData(await laravelMaintenance.deleteDocumentFile(documentId))
// Authenticated, session-cookie-based download - a plain same-origin URL, not a
// fetch+blob dance, so the browser's native download/view handling (and the
// existing session cookies) just work.
export const maintenanceDocumentFileUrl = (documentId, { inline = false } = {}) => `${apiBaseUrl}/maintenance/documents/${documentId}/download${inline ? '?inline=1' : ''}`

export const loadMaintenanceTickets = async ({ machineId, status, perPage } = {}) => unwrapData(await laravelMaintenance.tickets(toQueryString({ machine_id: machineId, status, per_page: perPage })))
export const loadMaintenanceTicket = async (id) => unwrapData(await laravelMaintenance.ticket(id))
export const createMaintenanceTicket = async (payload) => unwrapData(await laravelMaintenance.createTicket(payload))
export const updateMaintenanceTicket = async (id, payload) => unwrapData(await laravelMaintenance.updateTicket(id, payload))
export const assignMaintenanceTicket = async (id, assignedTo) => unwrapData(await laravelMaintenance.assignTicket(id, assignedTo))
export const transitionMaintenanceTicket = async (id, status) => unwrapData(await laravelMaintenance.transitionTicket(id, status))
export const recordMaintenanceAction = async (ticketId, payload) => unwrapData(await laravelMaintenance.createAction(ticketId, payload))

export const loadMaintenanceKnowledge = async ({ approvalStatus, machineModelId, errorCodeId } = {}) => unwrapCollection(await laravelMaintenance.knowledge(toQueryString({ approval_status: approvalStatus, machine_model_id: machineModelId, error_code_id: errorCodeId })))
export const submitMaintenanceKnowledge = async (payload) => unwrapData(await laravelMaintenance.submitKnowledge(payload))
export const reviewMaintenanceKnowledge = async (id, approvalStatus) => unwrapData(await laravelMaintenance.reviewKnowledge(id, approvalStatus))
