import { apiClient, unwrapCollection, unwrapData } from '../../lib/api/apiClient.js'

export async function loadMachineComponentLifecycles({ machineIds = [] } = {}) {
  const rows = await Promise.all(machineIds.map((id) => apiClient.get(`/machines/${id}/components`)))
  return { lifecycles: rows.flatMap((payload) => unwrapCollection(payload).flatMap((row) => row.lifecycles || [])), components: rows.flatMap((payload) => unwrapCollection(payload)) }
}
export async function initializeComponentLifecycle({ componentId, values }) { return unwrapData(await apiClient.post(`/machine-components/${componentId}/lifecycles`, values)) }
export async function replaceComponentLifecycle({ componentId, values }) { return unwrapData(await apiClient.post(`/machine-components/${componentId}/replacements`, values)) }
