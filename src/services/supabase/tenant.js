import { supabase } from './client.js'

export async function loadTenantContext(userId) {
  const [profileResult, membershipResult] = await Promise.all([
    supabase
      .from('profiles')
      .select('user_id, display_name, avatar_path, locale')
      .eq('user_id', userId)
      .maybeSingle(),
    supabase
      .from('account_memberships')
      .select('id, account_id, role, status')
      .eq('user_id', userId)
      .eq('status', 'active'),
  ])

  if (profileResult.error) throw profileResult.error
  if (membershipResult.error) throw membershipResult.error

  const memberships = membershipResult.data ?? []
  const accountIds = [...new Set(memberships.map((membership) => membership.account_id))]

  if (accountIds.length === 0) {
    return { profile: profileResult.data, memberships: [], accounts: [], branches: [] }
  }

  const [accountsResult, branchesResult] = await Promise.all([
    supabase
      .from('accounts')
      .select('id, code, name, default_timezone, status')
      .in('id', accountIds)
      .eq('status', 'active')
      .order('name'),
    supabase
      .from('branches')
      .select('id, account_id, code, name, timezone, is_active')
      .in('account_id', accountIds)
      .eq('is_active', true)
      .order('name'),
  ])

  if (accountsResult.error) throw accountsResult.error
  if (branchesResult.error) throw branchesResult.error

  return {
    profile: profileResult.data,
    memberships,
    accounts: accountsResult.data ?? [],
    branches: branchesResult.data ?? [],
  }
}
