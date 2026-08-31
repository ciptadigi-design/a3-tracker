import { apiClient, unwrapCollection, unwrapData } from '../../lib/api/apiClient.js'

export async function loadInventory({ accountId, branchId }) {
  const [items, locations] = await Promise.all([
    apiClient.get(`/inventory/items?account_id=${encodeURIComponent(accountId)}&branch_id=${encodeURIComponent(branchId || '')}&per_page=100`),
    apiClient.get(`/inventory/locations?account_id=${encodeURIComponent(accountId)}&branch_id=${encodeURIComponent(branchId || '')}`),
  ])
  return { items: unwrapCollection(items), locations: unwrapCollection(locations), suppliers: [], purchases: [], movements: [], stock: [] }
}

export const saveInventorySupplier = () => unsupported('saveInventorySupplier')
export const deleteInventorySupplier = () => unsupported('deleteInventorySupplier')
export async function createInventoryPurchase({ accountId, values, clientRequestId }) { return unwrapData(await apiClient.post('/purchases', { ...values, account_id: accountId, client_request_id: clientRequestId })) }
export async function receiveInventoryPurchase({ purchaseId, values, clientRequestId }) { return unwrapData(await apiClient.post(`/purchases/${purchaseId}/receive`, { ...values, client_request_id: clientRequestId })) }
export const cancelInventoryPurchase = () => unsupported('cancelInventoryPurchase')
export const saveInventoryItem = () => unsupported('saveInventoryItem')
export const deleteInventoryItem = () => unsupported('deleteInventoryItem')
export const saveInventoryLocation = () => unsupported('saveInventoryLocation')
export const deleteInventoryLocation = () => unsupported('deleteInventoryLocation')
export async function initializeInventoryStock({ values, clientRequestId }) { return unwrapData(await apiClient.post('/inventory/opening', { ...values, client_request_id: clientRequestId })) }
export async function adjustInventoryStock({ values, clientRequestId }) { return unwrapData(await apiClient.post('/inventory/adjustments', { ...values, client_request_id: clientRequestId })) }
export async function transferInventoryStock({ values, clientRequestId }) { return unwrapData(await apiClient.post('/inventory/transfers', { ...values, client_request_id: clientRequestId })) }

function unsupported(operation) { throw new Error(`inventory.${operation} is not implemented for Laravel Production mode. No Supabase fallback was attempted.`) }
