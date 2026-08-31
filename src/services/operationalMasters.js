import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/operationalMasters.js'), laravel: () => import('./laravel/operationalMasters.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'operational-masters', operation, args, ...adapters })
export const loadOperationalMasters = (args) => invoke('loadOperationalMasters', args)
export const loadOperationalPeopleForBranch = (args) => invoke('loadOperationalPeopleForBranch', args)
export const saveOperationalPerson = (args) => invoke('saveOperationalPerson', args)
export const deleteOperationalPerson = (args) => invoke('deleteOperationalPerson', args)
export const saveManufacturer = (args) => invoke('saveManufacturer', args)
export const setManufacturerStatus = (args) => invoke('setManufacturerStatus', args)
export const deleteManufacturer = (args) => invoke('deleteManufacturer', args)
export const saveMachineModel = (args) => invoke('saveMachineModel', args)
export const setMachineModelStatus = (args) => invoke('setMachineModelStatus', args)
export const deleteMachineModel = (args) => invoke('deleteMachineModel', args)
