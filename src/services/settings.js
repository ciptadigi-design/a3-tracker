import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/settings.js'), laravel: () => import('./laravel/settings.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'settings', operation, args, ...adapters })
export const loadSettings = (args) => invoke('loadSettings', args)
export const updateWorkspace = (args) => invoke('updateWorkspace', args)
export const manageBranch = (args) => invoke('manageBranch', args)
export const updateMembership = (args) => invoke('updateMembership', args)
export const provisionMember = (args) => invoke('provisionMember', args)
export const activateMember = (args) => invoke('activateMember', args)
export const updateManagedMemberEmail = (args) => invoke('updateManagedMemberEmail', args)
export const resetManagedMemberPassword = (args) => invoke('resetManagedMemberPassword', args)
export const updateOperationalPersonBranches = (args) => invoke('updateOperationalPersonBranches', args)
export const updateOperationalPermissions = (args) => invoke('updateOperationalPermissions', args)
export const updateAdvancedEconomics = (args) => invoke('updateAdvancedEconomics', args)
