import { callBackend, dataBackend } from './dataBackend.js'

const adapters = { supabase: () => import('./supabase/auth.js'), laravel: () => import('./laravel/auth.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'auth', operation, args, ...adapters })

export const authBackend = dataBackend
export const restoreSession = () => invoke('restoreSession')
export const subscribeToSession = (callback) => {
  let dispose = () => {}
  let active = true
  adapters[dataBackend]().then((adapter) => { if (active) dispose = adapter.subscribe(callback) })
  return () => { active = false; dispose() }
}
export const signIn = (identifier, password) => invoke('signIn', identifier, password)
export const completePasswordSetup = (password) => invoke('completePasswordSetup', password)
export const signOut = () => invoke('signOut')
