import { createClient } from 'npm:@supabase/supabase-js@2'
import { corsHeaders, json } from '../_shared/http.ts'

function constantTimeEqual(left: string, right: string) {
  const encoder = new TextEncoder()
  const leftBytes = encoder.encode(left)
  const rightBytes = encoder.encode(right)
  let difference = leftBytes.length ^ rightBytes.length
  const length = Math.max(leftBytes.length, rightBytes.length)
  for (let index = 0; index < length; index += 1) difference |= (leftBytes[index] ?? 0) ^ (rightBytes[index] ?? 0)
  return difference === 0
}

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: corsHeaders(request) })
  if (request.method !== 'POST') return json(request, 404, { error: 'Not found.' })
  try {
    const body = await request.json()
    const userId = String(body.userId ?? '')
    const token = String(body.bootstrapToken ?? '')
    const configuredUserId = Deno.env.get('PLATFORM_BOOTSTRAP_USER_ID') ?? ''
    const configuredToken = Deno.env.get('PLATFORM_BOOTSTRAP_TOKEN') ?? ''
    if (!configuredUserId || !configuredToken || userId !== configuredUserId || !constantTimeEqual(token, configuredToken)) {
      return json(request, 404, { error: 'Not found.' })
    }
    const admin = createClient(Deno.env.get('SUPABASE_URL')!, Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!, {
      auth: { persistSession: false, autoRefreshToken: false },
    })
    const identity = await admin.auth.admin.getUserById(userId)
    if (identity.error || !identity.data.user) return json(request, 404, { error: 'Not found.' })
    const { data, error } = await admin.rpc('bootstrap_platform_superuser', {
      target_user_id: userId, target_operator_id: userId, target_notes: 'Explicit operator-controlled DEV bootstrap',
    })
    if (error) return json(request, 500, { error: 'Bootstrap did not complete.' })
    return json(request, 200, { userId: data.user_id, role: data.role, isActive: data.is_active })
  } catch {
    return json(request, 404, { error: 'Not found.' })
  }
})
