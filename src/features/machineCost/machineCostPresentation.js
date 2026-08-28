const plural = (count, singular, pluralForm = `${singular}s`) => `${count} ${count === 1 ? singular : pluralForm}`

export function counterEvidencePresentation(summary) {
  switch (summary?.counter_status) {
    case 'COMPLETE': return { label: 'Counter data complete', hint: 'Effective Daily Counter usage is available for this period.' }
    case 'INSUFFICIENT_START': return { label: 'Start boundary missing', hint: 'Start-of-period counter evidence is missing.' }
    case 'INSUFFICIENT_END': return { label: 'End boundary missing', hint: 'End-of-period counter evidence is missing.' }
    case 'NO_DATA': return { label: 'No counter data', hint: 'No effective Total Impressions reading exists in this period.' }
    default: return { label: 'Counter data unavailable', hint: 'Counter data is unavailable.' }
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

export function economicsStatusPresentation(summary) {
  switch (summary?.economics_status) {
    case 'COMPLETE': return ['Standard economics complete', 'Counter, component consumption, and error/waste evidence is complete.']
    case 'PARTIAL': return ['Standard economics partial', 'Standard Machine Cost excludes one or more unpriced consumption or error/waste events.']
    case 'INSUFFICIENT_COUNTER_DATA': return ['Insufficient counter data', 'Standard cost evidence is available, but Standard Cost / Click cannot be calculated.']
    case 'NO_DATA': return ['No Standard economics data', 'No relevant click, component consumption, or error/waste evidence exists for this period.']
    default: return ['Standard economics unavailable', 'Standard economics status is unavailable.']
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

export function primaryCostPerClickPresentation(summary, formatCurrency) {
  const unknownComponents = Number(summary?.unknown_consumption_events ?? 0)
  const unknownWaste = Number(summary?.unknown_error_waste_events ?? 0)
  if (summary?.known_standard_cost_per_click != null) {
    const unknown = unknownComponents + unknownWaste
    return {
      value: unknown > 0 ? `${formatCurrency(summary.known_standard_cost_per_click)} known` : formatCurrency(summary.known_standard_cost_per_click),
      hint: unknownComponents > 0
        ? `Known costs only · ${plural(unknownComponents, 'unknown component event')}`
        : unknownWaste > 0
          ? `Known costs only · ${plural(unknownWaste, 'unpriced Error/Waste event')}`
          : 'Component consumption + Error/Waste ÷ clicks',
    }
  }
  if (Number(summary?.total_clicks) === 0 && summary?.counter_status === 'COMPLETE') {
    return { value: 'Unavailable', hint: 'Clicks are zero, so Cost / Click cannot be calculated.' }
  }
  return { value: 'Unavailable', hint: counterEvidencePresentation(summary).hint }
}

export function summaryStatusPresentation(summary) {
  if (summary?.counter_status !== 'COMPLETE') return ['No counter data', 'neutral']
  if (Number(summary?.unknown_evidence_events ?? 0) > 0) return ['Partial cost data', 'warning']
  if (Number(summary?.total_consumption_events ?? 0) === 0 && Number(summary?.error_waste_events ?? 0) === 0) return ['No consumption', 'neutral']
  return ['Complete', 'success']
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
    hint: 'Inventory acquired during the selected period. It does not become machine cost until consumed.',
  }
}
