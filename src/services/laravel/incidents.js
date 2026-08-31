import { apiClient, unwrapData, unwrapCollection } from '../../lib/api/apiClient.js'

export async function loadOperationalIncidents({ accountId, branchId }) { const payload = await apiClient.get(`/accounts/${accountId}/branches/${branchId}/incidents`); return payload.incidents ?? unwrapCollection(payload) }
export async function loadOperationalIncident({ accountId, branchId, incidentId }) { const payload = await apiClient.get(`/accounts/${accountId}/branches/${branchId}/incidents/${incidentId}`); return payload.incident ?? unwrapData(payload) }
export async function createOperationalIncident({ accountId, branchId, values }) { const payload = await apiClient.post(`/accounts/${accountId}/branches/${branchId}/incidents`, values); return payload.incident ?? unwrapData(payload) }
const unsupported = (operation) => { throw new Error(`Incident ${operation} is not yet available through the Laravel adapter; no Supabase fallback was attempted.`) }
export const updateOperationalIncident = () => unsupported('updates')
export const solveOperationalIncident = () => unsupported('resolution')
export const resolveOperationalIncident = () => unsupported('resolution')
export const voidOperationalIncident = () => unsupported('voiding')
