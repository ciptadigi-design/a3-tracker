import { apiClient } from './apiClient.js'
export const laravelInventory = {
  items: (params = '') => apiClient.get(`/inventory/items${params ? `?${params}` : ''}`),
  locations: () => apiClient.get('/inventory/locations'),
  createPurchase: (payload) => apiClient.post('/purchases', payload),
  receive: (purchaseId, payload) => apiClient.post(`/purchases/${purchaseId}/receive`, payload),
  balance: (itemId, locationId) => apiClient.get(`/inventory/items/${itemId}/locations/${locationId}/balance`),
  transfer: (payload) => apiClient.post('/inventory/transfers', payload),
  adjust: (payload) => apiClient.post('/inventory/adjustments', payload),
  replace: (componentId, payload) => apiClient.post(`/machine-components/${componentId}/replacements`, payload),
}
