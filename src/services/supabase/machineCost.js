import { supabase } from './client.js'

export async function loadMachineCostPeriod({ accountId, machineId, periodStart, periodEnd }) {
  const parameters = {
      target_account_id: accountId,
      target_machine_id: machineId,
      target_period_start: periodStart,
      target_period_end: periodEnd,
  }
  const [summary, trend] = await Promise.all([
    supabase.rpc('get_machine_economics_period', parameters).single(),
    supabase.rpc('get_machine_cost_daily_trend', parameters),
  ])

  if (summary.error) throw summary.error
  if (trend.error) throw trend.error
  return { ...summary.data, daily_trend: trend.data ?? [] }
}

export async function loadMachineOperatingCosts({ accountId, machineId, branchId }) {
  const [costs, people] = await Promise.all([
    supabase.from('machine_operating_cost_history').select('*').eq('account_id', accountId).eq('machine_id', machineId).order('created_at', { ascending: false }),
    supabase.from('operational_people').select('id,name,is_active,operational_person_branches!inner(branch_id,is_active)').eq('account_id', accountId).eq('operational_person_branches.branch_id', branchId).eq('operational_person_branches.is_active', true).order('name'),
  ])
  if (costs.error) throw costs.error
  if (people.error) throw people.error
  return { costs: costs.data ?? [], people: people.data ?? [] }
}

export async function loadMachineSellingPrices({ accountId, machineId }) {
  const { data, error } = await supabase.from('machine_selling_price_history').select('*')
    .eq('account_id', accountId).eq('machine_id', machineId)
    .order('effective_from', { ascending: false }).order('created_at', { ascending: false })
  if (error) throw error
  return data ?? []
}

export async function createMachineSellingPrice({ accountId, machineId, values }) {
  const { data, error } = await supabase.rpc('create_machine_selling_price', {
    target_account_id: accountId,
    target_machine_id: machineId,
    target_price_per_click: values.pricePerClick,
    target_effective_from: values.effectiveFrom,
    target_notes: values.notes || null,
    target_client_request_id: values.clientRequestId,
  })
  if (error) throw error
  return data
}

export async function voidMachineSellingPrice({ priceId, reason, clientRequestId }) {
  const { data, error } = await supabase.rpc('void_machine_selling_price', {
    target_price_id: priceId,
    target_reason: reason,
    target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
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
