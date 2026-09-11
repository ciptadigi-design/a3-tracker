/**
 * Transport boundary for the future Laravel adapter. Existing feature modules
 * remain on Supabase until a bounded domain is migrated; this client is inert
 * unless a caller explicitly opts into the Laravel backend.
 */
import { dataBackend } from '../../services/dataBackend.js'

const backend = dataBackend
const baseUrl = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')

export const apiBackend = backend

function assertLaravel() {
  if (backend !== 'laravel') throw new Error(`Laravel API client used while VITE_DATA_BACKEND=${backend}.`)
}

export async function apiRequest(path, options = {}) {
  assertLaravel()
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) }
  const csrf = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')
  if (csrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf)
  const response = await fetch(`${baseUrl}${path}`, { credentials: 'include', ...options, headers })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) { const error = new Error(payload.message || 'API request failed.'); error.status = response.status; error.errors = payload.errors || {}; throw error }
  return payload
}

export function unwrapData(payload) { return payload?.data ?? payload }
export function unwrapCollection(payload) { const data = unwrapData(payload); return Array.isArray(data) ? data : data?.data ?? [] }

// Laravel's default ValidationException message truncates to "first error (and N more errors)".
// The `errors` map on a 422 response always carries every field's messages, so prefer that
// when present instead of showing the user an incomplete summary.
export function describeApiError(error) {
  const fieldMessages = error?.errors && typeof error.errors === 'object' ? Object.values(error.errors).flat() : []
  return fieldMessages.length ? fieldMessages.join(' ') : error?.message ?? 'The request could not be completed.'
}

// Backend-agnostic "this record is referenced elsewhere" detection: Postgres/PostgREST
// surfaces foreign-key violations as error.code (23503), while the Laravel API normalizes
// the same condition to an HTTP 409 with no .code at all.
export function isReferenceConflict(error) {
  return error?.status === 409 || error?.code === '23503' || error?.code === '23505'
}

export async function csrfCookie() {
  assertLaravel()
  const response = await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
  if (!response.ok) throw new Error('Laravel CSRF session could not be initialized.')
}

export const apiClient = {
  get: (path, options) => apiRequest(path, { ...options, method: 'GET' }),
  post: (path, body, options) => apiRequest(path, { ...options, method: 'POST', body: JSON.stringify(body ?? {}) }),
  put: (path, body, options) => apiRequest(path, { ...options, method: 'PUT', body: JSON.stringify(body ?? {}) }),
  patch: (path, body, options) => apiRequest(path, { ...options, method: 'PATCH', body: JSON.stringify(body ?? {}) }),
  delete: (path, options) => apiRequest(path, { ...options, method: 'DELETE' }),
}
