import { createClient } from 'npm:@supabase/supabase-js@2'
import { corsHeaders, json, originAllowed } from '../_shared/http.ts'

const genericFailure = 'Invalid username/email or password.'

Deno.serve(async (request) => {
  if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: corsHeaders(request) })
  if (request.method !== 'POST' || !originAllowed(request)) return json(request, 403, { error: genericFailure })
  try {
    const body = await request.json()
    const identifier = String(body.identifier ?? '').trim().toLowerCase()
    const password = String(body.password ?? '')
    if (!identifier || !password || identifier.length > 254 || password.length > 1024) return json(request, 401, { error: genericFailure })

    const url = Deno.env.get('SUPABASE_URL')!
    const anonKey = Deno.env.get('SUPABASE_ANON_KEY')!
    const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!
    let email = identifier
    if (!identifier.includes('@')) {
      if (!/^[a-z0-9._-]{3,32}$/.test(identifier)) return json(request, 401, { error: genericFailure })
      const admin = createClient(url, serviceKey, { auth: { persistSession: false, autoRefreshToken: false } })
      const { data: profile, error: lookupError } = await admin.from('profiles').select('user_id').eq('username_normalized', identifier).maybeSingle()
      if (lookupError || !profile) email = 'invalid-username-login@invalid.local'
      else {
      const { data: authUser, error: userError } = await admin.auth.admin.getUserById(profile.user_id)
      email = userError || !authUser.user?.email ? 'invalid-username-login@invalid.local' : authUser.user.email
      }
    }

    const authClient = createClient(url, anonKey, { auth: { persistSession: false, autoRefreshToken: false } })
    const { data, error } = await authClient.auth.signInWithPassword({ email, password })
    if (error || !data.session) return json(request, 401, { error: genericFailure })
    return json(request, 200, {
      access_token: data.session.access_token,
      refresh_token: data.session.refresh_token,
      expires_in: data.session.expires_in,
      token_type: data.session.token_type,
    })
  } catch {
    return json(request, 401, { error: genericFailure })
  }
})
