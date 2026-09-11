import { apiClient, unwrapData, unwrapCollection } from '../../lib/api/apiClient.js'
import { unsupportedBackendOperation } from '../dataBackend.js'
export const catalogs = async () => unwrapCollection(await apiClient.get('/components'))
export const createCatalog = async (payload) => unwrapData(await apiClient.post('/components', payload))
export const profiles = async (modelId) => unwrapCollection(await apiClient.get(`/machine-models/${modelId}/profiles`))
export const createProfile = async (modelId, payload) => unwrapData(await apiClient.post(`/machine-models/${modelId}/profiles`, payload))
export const addSlot = async (profileId, payload) => unwrapData(await apiClient.post(`/model-profiles/${profileId}/slots`, payload))
export const updateSlot = async (slotId, payload) => unwrapData(await apiClient.put(`/model-profile-slots/${slotId}`, payload))
export const setSlotStatus = async (slotId, isActive) => unwrapData(await apiClient.patch(`/model-profile-slots/${slotId}/status`, { is_active: isActive }))
export const machineComponents = async (machineId) => unwrapCollection(await apiClient.get(`/machines/${machineId}/components`))
export const sync = async (machineId) => unwrapData(await apiClient.post(`/machines/${machineId}/components/sync`))
export const addManual = async (machineId, payload) => unwrapData(await apiClient.post(`/machines/${machineId}/components/manual`, payload))
export const exclude = async (componentId, payload) => unwrapData(await apiClient.post(`/machine-components/${componentId}/exclude`, payload))
export const clearExclusion = async (exclusionId) => unwrapData(await apiClient.post(`/component-exclusions/${exclusionId}/clear`))
export const initializeLifecycle = async (componentId, payload) => unwrapData(await apiClient.post(`/machine-components/${componentId}/lifecycles`, payload))
export const unsupported = (operation) => { throw unsupportedBackendOperation('components', operation) }
export async function loadComponentFoundation({ accountId }) {
  // M2.17.5.2: this hardcoded `manufacturers: []` (never actually fetched) left the Add
  // Component dialog's Manufacturer dropdown permanently empty even though
  // GET /manufacturers already correctly returns global + account-scoped active
  // manufacturers (proven by Settings' loadOperationalMasters, which already used it).
  const [catalogRows, modelRows, manufacturerRows] = await Promise.all([catalogs(accountId), unwrapCollection(await apiClient.get(`/machine-models?account_id=${accountId}`)), unwrapCollection(await apiClient.get(`/manufacturers?account_id=${accountId}`))])
  const profileRows = (await Promise.all(modelRows.map(async (model) => unwrapCollection(await apiClient.get(`/machine-models/${model.id}/profiles`))))).flatMap((profiles) => profiles.flatMap((profile) => (profile.slots || []).map((slot) => ({ ...slot, id: slot.id, account_id: profile.account_id, machine_model_id: profile.machine_model_id, components: slot.component, is_active: slot.is_active !== false, tracking_method: slot.tracking_method || 'counter_based' }))))
  return { manufacturers: manufacturerRows, models: modelRows, components: catalogRows, profiles: profileRows, intelligence: [], intelligenceSamples: [] }
}
export const adoptIntelligenceRecommendation = () => unsupported('adoptIntelligenceRecommendation')
// M2.17.5.2: this used to forward the Component form's raw camelCase draft state
// (manufacturerId, trackingMethod, ...) directly as the request body - Laravel's
// validate() only ever sees whitelisted snake_case keys, so manufacturer_id (and
// every other field not already coincidentally snake_case) was silently dropped on
// every save regardless of what the backend was made to accept.
function catalogPayload(values) {
  return {
    code: values.code?.trim(), name: values.name?.trim(), category: values.category || null,
    description: values.description || null, manufacturer_id: values.manufacturerId || null,
  }
}
export const saveComponent = ({ accountId, component, values }) => component?.id ? unwrapData(apiClient.put(`/components/${component.id}`, catalogPayload(values))) : createCatalog({ ...catalogPayload(values), account_id: accountId })
export const setComponentStatus = ({ componentId, action }) => apiClient.patch(`/components/${componentId}/status`, { is_active: action !== 'archive' }).then(unwrapData)
// M2.17.5.2 Part B: this previously stopped at baseline_expected_clicks - the four
// threshold fields and adaptiveEnabled were collected by ProfileDialog but never
// reached the request body at all, so the backend had nothing to persist even
// after model_profile_slots gained columns for them.
function slotPayload(values) {
  return {
    component_id: values.componentId,
    slot_code: values.slotCode?.trim().toUpperCase(),
    slot_name: values.slotCode?.trim() || null,
    display_order: values.displayOrder === '' || values.displayOrder == null ? 0 : Number(values.displayOrder),
    tracking_method: values.trackingMethod || 'counter_based',
    baseline_expected_clicks: values.baselineExpectedClicks === '' || values.baselineExpectedClicks == null ? null : Number(values.baselineExpectedClicks),
    healthy_threshold_percent: values.healthyThreshold === '' || values.healthyThreshold == null ? null : Number(values.healthyThreshold),
    watch_threshold_percent: values.watchThreshold === '' || values.watchThreshold == null ? null : Number(values.watchThreshold),
    warning_threshold_percent: values.warningThreshold === '' || values.warningThreshold == null ? null : Number(values.warningThreshold),
    critical_threshold_percent: values.criticalThreshold === '' || values.criticalThreshold == null ? null : Number(values.criticalThreshold),
    adaptive_enabled: Boolean(values.adaptiveEnabled),
    notes: values.notes || null,
  }
}
// `profile` here is really a flattened Model Profile Slot (see loadComponentFoundation above):
// the Laravel backend groups slots under a Model Profile container, but the frontend's data
// contract predates that split and treats each slot as one editable/archivable "profile" row.
export const saveProfile = ({ modelId, profile, values }) => profile ? updateSlot(profile.id, slotPayload(values)) : createProfile(modelId, slotPayload(values))
export const setProfileStatus = ({ profileId, action }) => setSlotStatus(profileId, action !== 'archive')
export const addMachineComponent = ({ machineId, values }) => addManual(machineId, values)
export const removeMachineComponent = ({ assignmentId, reason }) => unwrapData(apiClient.patch(`/machine-components/${assignmentId}`, { reason }))
export const clearMachineComponentExclusion = ({ profileId }) => clearExclusion(profileId)
export const syncMachineComponents = (args) => sync(args.machineId)
export const reconcileManualComponent = ({ assignmentId, profileId }) => apiClient.post(`/machine-components/${assignmentId}/reconcile`, { profile_slot_id: profileId }).then(unwrapData)
export const getReconciliationCandidate = ({ assignmentId }) => apiClient.get(`/machine-components/${assignmentId}/reconciliation-candidate`).then(unwrapData)
