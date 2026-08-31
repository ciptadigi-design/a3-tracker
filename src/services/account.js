import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/account.js'), laravel: () => import('./laravel/account.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'account', operation, args, ...adapters })
export const updateMyProfile = (args) => invoke('updateMyProfile', args)
export const updateMyEmail = (args) => invoke('updateMyEmail', args)
export const updateMyPassword = (args) => invoke('updateMyPassword', args)
