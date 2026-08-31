const allowedBackends = new Set(['supabase', 'laravel'])

export function resolveDataBackend(value = 'supabase') {
  const backend = String(value || '').trim().toLowerCase()
  if (!allowedBackends.has(backend)) throw new Error(`Unsupported VITE_DATA_BACKEND: ${value || '(empty)'}. Expected supabase or laravel.`)
  return backend
}

export const dataBackend = resolveDataBackend(import.meta.env.VITE_DATA_BACKEND || 'supabase')

export function unsupportedBackendOperation(domain, operation) {
  return new Error(`${domain}.${operation} is not implemented for VITE_DATA_BACKEND=${dataBackend}. No backend fallback was attempted.`)
}

export async function callBackend({ domain, operation, args, supabase, laravel }) {
  const loader = dataBackend === 'laravel' ? laravel : supabase
  const adapter = await loader()
  const method = adapter[operation]
  if (typeof method !== 'function') throw unsupportedBackendOperation(domain, operation)
  return method(...args)
}
