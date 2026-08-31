import { apiClient, unwrapCollection, unwrapData } from '../../lib/api/apiClient.js'

export async function loadMachineComponentLifecycles({ branchId } = {}) {
  const machines = branchId ? unwrapCollection(await apiClient.get(`/branches/${branchId}/machines?per_page=50`)) : []
  const rows = await Promise.all(machines.map((machine) => apiClient.get(`/machines/${machine.id}/components`)))
  const components = rows.flatMap((payload) => unwrapCollection(payload))
  return { branchId, machines, lifecycles: components.flatMap((row) => (row.lifecycles || []).map((lifecycle) => ({ ...lifecycle, machine_id: row.machine_id, assignment_id: row.id, component_name: row.component?.name, component_code: row.component?.code, slot_code: row.slot_code, lifecycle_status: lifecycle.status, lifecycle_id: lifecycle.id, source_type: row.source_type, current_profile_baseline: row.baseline_expected_clicks }))), replacementHistory: [], operationalPeople: [], inventoryItems: [], inventoryLocations: [], inventoryBalances: [], exclusions: [], components }
}
export async function initializeComponentLifecycle({ assignmentId, installedAt, notes, clientRequestId }) { return unwrapData(await apiClient.post(`/machine-components/${assignmentId}/lifecycles`, { started_at: installedAt || null, notes: notes || null, client_request_id: clientRequestId })) }
export async function replaceComponentLifecycle({ assignmentId, replacedAt, reason, notes, inventorySource, inventoryItemId, inventoryLocationId, inventoryQuantity, externalInventoryReason, clientRequestId }) { return unwrapData(await apiClient.post(`/machine-components/${assignmentId}/replacements`, { inventory_source: inventorySource, inventory_item_id: inventoryItemId || null, inventory_location_id: inventoryLocationId || null, quantity: inventoryQuantity || null, replaced_at: replacedAt || null, external_reason: externalInventoryReason || null, notes: notes || reason || null, client_request_id: clientRequestId })) }
