import { supabase } from './client.js'

const branchFields = 'id,account_id,code,name,address,timezone,is_active,notes,created_at,updated_at,archived_at'
const policyFields = 'account_id,operator_can_initialize_component,operator_can_replace_component,operator_can_create_purchase,operator_can_receive_goods,operator_can_adjust_inventory,operator_can_transfer_inventory,operator_can_log_errors,updated_at'

function optional(value) { return value?.trim() || null }

export async function loadSettings({ accountId }) {
  const [branches, members, policy, models, components, profiles, locations, people, manufacturers, audit] = await Promise.all([
    supabase.from('branches').select(branchFields).eq('account_id', accountId).order('is_active', { ascending: false }).order('name'),
    supabase.rpc('get_settings_members', { target_account_id: accountId }),
    supabase.from('account_operational_permissions').select(policyFields).eq('account_id', accountId).single(),
    supabase.from('machine_models').select('id,account_id,manufacturer_id,model_code,name,machine_category,color_capability,description,notes,is_active,created_at,updated_at,archived_at').or(`account_id.is.null,account_id.eq.${accountId}`).order('name'),
    supabase.from('components').select('id,account_id,is_active').or(`account_id.is.null,account_id.eq.${accountId}`),
    supabase.from('machine_model_components').select('id,account_id,is_active').or(`account_id.is.null,account_id.eq.${accountId}`),
    supabase.from('inventory_locations').select('id,is_active').eq('account_id', accountId),
    supabase.from('operational_people').select('id,account_id,name,code,is_active,notes,created_at,updated_at,archived_at,operational_person_branches(branch_id,is_active,branches(name))').eq('account_id', accountId).order('name'),
    supabase.from('manufacturers').select('id,account_id,code,name,website,notes,is_active,created_at,updated_at,archived_at').or(`account_id.is.null,account_id.eq.${accountId}`).order('name'),
    supabase.from('settings_change_events').select('id,action,target_type,actor_name_snapshot,created_at').eq('account_id', accountId).order('created_at', { ascending: false }).limit(8),
  ])
  for (const result of [branches, members, policy, models, components, profiles, locations, people, manufacturers, audit]) if (result.error) throw result.error
  return {
    branches: branches.data ?? [], members: members.data ?? [], policy: policy.data,
    models: models.data ?? [], components: components.data ?? [], profiles: profiles.data ?? [],
    locations: locations.data ?? [], people: people.data ?? [], manufacturers: manufacturers.data ?? [], audit: audit.data ?? [],
  }
}

export async function updateWorkspace({ accountId, values, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_workspace_settings', {
    target_account_id: accountId, target_name: values.name.trim(),
    target_default_timezone: values.defaultTimezone, target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}

export async function manageBranch({ accountId, branch, action, values = {}, clientRequestId }) {
  const source = values.code ? values : branch
  const { data, error } = await supabase.rpc('manage_settings_branch', {
    target_account_id: accountId, target_branch_id: branch?.id ?? null, target_action: action,
    target_code: source?.code ?? '', target_name: source?.name ?? '', target_address: optional(source?.address),
    target_timezone: source?.timezone || null, target_notes: optional(source?.notes), target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}

export async function updateMembership({ accountId, member, role, status, branchIds, username, displayName, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_settings_membership', {
    target_account_id: accountId, target_user_id: member.user_id, target_role: role,
    target_status: status, target_client_request_id: clientRequestId,
    target_branch_ids: role === 'owner' ? [] : branchIds,
    target_username: username || null,
    target_display_name: displayName,
  })
  if (error) throw error
  return data
}

export async function provisionMember({ accountId, values }) {
  const { data, error } = await supabase.functions.invoke('provision-member', { body: {
    accountId, displayName: values.displayName.trim(), username: values.username.trim().toLowerCase(),
    email: values.email.trim().toLowerCase(), role: values.role, branchIds: values.branchIds,
    clientRequestId: values.clientRequestId,
  } })
  if (error) throw new Error(data?.error || 'Member invitation could not be completed.')
  return data
}

export async function updateOperationalPersonBranches({ accountId, personId, branchIds, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_operational_person_branches', {
    target_account_id: accountId, target_person_id: personId, target_branch_ids: branchIds,
    target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}

export async function updateOperationalPermissions({ accountId, policy, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_operational_permissions', {
    target_account_id: accountId,
    target_operator_can_initialize_component: policy.operator_can_initialize_component,
    target_operator_can_replace_component: policy.operator_can_replace_component,
    target_operator_can_create_purchase: policy.operator_can_create_purchase,
    target_operator_can_receive_goods: policy.operator_can_receive_goods,
    target_operator_can_adjust_inventory: policy.operator_can_adjust_inventory,
    target_operator_can_transfer_inventory: policy.operator_can_transfer_inventory,
    target_operator_can_log_errors: policy.operator_can_log_errors,
    target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}

export async function updateAdvancedEconomics({ accountId, enabled, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_advanced_economics_setting', {
    target_account_id: accountId, target_enabled: enabled, target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}
