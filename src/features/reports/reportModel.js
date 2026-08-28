import { machineCostPeriodPresets } from '../machineCost/machineCostPeriods.js'

export const reportTabs = [
  { id: 'overview', label: 'Overview' },
  { id: 'performance', label: 'Machine Performance' },
  { id: 'economics', label: 'Machine Economics' },
  { id: 'components', label: 'Component Consumption' },
  { id: 'errors', label: 'Error / Waste' },
  { id: 'inventory', label: 'Inventory / Purchasing' },
]

export function validReportFilters(value) {
  return Boolean(value && reportTabs.some((tab) => tab.id === value.tab)
    && machineCostPeriodPresets.some((preset) => preset.id === value.preset)
    && typeof value.branchId === 'string' && typeof value.machineId === 'string'
    && typeof value.customStart === 'string' && typeof value.customEnd === 'string'
    && typeof value.errorCategory === 'string' && typeof value.errorStatus === 'string')
}

export function reportStatus(status) {
  switch (status) {
    case 'COMPLETE': return ['Complete', 'success']
    case 'PARTIAL_COST': return ['Partial cost evidence', 'warning']
    case 'PARTIAL_PRICE': return ['Partial price evidence', 'warning']
    case 'NO_COUNTER_DATA': return ['Counter data incomplete', 'neutral']
    default: return ['No data', 'neutral']
  }
}

export function priceEvidence(row, formatCurrency) {
  if (row.revenue_status === 'NO_PRICE') return 'Not configured'
  if (Number(row.period_price_count) > 1) return `${row.period_price_count} historical prices`
  const price = row.period_end_selling_price_per_click ?? row.current_selling_price_per_click
  return price == null ? 'Not configured' : formatCurrency(price)
}
