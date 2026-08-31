import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/tenant.js'), laravel: () => import('./laravel/tenant.js') }
export const loadTenantContext = (userId) => callBackend({ domain: 'tenant', operation: 'loadTenantContext', args: [userId], ...adapters })
export const setMachineEconomicsAdvancedEnabled = (args) => callBackend({ domain: 'tenant', operation: 'setMachineEconomicsAdvancedEnabled', args: [args], ...adapters })
