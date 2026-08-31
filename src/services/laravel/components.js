import { apiClient, unwrapData, unwrapCollection } from '../../lib/api/apiClient.js'
import { unsupportedBackendOperation } from '../dataBackend.js'
export const catalogs = async () => unwrapCollection(await apiClient.get('/components'))
export const createCatalog = async (payload) => unwrapData(await apiClient.post('/components', payload))
export const profiles = async (modelId) => unwrapCollection(await apiClient.get(`/machine-models/${modelId}/profiles`))
export const createProfile = async (modelId, payload) => unwrapData(await apiClient.post(`/machine-models/${modelId}/profiles`, payload))
export const addSlot = async (profileId, payload) => unwrapData(await apiClient.post(`/model-profiles/${profileId}/slots`, payload))
export const machineComponents = async (machineId) => unwrapCollection(await apiClient.get(`/machines/${machineId}/components`))
export const sync = async (machineId) => unwrapData(await apiClient.post(`/machines/${machineId}/components/sync`))
export const addManual = async (machineId, payload) => unwrapData(await apiClient.post(`/machines/${machineId}/components/manual`, payload))
export const exclude = async (componentId, payload) => unwrapData(await apiClient.post(`/machine-components/${componentId}/exclude`, payload))
export const clearExclusion = async (exclusionId) => unwrapData(await apiClient.post(`/component-exclusions/${exclusionId}/clear`))
export const initializeLifecycle = async (componentId, payload) => unwrapData(await apiClient.post(`/machine-components/${componentId}/lifecycles`, payload))
export const unsupported = (operation) => { throw unsupportedBackendOperation('components', operation) }
export async function loadComponentFoundation({ accountId }) {
  const [catalogRows, modelRows] = await Promise.all([catalogs(accountId), unwrapCollection(await apiClient.get(`/machine-models?account_id=${accountId}`))])
  const profileRows = (await Promise.all(modelRows.map(async (model) => unwrapCollection(await apiClient.get(`/machine-models/${model.id}/profiles`))))).flatMap((profiles) => profiles.flatMap((profile) => (profile.slots || []).map((slot) => ({ ...slot, id: slot.id, account_id: profile.account_id, machine_model_id: profile.machine_model_id, components: slot.component, is_active: slot.is_active !== false, tracking_method: slot.tracking_method || 'counter_based' }))))
  return { manufacturers: [], models: modelRows, components: catalogRows, profiles: profileRows, intelligence: [], intelligenceSamples: [] }
}
export const adoptIntelligenceRecommendation = () => unsupported('adoptIntelligenceRecommendation')
export const saveComponent = ({ accountId, component, values }) => component?.id ? unwrapData(apiClient.put(`/components/${component.id}`, values)) : createCatalog({ ...values, account_id: accountId })
export const setComponentStatus = ({ componentId, action }) => apiClient.patch(`/components/${componentId}/status`, { is_active: action !== 'archive' }).then(unwrapData)
export const saveProfile = ({ modelId, values }) => createProfile(modelId, { ...values, name: values.name || values.slotCode || 'Default profile' })
export const setProfileStatus = ({ profileId, action }) => apiClient.patch(`/model-profiles/${profileId}/status`, { is_active: action !== 'archive' }).then(unwrapData)
export const addMachineComponent = ({ machineId, values }) => addManual(machineId, values)
export const removeMachineComponent = ({ assignmentId, reason }) => unwrapData(apiClient.patch(`/machine-components/${assignmentId}`, { reason }))
export const clearMachineComponentExclusion = ({ profileId }) => clearExclusion(profileId)
export const syncMachineComponents = (args) => sync(args.machineId)
export const reconcileManualComponent = ({ assignmentId, profileId }) => apiClient.post(`/machine-components/${assignmentId}/reconcile`, { profile_slot_id: profileId }).then(unwrapData)
export const getReconciliationCandidate = ({ assignmentId }) => apiClient.get(`/machine-components/${assignmentId}/reconciliation-candidate`).then(unwrapData)
