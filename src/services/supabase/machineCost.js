import { supabase } from './client.js'

export async function loadMachineCostPeriod({ accountId, machineId, periodStart, periodEnd }) {
  const { data, error } = await supabase
    .rpc('get_machine_cost_period', {
      target_account_id: accountId,
      target_machine_id: machineId,
      target_period_start: periodStart,
      target_period_end: periodEnd,
    })
    .single()

  if (error) throw error
  return data
}
