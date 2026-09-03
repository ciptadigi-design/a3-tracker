import { apiClient, unwrapCollection, unwrapData } from '../../lib/api/apiClient.js'
import { projectLaravelMachineComponents, projectLaravelReplacementHistory } from '../../features/components/laravelComponentProjection.js'

export async function loadMachineComponentLifecycles({ branchId } = {}) {
  const machines = branchId ? unwrapCollection(await apiClient.get(`/branches/${branchId}/machines?per_page=50`)) : []
  const rows = await Promise.all(machines.map((machine) => apiClient.get(`/machines/${machine.id}/components`)))
  const components = rows.flatMap((payload) => unwrapCollection(payload))
  return { branchId, machines, lifecycles: projectLaravelMachineComponents(components), replacementHistory: projectLaravelReplacementHistory(components), operationalPeople: [], inventoryItems: [], inventoryLocations: [], inventoryBalances: [], exclusions: [], components }
}
export async function initializeComponentLifecycle({ assignmentId, installedAt, notes, clientRequestId }) { return unwrapData(await apiClient.post(`/machine-components/${assignmentId}/lifecycles`, { started_at: installedAt || null, notes: notes || null, client_request_id: clientRequestId })) }
export async function replaceComponentLifecycle({ assignmentId, replacedAt, reason, notes, inventorySource, inventoryItemId, inventoryLocationId, inventoryQuantity, externalInventoryReason, clientRequestId }) { return unwrapData(await apiClient.post(`/machine-components/${assignmentId}/replacements`, { inventory_source: inventorySource, inventory_item_id: inventoryItemId || null, inventory_location_id: inventoryLocationId || null, quantity: inventoryQuantity || null, replaced_at: replacedAt || null, external_reason: externalInventoryReason || null, notes: notes || reason || null, client_request_id: clientRequestId })) }
