import { machineCostPeriodPresets } from '../machineCost/machineCostPeriods.js'

export const reportTabs = [
  { id: 'overview', label: 'Overview' },
  { id: 'counter', label: 'Counter / Usage' },
  { id: 'machine-cost', label: 'Machine Cost' },
  { id: 'replacements', label: 'Component / Replacement' },
  { id: 'incidents', label: 'Incident / Error / Waste' },
  { id: 'operators', label: 'Operator Activity' },
  { id: 'consumption', label: 'Inventory Consumption' },
]

export function validReportFilters(value) {
  return Boolean(value && reportTabs.some((tab) => tab.id === value.tab)
    && machineCostPeriodPresets.some((preset) => preset.id === value.preset)
    && (value.branchId == null || typeof value.branchId === 'string') && typeof value.machineId === 'string'
    && typeof value.customStart === 'string' && typeof value.customEnd === 'string'
    && typeof value.errorCategory === 'string' && typeof value.errorStatus === 'string'
    && (value.comparisonMetric == null || ['estimated_standard_contribution','total_clicks','standard_cost_per_click','error_waste_cost'].includes(value.comparisonMetric))
    && (value.componentSort == null || ['cost_rank','replacement_rank','unknown_evidence_rank'].includes(value.componentSort)))
}

export function removePersistedReportBranch(value) {
  if (!value || !Object.hasOwn(value, 'branchId')) return value
  const { branchId: _obsoleteBranchId, ...globalBranchFilters } = value
  return globalBranchFilters
}

export function deltaPresentation(row) {
  if (!row) return { label: 'No comparison', tone: 'neutral' }
  if (row.delta_status === 'NEW') return { label: 'New vs previous', tone: 'positive' }
  if (row.delta_status === 'PARTIAL') return { label: 'Partial evidence', tone: 'warning' }
  if (row.delta_status !== 'COMPLETE' || row.delta_percent == null) return { label: 'No comparison', tone: 'neutral' }
  const value = Number(row.delta_percent)
  return { label: `${value > 0 ? '+' : ''}${value.toLocaleString('en-US', { maximumFractionDigits: 1 })}% vs previous`, tone: value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral' }
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
