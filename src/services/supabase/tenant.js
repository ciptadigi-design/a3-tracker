import { supabase } from './client.js'

export async function loadTenantContext(userId) {
  const [profileResult, membershipResult, platformResult] = await Promise.all([
    supabase
      .from('profiles')
      .select('user_id, display_name, username, avatar_path, locale')
      .eq('user_id', userId)
      .maybeSingle(),
    supabase
      .from('account_memberships')
      .select('id, account_id, role, status')
      .eq('user_id', userId)
      .eq('status', 'active'),
    supabase.from('platform_user_privileges').select('role,is_active').eq('user_id', userId).eq('is_active', true).maybeSingle(),
  ])

  if (profileResult.error) throw profileResult.error
  if (membershipResult.error) throw membershipResult.error
  if (platformResult.error) throw platformResult.error

  const memberships = membershipResult.data ?? []
  const isPlatformSuperuser = platformResult.data?.role === 'superuser'
  let accountIds = [...new Set(memberships.map((membership) => membership.account_id))]

  if (isPlatformSuperuser) {
    const { data, error } = await supabase.from('accounts').select('id').eq('status', 'active')
    if (error) throw error
    accountIds = data.map((account) => account.id)
  }

  if (accountIds.length === 0) {
    return { profile: profileResult.data, memberships: [], accounts: [], branches: [], isPlatformSuperuser }
  }

  const [accountsResult, branchesResult, permissionsResult] = await Promise.all([
    supabase
      .from('accounts')
      .select('id, code, name, default_timezone, status, machine_economics_advanced_enabled')
      .in('id', accountIds)
      .eq('status', 'active')
      .order('name'),
    supabase
      .from('branches')
      .select('id, account_id, code, name, timezone, is_active')
      .in('account_id', accountIds)
      .eq('is_active', true)
      .order('name'),
    supabase
      .from('account_operational_permissions')
      .select('account_id, operator_can_initialize_component, operator_can_replace_component, operator_can_create_purchase, operator_can_receive_goods, operator_can_adjust_inventory, operator_can_transfer_inventory, operator_can_log_errors')
      .in('account_id', accountIds),
  ])

  if (accountsResult.error) throw accountsResult.error
  if (branchesResult.error) throw branchesResult.error
  if (permissionsResult.error) throw permissionsResult.error

  return {
    profile: profileResult.data,
    memberships,
    accounts: accountsResult.data ?? [],
    branches: branchesResult.data ?? [],
    permissions: permissionsResult.data ?? [],
    isPlatformSuperuser,
  }
}

export async function setMachineEconomicsAdvancedEnabled({ accountId, enabled }) {
  const { data, error } = await supabase.rpc('set_machine_economics_advanced_enabled', {
    target_account_id: accountId,
    target_enabled: enabled,
  })
  if (error) throw error
  return data
}
