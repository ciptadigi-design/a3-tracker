import { supabase } from './client.js'

function reportParameters({ accountId, branchId, machineId, periodStart, periodEnd }) {
  return {
    target_account_id: accountId,
    target_branch_id: branchId || null,
    target_machine_id: machineId || null,
    target_period_start: periodStart,
    target_period_end: periodEnd,
  }
}

export async function loadOperationalReport({ accountId, branchId, machineId, periodStart, periodEnd, errorCategory, errorStatus }) {
  const parameters = reportParameters({ accountId, branchId, machineId, periodStart, periodEnd })
  const [overview, performance, economics, dailyClicks, components, errors, inventory, purchases, stock] = await Promise.all([
    supabase.rpc('get_report_overview', parameters).single(),
    supabase.rpc('get_report_machine_performance', parameters),
    supabase.rpc('get_report_machine_economics', parameters),
    supabase.rpc('get_report_daily_clicks', parameters),
    supabase.rpc('get_report_component_consumption', parameters),
    supabase.rpc('get_report_error_waste', { ...parameters, target_category: errorCategory || null, target_status: errorStatus || null }),
    supabase.rpc('get_report_inventory_activity', { target_account_id: accountId, target_branch_id: branchId || null, target_period_start: periodStart, target_period_end: periodEnd }),
    supabase.rpc('get_report_purchase_lines', { target_account_id: accountId, target_period_start: periodStart, target_period_end: periodEnd }),
    supabase.rpc('get_report_inventory_stock', { target_account_id: accountId, target_branch_id: branchId || null }),
  ])
  for (const result of [overview, performance, economics, dailyClicks, components, errors, inventory, purchases, stock]) if (result.error) throw result.error
  return {
    overview: overview.data,
    performance: performance.data ?? [], economics: economics.data ?? [], dailyClicks: dailyClicks.data ?? [],
    components: components.data ?? [], errors: errors.data ?? [], inventory: inventory.data?.[0] ?? null,
    purchases: purchases.data ?? [], stock: stock.data ?? [],
  }
}
