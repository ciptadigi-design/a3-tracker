import { supabase, supabaseConfigurationError } from './client.js'
import { reportFailure } from '../../lib/appErrors.js'

function requireClient() { if (!supabase) throw new Error(supabaseConfigurationError); return supabase }

export async function restoreSession() {
  const { data, error } = await requireClient().auth.getSession()
  if (error) throw error
  return data.session ?? null
}

export function subscribe(callback) {
  const { data } = requireClient().auth.onAuthStateChange((_event, session) => callback(session))
  return () => data.subscription.unsubscribe()
}

export async function signIn(identifier, password) {
  const client = requireClient()
  const { data, error } = await client.functions.invoke('auth-login', { body: { identifier, password } })
  if (error || !data?.access_token || !data?.refresh_token) throw new Error(data?.error || 'Invalid username/email or password.')
  const result = await client.auth.setSession({ access_token: data.access_token, refresh_token: data.refresh_token })
  if (result.error) throw new Error('Invalid username/email or password.')
  const membership = await client.rpc('accept_current_memberships')
  if (membership.error) {
    reportFailure(membership.error, { operation: 'auth.membership.accept' })
    await client.auth.signOut()
    throw new Error('Workspace access could not be activated. Please try again.')
  }
  return result.data.session
}

export async function completePasswordSetup(password) {
  const client = requireClient()
  const { error } = await client.auth.updateUser({ password })
  if (error) throw error
  const membership = await client.rpc('accept_current_memberships')
  if (membership.error) throw new Error('Workspace access could not be activated. Please try again.')
  return restoreSession()
}

export async function signOut() { const { error } = await requireClient().auth.signOut(); if (error) throw error; return null }

// Clears the current browser session without calling the network revoke endpoint.
// Used right after the caller's own password/email credential changed server-side:
// the previously issued token may already be invalid there, so a normal (global-scope)
// signOut() call could itself fail with 401 and block the local session teardown.
export async function signOutLocal() {
  const client = requireClient()
  try {
    await client.auth.signOut({ scope: 'local' })
  } catch {
    // Local session state is cleared by the caller regardless of this outcome.
  }
  return null
}
