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

export const startDocumentExtraction = async (documentId) => unwrapData(await laravelMaintenance.startDocumentExtraction(documentId))
export const loadDocumentExtraction = async (documentId) => unwrapData(await laravelMaintenance.documentExtraction(documentId))
// unwrapData (not unwrapCollection) - the pagination metadata (current_page,
// last_page, total) matters here, same reason loadMaintenanceTickets keeps it.
export const loadDocumentPages = async (documentId, { page, perPage } = {}) => unwrapData(await laravelMaintenance.documentPages(documentId, toQueryString({ page, per_page: perPage })))

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

export const processDocumentKnowledge = async (documentId, payload = {}) => unwrapData(await laravelMaintenance.processDocumentKnowledge(documentId, payload))
export const loadDocumentImports = async ({ documentId, status } = {}) => unwrapCollection(await laravelMaintenance.documentImports(toQueryString({ document_id: documentId, status })))
export const loadDocumentImport = async (id) => unwrapData(await laravelMaintenance.documentImport(id))
export const loadDocumentImportEntries = async (importId, { status, collisionStatus, evidence, code, page, perPage } = {}) => unwrapData(await laravelMaintenance.documentImportEntries(importId, toQueryString({ status, collision_status: collisionStatus, evidence, code, page, per_page: perPage })))
export const bulkReviewEntries = async (importId, { entryIds, action }) => unwrapData(await laravelMaintenance.bulkReviewEntries(importId, { entry_ids: entryIds, action }))

// V1.7.2 - code-group read model + filter-based bulk triage. The query string is built by
// codeGroupUtils.buildCodeGroupQuery so the exact same criteria vocabulary is used everywhere.
export const loadCodeGroups = async (importId, queryString = '') => unwrapData(await laravelMaintenance.codeGroups(importId, queryString))
export const loadCodeGroup = async (importId, code) => unwrapData(await laravelMaintenance.codeGroup(importId, code))
// V1.9 - one adjacent context page (not one of the group's own direct source pages).
export const loadCodeGroupPage = async (importId, code, pageNumber) => unwrapData(await laravelMaintenance.codeGroupPage(importId, code, pageNumber))
export const previewFilterBulkReview = async (importId, { action, filters }) => unwrapData(await laravelMaintenance.previewFilterBulkReview(importId, { action, filters }))
export const applyFilterBulkReview = async (importId, { action, filters, confirmationToken }) => unwrapData(await laravelMaintenance.applyFilterBulkReview(importId, { action, filters, confirmation_token: confirmationToken }))

// V1.8 - group-aware canonical publish. The payload is built by groupPublishUtils.buildPublishPayload.
export const previewGroupPublish = async (importId, code, payload) => unwrapData(await laravelMaintenance.previewGroupPublish(importId, code, payload))
export const publishCodeGroup = async (importId, code, payload, { confirmationToken, confirmUpdate }) => unwrapData(await laravelMaintenance.publishGroup(importId, code, { ...payload, confirmation_token: confirmationToken, confirm_update: Boolean(confirmUpdate) }))
export const createDocumentImport = async (payload) => unwrapData(await laravelMaintenance.createDocumentImport(payload))
export const addKnowledgeEntry = async (importId, payload) => unwrapData(await laravelMaintenance.addKnowledgeEntry(importId, payload))
export const updateKnowledgeEntry = async (id, payload) => unwrapData(await laravelMaintenance.updateKnowledgeEntry(id, payload))
export const publishKnowledgeEntry = async (id) => unwrapData(await laravelMaintenance.publishKnowledgeEntry(id))

// V1.11 - review-session telemetry. Deliberately thin: reviewSessionTracker.js owns the local
// aggregation/flush policy, these just wrap the three endpoints it calls.
export const startReviewSession = async (importId, code) => unwrapData(await laravelMaintenance.startReviewSession(importId, code))
export const heartbeatReviewSession = async (sessionId, payload) => unwrapData(await laravelMaintenance.heartbeatReviewSession(sessionId, payload))
export const abandonReviewSession = async (sessionId) => unwrapData(await laravelMaintenance.abandonReviewSession(sessionId))
export const loadReviewBenchmark = async (importId, { cohort } = {}) => unwrapData(await laravelMaintenance.reviewBenchmark(importId, toQueryString({ cohort })))
