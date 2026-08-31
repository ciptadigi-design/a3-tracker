import { supabase } from './client.js'
import { apiBackend } from '../../lib/api/apiClient.js'
import { laravelReports } from '../../lib/api/reports.js'

function reportParameters({ accountId, branchId, machineId, periodStart, periodEnd }) {
  return {
    target_account_id: accountId,
    target_branch_id: branchId || null,
    target_machine_id: machineId || null,
    target_period_start: periodStart,
    target_period_end: periodEnd,
  }
}

function localDate(value, timezone) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(value))
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}

function inOperationalPeriod(row, timezoneByMachine, start, end, timestampKey) {
  const date = localDate(row[timestampKey], timezoneByMachine.get(row.machine_id) || 'UTC')
  return date >= start && date <= end
}

function operatorActivity(counter) {
  const grouped = new Map()
  for (const row of counter) {
    const operator = row.operator_name_snapshot || 'Unassigned'
    const current = grouped.get(operator) || { operator, counter_entries: 0, recorded_usage: 0, last_counter_entry: null, machines: new Set() }
    current.counter_entries += 1
    current.recorded_usage += Number(row.usage || 0)
    if (!current.last_counter_entry || row.observed_at > current.last_counter_entry) current.last_counter_entry = row.observed_at
    if (row.machine_code) current.machines.add(row.machine_code)
    grouped.set(operator, current)
  }
  return [...grouped.values()].map((row) => ({ ...row, machines: [...row.machines] })).sort((a, b) => b.counter_entries - a.counter_entries || a.operator.localeCompare(b.operator))
}

export async function loadOperationalReport({ accountId, branchId, machineId, periodStart, periodEnd, periodPreset, errorCategory, errorStatus }) {
  if (apiBackend === 'laravel') return laravelReports.load({ accountId, branchId, machineId, periodStart, periodEnd, periodPreset, errorCategory, errorStatus })
  const parameters = reportParameters({ accountId, branchId, machineId, periodStart, periodEnd })
  const errorParameters = { ...parameters, target_category: errorCategory || null, target_status: errorStatus || null }
  const inventoryParameters = { target_account_id: accountId, target_branch_id: branchId || null, target_period_start: periodStart, target_period_end: periodEnd }
  const [overview, performance, economics, dailyClicks, components, errors, inventory, purchases, stock, periodComparison, machineComparison, componentRanking, errorSummary, counterHistory, replacementHistory] = await Promise.all([
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
    supabase.from('machine_counter_history').select('reading_id,machine_id,reading_value,usage,observed_at,shift_code,operator_name_snapshot,status,counter_type_code').eq('account_id', accountId).eq('status', 'effective').eq('counter_type_code', 'total_impressions').order('observed_at', { ascending: false }).order('reading_id', { ascending: false }),
    supabase.from('machine_component_consumption_events').select('replacement_event_id,branch_id,machine_id,component_id,component_code,component_name,occurred_at,inventory_source,consumed_quantity,known_consumption_cost,cost_is_complete').eq('account_id', accountId).order('occurred_at', { ascending: false }).order('replacement_event_id', { ascending: false }),
  ])
  for (const result of [overview, performance, economics, dailyClicks, components, errors, inventory, purchases, stock, periodComparison, machineComparison, componentRanking, errorSummary, counterHistory, replacementHistory]) if (result.error) throw result.error
  const timezoneByMachine = new Map((performance.data ?? []).map((row) => [row.machine_id, row.resolved_timezone]))
  const machineById = new Map((performance.data ?? []).map((row) => [row.machine_id, row]))
  const counters = (counterHistory.data ?? []).filter((row) => (!branchId || machineById.has(row.machine_id)) && (!machineId || row.machine_id === machineId) && inOperationalPeriod(row, timezoneByMachine, periodStart, periodEnd, 'observed_at')).map((row) => ({ ...row, machine_code: machineById.get(row.machine_id)?.machine_code, machine_name: machineById.get(row.machine_id)?.machine_name }))
  const replacements = (replacementHistory.data ?? []).filter((row) => (!branchId || row.branch_id === branchId) && (!machineId || row.machine_id === machineId) && inOperationalPeriod({ ...row, replaced_at: row.occurred_at }, timezoneByMachine, periodStart, periodEnd, 'replaced_at')).map((row) => ({ ...row, replaced_at: row.occurred_at, consumed_cost: row.cost_is_complete ? row.known_consumption_cost : null }))
  return {
    overview: overview.data,
    performance: performance.data ?? [], economics: economics.data ?? [], dailyClicks: dailyClicks.data ?? [],
    components: components.data ?? [], errors: errors.data ?? [], inventory: inventory.data?.[0] ?? null,
    purchases: purchases.data ?? [], stock: stock.data ?? [],
    periodComparison: periodComparison.data ?? [], machineComparison: machineComparison.data ?? [],
    componentRanking: componentRanking.data ?? [], errorSummary: errorSummary.data ?? [],
    counter: counters, operatorActivity: operatorActivity(counters), replacements,
    inventoryConsumption: replacements.filter((row) => row.inventory_source === 'inventory'),
  }
}
