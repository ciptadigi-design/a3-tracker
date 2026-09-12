const allowedBackends = new Set(['supabase', 'laravel'])

export function resolveDataBackend(value = 'supabase') {
  const backend = String(value || '').trim().toLowerCase()
  if (!allowedBackends.has(backend)) throw new Error(`Unsupported VITE_DATA_BACKEND: ${value || '(empty)'}. Expected supabase or laravel.`)
  return backend
}

// A missing VITE_DATA_BACKEND used to fall back to 'supabase' unconditionally -
// exactly how Production shipped a Supabase-routed frontend against a
// Laravel-only backend when the build pipeline forgot to set it (M2.17.5.6).
// An explicit value (either backend) is always honored, so DEV/staging can still
// intentionally opt into the Supabase behavioral oracle. Only a *missing* value in
// a production build (import.meta.env.PROD) fails closed instead of defaulting.
export function selectDataBackend(configuredValue, isProductionBuild) {
  if (configuredValue) return resolveDataBackend(configuredValue)
  if (isProductionBuild) {
    throw new Error('VITE_DATA_BACKEND is required in a production build and was not set. Refusing to silently default to Supabase in Production.')
  }
  return 'supabase'
}

export const dataBackend = selectDataBackend(import.meta.env?.VITE_DATA_BACKEND, import.meta.env?.PROD === true)

export function unsupportedBackendOperation(domain, operation) {
  return new Error(`${domain}.${operation} is not implemented for VITE_DATA_BACKEND=${dataBackend}. No backend fallback was attempted.`)
}

export async function callSelectedBackend({ backend = dataBackend, domain, operation, args, supabase, laravel }) {
  const selected = resolveDataBackend(backend)
  const loader = selected === 'laravel' ? laravel : supabase
  const adapter = await loader()
  const method = adapter[operation]
  if (typeof method !== 'function') throw unsupportedBackendOperation(domain, operation)
  return method(...args)
}

export async function callBackend(options) {
  return callSelectedBackend(options)
}
