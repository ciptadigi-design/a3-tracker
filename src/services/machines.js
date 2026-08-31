import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/machines.js'), laravel: () => import('./laravel/machines.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'machines', operation, args, ...adapters })
export const loadMachines = (args) => invoke('loadMachines', args)
export const loadMachine = (args) => invoke('loadMachine', args)
export const loadMachineCatalog = (args) => invoke('loadMachineCatalog', args)
export const createMachine = (args) => invoke('createMachine', args)
export const updateMachine = (args) => invoke('updateMachine', args)
export const retireMachine = (args) => invoke('retireMachine', args)
