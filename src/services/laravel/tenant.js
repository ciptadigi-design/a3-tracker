import { apiClient, unwrapData, unwrapCollection } from '../../lib/api/apiClient.js'

export async function loadTenantContext() {
  const me = unwrapData(await apiClient.get('/me'))
  const accounts = me.accounts ?? []
  const branches = (await Promise.all(accounts.map(async (account) => unwrapCollection(await apiClient.get(`/accounts/${account.id}/branches?per_page=50`))))).flat()
  return {
    profile: me.user ? { user_id: me.user.id, display_name: me.user.name, username: me.user.username } : null,
    memberships: me.memberships ?? [], accounts, branches,
    permissions: [], isPlatformSuperuser: Boolean(me.platform?.is_superuser),
  }
}

export async function setMachineEconomicsAdvancedEnabled() {
  throw new Error('Advanced Economics is deferred and is not available in Laravel Production mode.')
}
