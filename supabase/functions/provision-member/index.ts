import { createClient, type User } from 'npm:@supabase/supabase-js@2'
import { corsHeaders, json, originAllowed } from '../_shared/http.ts'

async function findUserByEmail(listUsers: (parameters: { page: number; perPage: number }) => ReturnType<ReturnType<typeof createClient>['auth']['admin']['listUsers']>, targetEmail: string): Promise<User | null> {
  for (let page = 1; page <= 20; page += 1) {
    const { data, error } = await listUsers({ page, perPage: 1000 })
    if (error) throw error
    const found = data.users.find((user) => user.email?.trim().toLowerCase() === targetEmail)
    if (found) return found
    if (data.users.length < 1000) return null
  }
  throw new Error('AUTH_DIRECTORY_LIMIT')
}

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: corsHeaders(request) })
  if (request.method !== 'POST' || !originAllowed(request)) return json(request, 403, { error: 'Member provisioning is not available.' })
  const authorization = request.headers.get('authorization') ?? ''
  if (!authorization.startsWith('Bearer ')) return json(request, 401, { error: 'Authentication required.' })

  try {
    const body = await request.json()
    const accountId = String(body.accountId ?? '')
    const email = String(body.email ?? '').trim().toLowerCase()
    const displayName = String(body.displayName ?? '').trim()
    const username = String(body.username ?? '').trim().toLowerCase()
    const role = String(body.role ?? '')
    const branchIds = Array.isArray(body.branchIds) ? [...new Set(body.branchIds.map(String))] : []
    const clientRequestId = String(body.clientRequestId ?? '')
    const url = Deno.env.get('SUPABASE_URL')!
    const anonKey = Deno.env.get('SUPABASE_ANON_KEY')!
    const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!
    const userClient = createClient(url, anonKey, { global: { headers: { Authorization: authorization } }, auth: { persistSession: false } })
    const { data: caller, error: callerError } = await userClient.auth.getUser(authorization.slice(7))
    if (callerError || !caller.user) return json(request, 401, { error: 'Authentication required.' })

    const parameters = {
      target_account_id: accountId, target_email: email, target_display_name: displayName,
      target_username: username, target_role: role, target_branch_ids: branchIds,
      target_client_request_id: clientRequestId,
    }
    const { error: prepareError } = await userClient.rpc('prepare_member_provisioning', parameters)
    if (prepareError) {
      const duplicate = prepareError.code === '23505'
      return json(request, duplicate ? 409 : prepareError.code === '42501' ? 403 : 400, {
        error: duplicate ? 'Username, membership, or request is already in use.' : 'Member details could not be validated.',
      })
    }

    const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } })
    let authUser = await findUserByEmail((parameters) => admin.auth.admin.listUsers(parameters), email)
    let invited = false
    if (!authUser) {
      const redirectTo = Deno.env.get('INVITE_REDIRECT_URL')
      const { data, error } = await admin.auth.admin.inviteUserByEmail(email, {
        data: { display_name: displayName }, ...(redirectTo ? { redirectTo } : {}),
      })
      if (error || !data.user) return json(request, 502, { error: 'Invitation could not be sent. Retry with the same request.' })
      authUser = data.user
      invited = true
    }

    const { data: membership, error: finalizeError } = await userClient.rpc('finalize_member_provisioning', {
      ...parameters, target_user_id: authUser.id,
    })
    if (finalizeError) {
      return json(request, finalizeError.code === '23505' ? 409 : 503, {
        error: 'Auth identity is safe, but workspace setup is incomplete. Retry with the same request.', retryable: true,
      })
    }
    return json(request, 200, { membershipId: membership.id, invited, status: membership.status })
  } catch {
    return json(request, 500, { error: 'Member provisioning failed safely. Retry with the same request.' })
  }
})
