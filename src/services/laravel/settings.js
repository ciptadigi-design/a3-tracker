import { unsupportedBackendOperation } from '../dataBackend.js'
const unsupported = (operation) => { throw unsupportedBackendOperation('settings', operation) }
export const loadSettings = () => unsupported('loadSettings')
export const updateWorkspace = () => unsupported('updateWorkspace')
export const manageBranch = () => unsupported('manageBranch')
export const updateMembership = () => unsupported('updateMembership')
export const provisionMember = () => unsupported('provisionMember')
export const activateMember = () => unsupported('activateMember')
export const updateManagedMemberEmail = () => unsupported('updateManagedMemberEmail')
export const resetManagedMemberPassword = () => unsupported('resetManagedMemberPassword')
export const updateOperationalPersonBranches = () => unsupported('updateOperationalPersonBranches')
export const updateOperationalPermissions = () => unsupported('updateOperationalPermissions')
export const updateAdvancedEconomics = () => unsupported('updateAdvancedEconomics')
