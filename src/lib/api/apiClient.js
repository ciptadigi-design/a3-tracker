/**
 * Transport boundary for the future Laravel adapter. Existing feature modules
 * remain on Supabase until a bounded domain is migrated; this client is inert
 * unless a caller explicitly opts into the Laravel backend.
 */
const backend = import.meta.env.VITE_DATA_BACKEND || 'supabase'
const baseUrl = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')

export const apiBackend = backend

function assertLaravel() {
  if (backend !== 'laravel') throw new Error(`Laravel API client used while VITE_DATA_BACKEND=${backend}.`)
}

async function request(path, options = {}) {
  assertLaravel()
  const response = await fetch(`${baseUrl}${path}`, { credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) }, ...options })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) { const error = new Error(payload.message || 'API request failed.'); error.status = response.status; error.errors = payload.errors || {}; throw error }
  return payload
}

export const apiClient = { get: (path, options) => request(path, { ...options, method: 'GET' }), post: (path, body, options) => request(path, { ...options, method: 'POST', body: JSON.stringify(body ?? {}) }), patch: (path, body, options) => request(path, { ...options, method: 'PATCH', body: JSON.stringify(body ?? {}) }), delete: (path, options) => request(path, { ...options, method: 'DELETE' }) }
