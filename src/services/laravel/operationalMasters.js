import { apiClient, unwrapCollection, unwrapData } from '../../lib/api/apiClient.js'
import { unsupportedBackendOperation } from '../dataBackend.js'
export async function loadOperationalPeopleForBranch({ branchId }) { return unwrapCollection(await apiClient.get(`/branches/${branchId}/operational-people`)) }
export async function loadOperationalMasters({ accountId }) { const [people, manufacturers, models] = await Promise.all([apiClient.get(`/accounts/${accountId}/operational-people?per_page=100`), apiClient.get(`/manufacturers?account_id=${accountId}`), apiClient.get(`/machine-models?account_id=${accountId}`)]); return { people: unwrapCollection(people), manufacturers: unwrapCollection(manufacturers), models: unwrapCollection(models) } }
const unsupported = (operation) => { throw unsupportedBackendOperation('operational-masters', operation) }
export const saveOperationalPerson = () => unsupported('saveOperationalPerson')
export const deleteOperationalPerson = () => unsupported('deleteOperationalPerson')
export async function saveManufacturer({ values }) { return unwrapData(await apiClient.post('/manufacturers', values)) }
export async function setManufacturerStatus({ manufacturerId, isActive }) { return unwrapData(await apiClient.patch(`/manufacturers/${manufacturerId}/status`, { is_active: isActive })) }
export const deleteManufacturer = () => unsupported('deleteManufacturer')
export async function saveMachineModel({ values }) { return unwrapData(await apiClient.post('/machine-models', values)) }
export async function setMachineModelStatus({ modelId, isActive }) { return unwrapData(await apiClient.patch(`/machine-models/${modelId}/status`, { is_active: isActive })) }
export const deleteMachineModel = () => unsupported('deleteMachineModel')
