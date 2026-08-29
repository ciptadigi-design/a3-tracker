import { supabase } from './client.js'
import { operationalError } from '../../lib/appErrors.js'

async function invokeAccount(body, fallback) {
  const { data, error } = await supabase.functions.invoke('manage-account', { body })
  if (error) throw operationalError(error, { operation: `my-account.${body.action}`, clientRequestId: body.clientRequestId }, data?.error || fallback)
  return data
}

export function updateMyProfile({ displayName, username, clientRequestId }) {
  return invokeAccount({
    action: 'profile', displayName: displayName.trim(), username: username.trim().toLowerCase(), clientRequestId,
  }, 'Profile could not be updated.')
}

export async function updateMyEmail({ email, currentPassword, clientRequestId }) {
  const data = await invokeAccount({
    action: 'email', email: email.trim().toLowerCase(), currentPassword, clientRequestId,
  }, 'Email could not be updated.')
  const { error } = await supabase.auth.refreshSession()
  if (error) throw new Error('Email changed, but the session could not refresh. Please sign in again.')
  return data
}

export function updateMyPassword({ currentPassword, password, clientRequestId }) {
  return invokeAccount({
    action: 'password', currentPassword, password, clientRequestId,
  }, 'Password could not be updated.')
}
