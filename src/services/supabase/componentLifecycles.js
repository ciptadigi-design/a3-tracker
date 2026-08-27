import { supabase } from './client.js'
import { loadMachines } from './machines.js'

export async function loadMachineComponentLifecycles({ accountId }) {
  const [machines, healthResult, historyResult, membersResult] = await Promise.all([
    loadMachines({ accountId }),
    supabase
      .from('machine_component_health')
      .select('*')
      .eq('account_id', accountId)
      .order('display_order'),
    supabase
      .from('component_replacement_history')
      .select('*')
      .eq('account_id', accountId)
      .order('replaced_at', { ascending: false }),
    supabase.rpc('get_account_member_profiles', { target_account_id: accountId }),
  ])

  if (healthResult.error) throw healthResult.error
  if (historyResult.error) throw historyResult.error
  if (membersResult.error) throw membersResult.error
  return {
    machines,
    lifecycles: (healthResult.data ?? []).filter((row) => row.component_code !== 'TEST_COMPONENT' && row.slot_code !== 'TEST_COMPONENT'),
    replacementHistory: historyResult.data ?? [],
    members: membersResult.data ?? [],
  }
}

export async function initializeComponentLifecycle({ accountId, machineId, profileId, installedCounter, installedAt, notes, clientRequestId }) {
  const { data, error } = await supabase.rpc('initialize_machine_component_lifecycle', {
    target_account_id: accountId,
    target_machine_id: machineId,
    target_model_component_profile_id: profileId,
    target_installed_counter: installedCounter == null ? null : installedCounter,
    target_installed_at: installedAt || null,
    target_client_request_id: clientRequestId,
    target_notes: notes?.trim() || null,
  })

  if (error) throw error
  return data
}

export async function replaceComponentLifecycle({ accountId, machineId, lifecycleId, replacementCounter, replacedAt, reason, condition, includeLearning, performedByUserId, performedByName, notes, clientRequestId }) {
  const { data, error } = await supabase.rpc('replace_machine_component', {
    target_account_id: accountId,
    target_machine_id: machineId,
    target_lifecycle_id: lifecycleId,
    target_replacement_counter: replacementCounter,
    target_replaced_at: replacedAt,
    target_replacement_reason: reason,
    target_condition_at_removal: condition,
    target_include_in_adaptive_learning: includeLearning,
    target_performed_by_user_id: performedByUserId,
    target_performed_by_name_snapshot: performedByName,
    target_notes: notes?.trim() || null,
    target_client_request_id: clientRequestId,
  })

  if (error) throw error
  return data
}
