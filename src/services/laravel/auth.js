import { apiClient, csrfCookie, unwrapData } from '../../lib/api/apiClient.js'

function sessionFrom(payload) {
  const data = unwrapData(payload)
  return data?.user ? { user: data.user, context: data } : null
}

export async function restoreSession() {
  try { return sessionFrom(await apiClient.get('/me')) } catch (error) { if (error.status === 401) return null; throw error }
}

export async function signIn(identifier, password) {
  await csrfCookie()
  await apiClient.post('/auth/login', { identifier, password })
  const session = await restoreSession()
  if (!session) throw new Error('Authentication succeeded but the session could not be restored.')
  return session
}

export async function signOut() { await apiClient.post('/auth/logout'); return null }

// Best-effort logout used right after the caller's own password changed server-side.
// The session cookie may already be stale, so a failed network call must not block
// clearing local application state.
export async function signOutLocal() {
  try { await apiClient.post('/auth/logout') } catch { /* local state is cleared by the caller regardless */ }
  return null
}
export async function completePasswordSetup() { throw new Error('Password invitation completion is not available in Laravel Production mode.') }
export function subscribe() { return () => {} }
