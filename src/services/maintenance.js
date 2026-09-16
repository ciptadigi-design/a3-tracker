import { laravelMaintenance } from '../lib/api/maintenance.js'
import { unwrapCollection, unwrapData } from '../lib/api/apiClient.js'

function toQueryString(params = {}) {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') search.set(key, value)
  }
  return search.toString()
}

export const loadMachineErrorCodes = async ({ machineModelId } = {}) => unwrapCollection(await laravelMaintenance.errorCodes(toQueryString({ machine_model_id: machineModelId })))
export const createMachineErrorCode = async (payload) => unwrapData(await laravelMaintenance.createErrorCode(payload))
export const updateMachineErrorCode = async (id, payload) => unwrapData(await laravelMaintenance.updateErrorCode(id, payload))
export const setMachineErrorCodeStatus = async (id, isActive) => unwrapData(await laravelMaintenance.setErrorCodeStatus(id, isActive))

export const loadMaintenanceDocuments = async ({ machineModelId } = {}) => unwrapCollection(await laravelMaintenance.documents(toQueryString({ machine_model_id: machineModelId })))
export const createMaintenanceDocument = async (payload) => unwrapData(await laravelMaintenance.createDocument(payload))

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
