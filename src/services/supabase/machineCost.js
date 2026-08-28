import { supabase } from './client.js'

export async function loadMachineCostPeriod({ accountId, machineId, periodStart, periodEnd }) {
  const { data, error } = await supabase
    .rpc('get_machine_economics_period', {
      target_account_id: accountId,
      target_machine_id: machineId,
      target_period_start: periodStart,
      target_period_end: periodEnd,
    })
    .single()

  if (error) throw error
  return data
}

export async function loadMachineOperatingCosts({ accountId, machineId }) {
  const [costs, people] = await Promise.all([
    supabase.from('machine_operating_cost_history').select('*').eq('account_id', accountId).eq('machine_id', machineId).order('created_at', { ascending: false }),
    supabase.from('operational_people').select('id,name,is_active').eq('account_id', accountId).order('name'),
  ])
  if (costs.error) throw costs.error
  if (people.error) throw people.error
  return { costs: costs.data ?? [], people: people.data ?? [] }
}

export async function createMachineOperatingCost({ accountId, machineId, values }) {
  const { data, error } = await supabase.rpc('create_machine_operating_cost', {
    target_account_id: accountId,
    target_machine_id: machineId,
    target_category: values.category,
    target_amount: values.amount,
    target_allocation_method: values.allocationMethod,
    target_description: values.description,
    target_client_request_id: values.clientRequestId,
    target_effective_at: values.allocationMethod === 'one_time' ? new Date(values.effectiveAt).toISOString() : null,
    target_period_start: values.allocationMethod === 'daily_proration_v1' ? values.periodStart : null,
    target_period_end: values.allocationMethod === 'daily_proration_v1' ? values.periodEnd : null,
    target_operational_person_id: values.operationalPersonId || null,
    target_external_reference: values.externalReference || null,
    target_notes: values.notes || null,
    target_source_type: 'manual',
  })
  if (error) throw error
  return data
}

export async function voidMachineOperatingCost({ costId, reason, clientRequestId }) {
  const { data, error } = await supabase.rpc('void_machine_operating_cost', {
    target_cost_id: costId,
    target_reason: reason,
    target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}
