import { apiClient, unwrapCollection, unwrapData } from '../../lib/api/apiClient.js'
import { optional, optionalEmail } from './supplierFields.js'

export async function loadInventory({ accountId, branchId }) {
  if (!accountId || !branchId) return { branchId, items: [], locations: [], suppliers: [], purchases: [], movements: [], balances: [], totals: [], components: [], people: [], purchaseLines: [], receipts: [], lastPrices: [], costHistory: [], costPositions: [] }
  return unwrapData(await apiClient.get(`/accounts/${accountId}/branches/${branchId}/inventory`))
}

// Account-wide supplier master list (every branch's suppliers, each with its branch
// assignments) - distinct from loadInventory()'s `suppliers`, which is branch-scoped for
// the purchase picker. Admin management (create/edit/archive/assign) always needs the
// full account list regardless of which branch happens to be selected.
export async function loadInventorySuppliers() { return unwrapCollection(await apiClient.get('/inventory/suppliers')) }
export async function saveInventorySupplier({ accountId, supplierId, values }) {
  const payload = { account_id: accountId, code: values.supplierCode?.trim(), name: values.name?.trim(), contact_name: optional(values.contactPerson), phone: optional(values.phone), email: optionalEmail(values.email), address: optional(values.address), notes: optional(values.notes), is_active: values.isActive }
  return unwrapData(await (supplierId ? apiClient.put(`/inventory/suppliers/${supplierId}`, payload) : apiClient.post('/inventory/suppliers', payload)))
}
export async function deleteInventorySupplier({ supplierId }) { return unwrapData(await apiClient.delete(`/inventory/suppliers/${supplierId}`)) }
export async function assignSupplierBranch({ supplierId, branchId }) { return unwrapData(await apiClient.post(`/inventory/suppliers/${supplierId}/branches`, { branch_id: branchId })) }
export async function unassignSupplierBranch({ supplierId, branchId }) { return unwrapData(await apiClient.delete(`/inventory/suppliers/${supplierId}/branches/${branchId}`)) }
export async function createInventoryPurchase({ accountId, branchId, values, clientRequestId }) { return unwrapData(await apiClient.post('/purchases', { account_id: accountId, branch_id: branchId, purchase_number: values.purchaseNumber || values.reference || `PUR-${Date.now()}`, purchase_date: values.purchaseDate, supplier_id: values.supplierId || null, external_reference: values.supplierReference || null, currency_code: 'IDR', notes: values.notes || null, client_request_id: clientRequestId, lines: values.lines.map((line) => ({ inventory_item_id: line.itemId, quantity: line.quantity, unit_cost: line.unitPrice, notes: line.notes || null })) })) }
export async function receiveInventoryPurchase({ purchaseId, values, clientRequestId }) { return unwrapData(await apiClient.post(`/purchases/${purchaseId}/receive`, { location_id: values.locationId, person_id: values.personId || null, notes: values.notes || null, client_request_id: clientRequestId, lines: values.lines.filter((line) => Number(line.quantity) > 0).map((line) => ({ purchase_line_id: line.purchaseLineId, quantity: line.quantity })) })) }
export const cancelInventoryPurchase = () => unsupported('cancelInventoryPurchase')
export async function saveInventoryItem({ accountId, itemId, values }) { const payload={ ...values, account_id: accountId, component_id: values.componentId || null, minimum_stock: values.minimumStock === '' ? null : values.minimumStock }; return unwrapData(await (itemId ? apiClient.put(`/inventory/items/${itemId}`, payload) : apiClient.post('/inventory/items', payload))) }
export async function deleteInventoryItem({ itemId }) { return unwrapData(await apiClient.patch(`/inventory/items/${itemId}`, { is_active: false })) }
export async function saveInventoryLocation({ accountId, locationId, values }) { const payload={ ...values, account_id: accountId, branch_id: values.branchId || null }; return unwrapData(await (locationId ? apiClient.put(`/inventory/locations/${locationId}`, payload) : apiClient.post('/inventory/locations', payload))) }
export async function deleteInventoryLocation({ locationId }) { return unwrapData(await apiClient.patch(`/inventory/locations/${locationId}`, { is_active: false })) }
export async function initializeInventoryStock({ values, clientRequestId }) { return unwrapData(await apiClient.post('/inventory/opening', { item_id: values.itemId, location_id: values.locationId, quantity: values.quantity, unit_cost: values.costState === 'known' ? values.unitCost : null, reason: values.notes || 'Opening balance', occurred_at: values.occurredAt, person_id: values.personId || null, client_request_id: clientRequestId })) }
export async function adjustInventoryStock({ values, clientRequestId }) { return unwrapData(await apiClient.post('/inventory/adjustments', { item_id: values.itemId, location_id: values.locationId, quantity: values.direction === 'in' ? values.quantity : -Math.abs(values.quantity), unit_cost: values.direction === 'in' && values.costState === 'known' ? values.unitCost : null, reason: values.reason, person_id: values.personId || null, client_request_id: clientRequestId })) }
export async function transferInventoryStock({ values, clientRequestId }) { return unwrapData(await apiClient.post('/inventory/transfers', { item_id: values.itemId, from_location_id: values.sourceLocationId, to_location_id: values.destinationLocationId, quantity: values.quantity, person_id: values.personId || null, client_request_id: clientRequestId })) }

function unsupported(operation) { throw new Error(`inventory.${operation} is not implemented for Laravel Production mode. No Supabase fallback was attempted.`) }
