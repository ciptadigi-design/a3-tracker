import { supabase } from './client.js'
import { loadMachines } from './machines.js'
import { projectCurrentComponentCards } from '../../features/components/componentCardProjection.js'

export async function loadMachineComponentLifecycles({ accountId }) {
  const [machines, healthResult, historyResult, peopleResult, itemsResult, locationsResult, balancesResult] = await Promise.all([
    loadMachines({ accountId }),
    supabase
      .from('machine_component_health')
      .select('*')
      .eq('account_id', accountId)
      .in('lifecycle_status', ['unknown', 'active'])
      .order('display_order'),
    supabase
      .from('component_replacement_history')
      .select('*')
      .eq('account_id', accountId)
      .order('replaced_at', { ascending: false }),
    supabase.from('operational_people').select('id,account_id,name,code,linked_user_id,is_active').eq('account_id', accountId).order('name'),
    supabase.from('inventory_items').select('id,account_id,component_id,sku,name,unit,is_active').eq('account_id', accountId).eq('is_active', true).order('name'),
    supabase.from('inventory_locations').select('id,account_id,branch_id,code,name,is_active').eq('account_id', accountId).eq('is_active', true).order('name'),
    supabase.from('inventory_stock_balances').select('account_id,inventory_item_id,location_id,quantity').eq('account_id', accountId),
  ])

  if (healthResult.error) throw healthResult.error
  if (historyResult.error) throw historyResult.error
  if (peopleResult.error) throw peopleResult.error
  if (itemsResult.error) throw itemsResult.error
  if (locationsResult.error) throw locationsResult.error
  if (balancesResult.error) throw balancesResult.error
  return {
    machines,
    lifecycles: projectCurrentComponentCards(healthResult.data ?? []),
    replacementHistory: historyResult.data ?? [],
    operationalPeople: peopleResult.data ?? [],
    inventoryItems: itemsResult.data ?? [],
    inventoryLocations: locationsResult.data ?? [],
    inventoryBalances: balancesResult.data ?? [],
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

export async function replaceComponentLifecycle({ accountId, machineId, lifecycleId, replacementCounter, replacedAt, reason, condition, includeLearning, performedByPersonId, performedByName, notes, clientRequestId, inventorySource, inventoryItemId, inventoryLocationId, inventoryQuantity, externalInventoryReason }) {
  const { data, error } = await supabase.rpc('replace_machine_component', {
    target_account_id: accountId,
    target_machine_id: machineId,
    target_lifecycle_id: lifecycleId,
    target_replacement_counter: replacementCounter,
    target_replaced_at: replacedAt,
    target_replacement_reason: reason,
    target_condition_at_removal: condition,
    target_include_in_adaptive_learning: includeLearning,
    target_performed_by_user_id: performedByPersonId,
    target_performed_by_name_snapshot: performedByName,
    target_notes: notes?.trim() || null,
    target_client_request_id: clientRequestId,
    target_inventory_source: inventorySource,
    target_inventory_item_id: inventorySource === 'inventory' ? inventoryItemId : null,
    target_inventory_location_id: inventorySource === 'inventory' ? inventoryLocationId : null,
    target_inventory_quantity: inventorySource === 'inventory' ? inventoryQuantity : null,
    target_external_inventory_reason: inventorySource === 'external_untracked' ? externalInventoryReason?.trim() || null : null,
  })

  if (error) throw error
  return data
}
