import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/operationalIncidents.js'), laravel: () => import('./laravel/incidents.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'incidents', operation, args, ...adapters })
export const loadOperationalIncidents = (args) => invoke('loadOperationalIncidents', args)
export const loadOperationalIncident = (args) => invoke('loadOperationalIncident', args)
export const updateOperationalIncident = (args) => invoke('updateOperationalIncident', args)
export const solveOperationalIncident = (args) => invoke('solveOperationalIncident', args)
export const createOperationalIncident = (args) => invoke('createOperationalIncident', args)
export const resolveOperationalIncident = (args) => invoke('resolveOperationalIncident', args)
export const voidOperationalIncident = (args) => invoke('voidOperationalIncident', args)
