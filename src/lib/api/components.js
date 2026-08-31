import { apiClient } from './apiClient.js'

// Opt-in Laravel adapter for M2.11F. No Supabase writes are performed here.
export const laravelComponents = {
  catalogs: () => apiClient.get('/components'),
  createCatalog: (payload) => apiClient.post('/components', payload),
  profiles: (modelId) => apiClient.get(`/machine-models/${modelId}/profiles`),
  createProfile: (modelId, payload) => apiClient.post(`/machine-models/${modelId}/profiles`, payload),
  addSlot: (profileId, payload) => apiClient.post(`/model-profiles/${profileId}/slots`, payload),
  machineComponents: (machineId) => apiClient.get(`/machines/${machineId}/components`),
  sync: (machineId) => apiClient.post(`/machines/${machineId}/components/sync`),
  addManual: (machineId, payload) => apiClient.post(`/machines/${machineId}/components/manual`, payload),
  exclude: (componentId, payload) => apiClient.post(`/machine-components/${componentId}/exclude`, payload),
  clearExclusion: (exclusionId) => apiClient.post(`/component-exclusions/${exclusionId}/clear`),
  initializeLifecycle: (componentId, payload) => apiClient.post(`/machine-components/${componentId}/lifecycles`, payload),
}
