import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/counters.js'), laravel: () => import('./laravel/counters.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'counters', operation, args, ...adapters })
export const loadCounterHistory = (args) => invoke('loadCounterHistory', args)
export const recordCounterReading = (args) => invoke('recordCounterReading', args)
export const correctCounterReading = (args) => invoke('correctCounterReading', args)
