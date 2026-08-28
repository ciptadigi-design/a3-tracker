const plural = (count, singular, pluralForm = `${singular}s`) => `${count} ${count === 1 ? singular : pluralForm}`

export function counterEvidencePresentation(summary) {
  switch (summary?.counter_status) {
    case 'COMPLETE': return { label: 'Counter evidence complete', hint: 'Start and end boundary readings are available.' }
    case 'INSUFFICIENT_START': return { label: 'Start boundary missing', hint: 'Start-of-period counter evidence is missing.' }
    case 'INSUFFICIENT_END': return { label: 'End boundary missing', hint: 'End-of-period counter evidence is missing.' }
    case 'NO_DATA': return { label: 'No counter data', hint: 'No start or end boundary reading exists for this period.' }
    default: return { label: 'Counter evidence unavailable', hint: 'Boundary evidence is unavailable.' }
  }
}

export function costStatusPresentation(summary) {
  switch (summary?.cost_status) {
    case 'COMPLETE': return ['Complete', 'All consumption events in this period have known cost.']
    case 'PARTIAL': return ['Partial cost evidence', 'Some consumption events do not have known acquisition cost.']
    case 'NO_CONSUMPTION': return ['No consumption', 'No component consumption occurred in this period.']
    case 'INSUFFICIENT_COUNTER_DATA': return ['Counter evidence incomplete', summary.total_consumption_events > 0
      ? 'Consumption is recorded, but cost/click cannot be calculated for the selected period.'
      : 'Full-period click volume cannot be calculated because a counter boundary is missing.']
    case 'NO_DATA': return ['No data', 'No relevant machine data exists for this period.']
    default: return ['No data', 'No relevant machine data exists for this period.']
  }
}

export function knownConsumptionPresentation(summary, formatCurrency) {
  const knownCost = Number(summary?.known_consumption_cost ?? 0)
  const knownEvents = Number(summary?.known_consumption_events ?? 0)
  const unknownEvents = Number(summary?.unknown_consumption_events ?? 0)
  if (unknownEvents > 0 && knownCost === 0) return {
    value: '—',
    hint: `${plural(unknownEvents, 'consumption event')} ${unknownEvents === 1 ? 'has' : 'have'} unknown acquisition cost.`,
  }
  if (unknownEvents > 0) return { value: `${formatCurrency(knownCost)} known`, hint: `${knownEvents} known · ${unknownEvents} unknown` }
  return { value: formatCurrency(knownCost), hint: summary?.total_consumption_events ? `${plural(summary.total_consumption_events, 'replacement')}` : 'No component consumption occurred.' }
}

export function costPerClickPresentation(summary, formatCurrency) {
  if (summary?.known_component_cost_per_click != null) return { value: formatCurrency(summary.known_component_cost_per_click), hint: 'Known consumption cost ÷ period clicks.' }
  if (Number(summary?.total_clicks) === 0 && summary?.counter_status === 'COMPLETE') return { value: 'Unavailable', hint: 'Click volume is genuinely zero, so division is unavailable.' }
  return { value: 'Unavailable', hint: counterEvidencePresentation(summary).hint }
}

export function componentCompositionPresentation(row, formatCurrency) {
  const total = Number(row.total_events ?? 0)
  const unknown = Number(row.unknown_cost_events ?? 0)
  const known = Math.max(0, total - unknown)
  const knownCost = Number(row.known_consumption_cost ?? 0)
  if (unknown > 0 && knownCost === 0) return { value: 'Cost basis unknown', meta: plural(total, 'replacement'), showPercent: false }
  if (unknown > 0) return { value: `${formatCurrency(knownCost)} known`, meta: `${known} known · ${unknown} unknown`, showPercent: true }
  return { value: formatCurrency(knownCost), meta: plural(total, 'replacement'), showPercent: true }
}

export function inventoryContextPresentation(summary) {
  const unknown = Number(summary?.ending_unknown_inventory_quantity_context ?? 0)
  return {
    label: unknown > 0 ? 'Known Inventory Cost Basis · Branch' : 'Ending Inventory Cost Basis · Branch',
    hint: 'Known acquisition cost still remaining in tracked stock at period end.',
    hasUnknownQuantity: unknown > 0,
  }
}

export function purchaseContextPresentation() {
  return {
    label: 'Purchase Cost · Account',
    hint: 'Value of inventory purchased during this period. It does not enter machine cost/click until the stock is consumed.',
  }
}
