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

export async function loadOperationalReport({ accountId, branchId, machineId, periodStart, periodEnd, periodPreset, errorCategory, errorStatus }) {
  const parameters = reportParameters({ accountId, branchId, machineId, periodStart, periodEnd })
  const errorParameters = { ...parameters, target_category: errorCategory || null, target_status: errorStatus || null }
  const inventoryParameters = { target_account_id: accountId, target_branch_id: branchId || null, target_period_start: periodStart, target_period_end: periodEnd }
  const [overview, performance, economics, dailyClicks, components, errors, inventory, purchases, stock, periodComparison, machineComparison, componentRanking, errorSummary] = await Promise.all([
    supabase.rpc('get_report_overview', parameters).single(),
    supabase.rpc('get_report_machine_performance', parameters),
    supabase.rpc('get_report_machine_economics', parameters),
    supabase.rpc('get_report_daily_clicks', parameters),
    supabase.rpc('get_report_component_consumption', parameters),
    supabase.rpc('get_report_error_waste', errorParameters),
    supabase.rpc('get_report_inventory_analytics', inventoryParameters),
    supabase.rpc('get_report_purchase_lines', { target_account_id: accountId, target_branch_id: branchId, target_period_start: periodStart, target_period_end: periodEnd }),
    supabase.rpc('get_report_inventory_stock', { target_account_id: accountId, target_branch_id: branchId || null }),
    supabase.rpc('get_report_period_comparison', { ...parameters, target_period_preset: periodPreset }),
    supabase.rpc('get_report_machine_comparison', parameters),
    supabase.rpc('get_report_component_ranking', parameters),
    supabase.rpc('get_report_error_summary', errorParameters),
  ])
  for (const result of [overview, performance, economics, dailyClicks, components, errors, inventory, purchases, stock, periodComparison, machineComparison, componentRanking, errorSummary]) if (result.error) throw result.error
  return {
    overview: overview.data,
    performance: performance.data ?? [], economics: economics.data ?? [], dailyClicks: dailyClicks.data ?? [],
    components: components.data ?? [], errors: errors.data ?? [], inventory: inventory.data?.[0] ?? null,
    purchases: purchases.data ?? [], stock: stock.data ?? [],
    periodComparison: periodComparison.data ?? [], machineComparison: machineComparison.data ?? [],
    componentRanking: componentRanking.data ?? [], errorSummary: errorSummary.data ?? [],
  }
}
