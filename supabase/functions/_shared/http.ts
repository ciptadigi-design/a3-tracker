const localhost = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/

export function corsHeaders(request: Request) {
  const origin = request.headers.get('origin') ?? ''
  const allowed = (Deno.env.get('ALLOWED_ORIGINS') ?? '').split(',').map((value) => value.trim()).filter(Boolean)
  const acceptedOrigin = allowed.includes(origin) || localhost.test(origin) ? origin : allowed[0] ?? ''
  return {
    'Access-Control-Allow-Origin': acceptedOrigin,
    'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Cache-Control': 'no-store',
    'Content-Type': 'application/json',
    Vary: 'Origin',
  }
}

export function json(request: Request, status: number, body: Record<string, unknown>) {
  return new Response(JSON.stringify(body), { status, headers: corsHeaders(request) })
}

export function originAllowed(request: Request) {
  const origin = request.headers.get('origin') ?? ''
  const allowed = (Deno.env.get('ALLOWED_ORIGINS') ?? '').split(',').map((value) => value.trim()).filter(Boolean)
  return allowed.includes(origin) || localhost.test(origin)
}
