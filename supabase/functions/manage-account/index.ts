import { createClient } from 'npm:@supabase/supabase-js@2'
import { corsHeaders, json, originAllowed } from '../_shared/http.ts'

function validPassword(password: string) {
  return password.length >= 10 && password.length <= 128
}

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: corsHeaders(request) })
  if (request.method !== 'POST' || !originAllowed(request)) return json(request, 403, { error: 'Account management is not available.' })
  const authorization = request.headers.get('authorization') ?? ''
  if (!authorization.startsWith('Bearer ')) return json(request, 401, { error: 'Authentication required.' })

  try {
    const body = await request.json()
    const action = String(body.action ?? '')
    const clientRequestId = String(body.clientRequestId ?? '')
    const url = Deno.env.get('SUPABASE_URL')!
    const anonKey = Deno.env.get('SUPABASE_ANON_KEY')!
    const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!
    const userClient = createClient(url, anonKey, { global: { headers: { Authorization: authorization } }, auth: { persistSession: false } })
    const { data: caller, error: callerError } = await userClient.auth.getUser(authorization.slice(7))
    if (callerError || !caller.user || !caller.user.email) return json(request, 401, { error: 'Authentication required.' })

    if (action === 'profile') {
      const displayName = String(body.displayName ?? '').trim()
      const username = String(body.username ?? '').trim().toLowerCase()
      const { data, error } = await userClient.rpc('manage_my_profile', {
        target_display_name: displayName, target_username: username, target_client_request_id: clientRequestId,
      })
      if (error) return json(request, error.code === '23505' ? 409 : 400, {
        error: error.code === '23505' ? 'Username or request is already in use.' : 'Profile details could not be saved.',
      })
      return json(request, 200, { userId: data.user_id, displayName: data.display_name, username: data.username })
    }

    if (action !== 'email' && action !== 'password') return json(request, 400, { error: 'Unsupported account operation.' })
    const currentPassword = String(body.currentPassword ?? '')
    if (!currentPassword) return json(request, 400, { error: 'Current password is required.' })
    const verifier = createClient(url, anonKey, { auth: { persistSession: false, autoRefreshToken: false } })
    const verification = await verifier.auth.signInWithPassword({ email: caller.user.email, password: currentPassword })
    if (verification.error || verification.data.user?.id !== caller.user.id) {
      return json(request, 403, { error: 'Current password is incorrect.' })
    }

    const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } })
    if (action === 'email') {
      const email = String(body.email ?? '').trim().toLowerCase()
      if (!/^[^\s@]+@[^\s@]+$/.test(email)) return json(request, 400, { error: 'A valid email is required.' })
      const result = await admin.auth.admin.updateUserById(caller.user.id, { email, email_confirm: true })
      if (result.error) return json(request, result.error.status === 422 ? 409 : 502, { error: 'Email could not be updated.' })
      const { error: auditError } = await admin.rpc('record_identity_auth_change', {
        target_actor_id: caller.user.id, target_user_id: caller.user.id, target_action: 'email.update',
        target_client_request_id: clientRequestId, target_request_payload: { email },
        target_before: { email: caller.user.email.toLowerCase() }, target_after: { email },
      })
      if (auditError) return json(request, 503, { error: 'Email changed, but audit completion is pending. Retry with the same request.', retryable: true })
      return json(request, 200, { userId: caller.user.id, email })
    }

    const password = String(body.password ?? '')
    if (!validPassword(password)) return json(request, 400, { error: 'New password must be 10 to 128 characters.' })
    const result = await admin.auth.admin.updateUserById(caller.user.id, { password })
    if (result.error) return json(request, 502, { error: 'Password could not be updated.' })
    const { error: auditError } = await admin.rpc('record_identity_auth_change', {
      target_actor_id: caller.user.id, target_user_id: caller.user.id, target_action: 'password.update',
      target_client_request_id: clientRequestId, target_request_payload: { credential_replaced: true },
      target_before: null, target_after: { credential_replaced: true },
    })
    if (auditError) return json(request, 503, { error: 'Password changed, but audit completion is pending. Retry with the same request.', retryable: true })
    return json(request, 200, { userId: caller.user.id, credentialReplaced: true })
  } catch {
    return json(request, 500, { error: 'Account management failed safely. Retry with the same request.' })
  }
})
