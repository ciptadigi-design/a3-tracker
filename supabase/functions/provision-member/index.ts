import { createClient, type User } from 'npm:@supabase/supabase-js@2'
import { corsHeaders, json, originAllowed } from '../_shared/http.ts'

type AdminClient = ReturnType<typeof createClient>

async function findUserByEmail(admin: AdminClient, targetEmail: string): Promise<User | null> {
  for (let page = 1; page <= 20; page += 1) {
    const { data, error } = await admin.auth.admin.listUsers({ page, perPage: 1000 })
    if (error) throw error
    const found = data.users.find((user) => user.email?.trim().toLowerCase() === targetEmail)
    if (found) return found
    if (data.users.length < 1000) return null
  }
  throw new Error('AUTH_DIRECTORY_LIMIT')
}

function validPassword(password: string) {
  return password.length >= 10 && password.length <= 128
}

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: corsHeaders(request) })
  if (request.method !== 'POST' || !originAllowed(request)) return json(request, 403, { error: 'Member administration is not available.' })
  const authorization = request.headers.get('authorization') ?? ''
  if (!authorization.startsWith('Bearer ')) return json(request, 401, { error: 'Authentication required.' })

  try {
    const body = await request.json()
    const action = String(body.action ?? 'direct_create')
    const accountId = String(body.accountId ?? '')
    const targetUserId = body.targetUserId ? String(body.targetUserId) : null
    const clientRequestId = String(body.clientRequestId ?? '')
    const url = Deno.env.get('SUPABASE_URL')!
    const anonKey = Deno.env.get('SUPABASE_ANON_KEY')!
    const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!
    const userClient = createClient(url, anonKey, { global: { headers: { Authorization: authorization } }, auth: { persistSession: false } })
    const { data: caller, error: callerError } = await userClient.auth.getUser(authorization.slice(7))
    if (callerError || !caller.user) return json(request, 401, { error: 'Authentication required.' })
    const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } })

    if (action === 'update_email' || action === 'reset_password') {
      if (!targetUserId) return json(request, 400, { error: 'Managed member identity is required.' })
      const email = action === 'update_email' ? String(body.email ?? '').trim().toLowerCase() : null
      const password = action === 'reset_password' ? String(body.password ?? '') : null
      if (password !== null && !validPassword(password)) return json(request, 400, { error: 'Password must be 10 to 128 characters.' })
      const dbAction = action === 'update_email' ? 'member.email' : 'member.password_reset'
      const parameters = {
        target_account_id: accountId, target_user_id: targetUserId, target_action: dbAction,
        target_email: email, target_client_request_id: clientRequestId,
      }
      const { error: prepareError } = await userClient.rpc('prepare_managed_auth_change', parameters)
      if (prepareError) return json(request, prepareError.code === '42501' ? 403 : prepareError.code === '23505' ? 409 : 400, {
        error: prepareError.code === '23505' ? 'Email or request is already in use.' : 'Managed identity change could not be validated.',
      })
      const attributes = action === 'update_email' ? { email: email!, email_confirm: true } : { password: password! }
      const { error: updateError } = await admin.auth.admin.updateUserById(targetUserId, attributes)
      if (updateError) return json(request, 502, { error: 'Auth identity update did not complete. Retry with the same request.' })
      const { error: finishError } = await userClient.rpc('finish_managed_auth_change', parameters)
      if (finishError) return json(request, 503, { error: 'Auth identity is safe, but audit completion is pending. Retry with the same request.', retryable: true })
      return json(request, 200, { userId: targetUserId, action, status: 'completed' })
    }

    if (action !== 'direct_create' && action !== 'activate') return json(request, 400, { error: 'Unsupported member operation.' })
    const email = String(body.email ?? '').trim().toLowerCase()
    const displayName = String(body.displayName ?? '').trim()
    const username = String(body.username ?? '').trim().toLowerCase()
    const password = String(body.password ?? '')
    const role = String(body.role ?? '')
    const branchIds = Array.isArray(body.branchIds) ? [...new Set(body.branchIds.map(String))].sort() : []
    if (!validPassword(password)) return json(request, 400, { error: 'Initial password must be 10 to 128 characters.' })
    const parameters = {
      target_account_id: accountId, target_user_id: targetUserId, target_email: email,
      target_display_name: displayName, target_username: username, target_role: role,
      target_branch_ids: branchIds, target_operation: action, target_client_request_id: clientRequestId,
    }
    const { data: prepared, error: prepareError } = await userClient.rpc('prepare_direct_member_provisioning', parameters)
    if (prepareError) return json(request, prepareError.code === '42501' ? 403 : prepareError.code === '23505' ? 409 : 400, {
      error: prepareError.code === '23505' ? 'Username, email, membership, or request is already in use.' : 'Member details could not be validated.',
    })

    let authUser: User | null = null
    if (action === 'activate') {
      const result = await admin.auth.admin.getUserById(targetUserId!)
      if (result.error || !result.data.user || result.data.user.email?.trim().toLowerCase() !== email) {
        return json(request, 409, { error: 'Invited Auth identity no longer matches this member.' })
      }
      authUser = result.data.user
      const { data, error } = await admin.auth.admin.updateUserById(authUser.id, {
        email, password, email_confirm: true,
        user_metadata: { ...authUser.user_metadata, display_name: displayName },
      })
      if (error || !data.user) return json(request, 502, { error: 'Account activation did not complete. Retry with the same request.' })
      authUser = data.user
    } else {
      authUser = await findUserByEmail(admin, email)
      if (authUser) {
        const sameRequest = prepared?.auth_user_id === authUser.id
          || authUser.user_metadata?.provisioning_request_id === clientRequestId
        if (!sameRequest) return json(request, 409, { error: 'Email is already in use.' })
        const updated = await admin.auth.admin.updateUserById(authUser.id, { password, email_confirm: true })
        if (updated.error || !updated.data.user) return json(request, 502, { error: 'Account reconciliation did not complete. Retry with the same request.' })
        authUser = updated.data.user
      } else {
        const created = await admin.auth.admin.createUser({
          email, password, email_confirm: true,
          user_metadata: { display_name: displayName, provisioning_request_id: clientRequestId },
        })
        if (created.error || !created.data.user) return json(request, 502, { error: 'Account creation did not complete. Retry with the same request.' })
        authUser = created.data.user
      }
    }

    const { data: membership, error: finalizeError } = await userClient.rpc('finalize_direct_member_provisioning', {
      ...parameters, target_user_id: authUser.id,
    })
    if (finalizeError) return json(request, finalizeError.code === '23505' ? 409 : 503, {
      error: 'Auth identity is safe, but workspace setup is incomplete. Retry with the same request.', retryable: true,
    })
    return json(request, 200, { membershipId: membership.id, userId: authUser.id, status: 'active', operation: action })
  } catch {
    return json(request, 500, { error: 'Member administration failed safely. Retry with the same request.' })
  }
})
