import { callBackend } from './dataBackend.js'
export { effectiveProfiles } from '../features/components/componentAssignmentContracts.js'
const adapters = { supabase: () => import('./supabase/components.js'), laravel: () => import('./laravel/components.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'components', operation, args, ...adapters })
export const loadComponentFoundation = (args) => invoke('loadComponentFoundation', args)
export const adoptIntelligenceRecommendation = (args) => invoke('adoptIntelligenceRecommendation', args)
export const saveComponent = (args) => invoke('saveComponent', args)
export const setComponentStatus = (args) => invoke('setComponentStatus', args)
export const saveProfile = (args) => invoke('saveProfile', args)
export const setProfileStatus = (args) => invoke('setProfileStatus', args)
export const addMachineComponent = (args) => invoke('addMachineComponent', args)
export const removeMachineComponent = (args) => invoke('removeMachineComponent', args)
export const clearMachineComponentExclusion = (args) => invoke('clearMachineComponentExclusion', args)
export const syncMachineComponents = (args) => invoke('syncMachineComponents', args)
export const reconcileManualComponent = (args) => invoke('reconcileManualComponent', args)
export const getReconciliationCandidate = (args) => invoke('getReconciliationCandidate', args)
