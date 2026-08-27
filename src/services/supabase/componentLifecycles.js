import { supabase } from './client.js'
import { loadMachines } from './machines.js'

export async function loadMachineComponentLifecycles({ accountId }) {
  const [machines, healthResult] = await Promise.all([
    loadMachines({ accountId }),
    supabase
      .from('machine_component_health')
      .select('*')
      .eq('account_id', accountId)
      .order('display_order'),
  ])

  if (healthResult.error) throw healthResult.error
  return {
    machines,
    lifecycles: (healthResult.data ?? []).filter((row) => row.component_code !== 'TEST_COMPONENT' && row.slot_code !== 'TEST_COMPONENT'),
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

