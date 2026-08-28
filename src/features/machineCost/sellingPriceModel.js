export function validSellingPriceDraft(value) {
  return Boolean(value && typeof value === 'object'
    && typeof value.pricePerClick === 'string'
    && typeof value.effectiveFrom === 'string'
    && typeof value.notes === 'string'
    && typeof value.clientRequestId === 'string')
}

export function sellingPriceValidation(value) {
  if (!/^\d+(\.\d{1,4})?$/.test(value.pricePerClick) || Number(value.pricePerClick) <= 0) {
    return 'Selling price must be greater than zero with at most four decimal places.'
  }
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value.effectiveFrom)) return 'Effective date and time is required.'
  if (value.notes.trim().length > 1000) return 'Notes cannot exceed 1000 characters.'
  return null
}

function zonedParts(date, timeZone) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(date)
  return Object.fromEntries(parts.filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]))
}

export function zonedLocalDateTimeToISOString(value, timeZone) {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value)
  if (!match) throw new Error('Effective date and time is invalid.')
  const [, year, month, day, hour, minute] = match
  const nominal = Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute))
  let instant = nominal
  for (let index = 0; index < 3; index += 1) {
    const parts = zonedParts(new Date(instant), timeZone)
    const represented = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second))
    instant += nominal - represented
  }
  const result = new Date(instant)
  const roundTrip = zonedParts(result, timeZone)
  const normalized = `${roundTrip.year}-${roundTrip.month}-${roundTrip.day}T${roundTrip.hour}:${roundTrip.minute}`
  if (normalized !== value) throw new Error(`That local time is not valid in ${timeZone}.`)
  return result.toISOString()
}

export function localDateTimeInZone(timeZone, date = new Date()) {
  const parts = zonedParts(date, timeZone)
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

export function sellingPriceCardPresentation(summary, formatCurrency) {
  const priceCount = Number(summary?.period_price_count ?? 0)
  const periodPrice = summary?.period_end_selling_price_per_click
  if (!summary?.current_selling_price_per_click && periodPrice == null) {
    return { value: 'Not configured', hint: 'Selling price not configured', state: 'missing' }
  }
  if (priceCount > 1) return {
    value: 'Multiple prices',
    hint: `${priceCount} prices applied to recorded clicks in this period.`,
    state: 'multiple',
  }
  return {
    value: formatCurrency(periodPrice ?? summary.current_selling_price_per_click),
    hint: priceCount === 1 ? 'Effective price for recorded clicks in this period.' : 'Configured price; no priced clicks in this period.',
    state: 'configured',
  }
}

export function revenuePresentation(summary, formatCurrency, formatNumber) {
  switch (summary?.revenue_status) {
    case 'COMPLETE': return { value: formatCurrency(summary.estimated_revenue), hint: `${formatNumber(summary.priced_clicks)} priced clicks · complete coverage` }
    case 'PARTIAL': return { value: formatCurrency(summary.estimated_revenue), hint: `Priced portion only · ${formatNumber(summary.unpriced_clicks)} clicks unpriced` }
    case 'NO_CLICKS': return { value: formatCurrency(0), hint: 'No recorded click activity in this period.' }
    case 'NO_PRICE': return { value: 'Unavailable', hint: 'Selling price not configured for recorded clicks.' }
    default: return { value: 'Unavailable', hint: 'Revenue evidence is unavailable.' }
  }
}

export function contributionPresentation(summary, formatCurrency) {
  if (summary?.estimated_standard_contribution == null) {
    const hint = summary?.revenue_status === 'PARTIAL'
      ? 'Unavailable until every recorded click has price evidence.'
      : summary?.revenue_status === 'NO_CLICKS'
        ? 'No clicks, so contribution is unavailable.'
        : 'Selling price not configured.'
    return { value: 'Unavailable', hint, margin: null }
  }
  const partial = summary.standard_contribution_status === 'PARTIAL_COST'
  return {
    value: formatCurrency(summary.estimated_standard_contribution),
    hint: partial
      ? 'Based on available cost evidence. Contribution is not net profit.'
      : 'Revenue after tracked machine consumption and machine-attributed Error/Waste.',
    margin: summary.standard_contribution_margin_percent == null ? null : Number(summary.standard_contribution_margin_percent),
  }
}

export function contributionPerClickPresentation(summary, formatCurrency) {
  if (summary?.standard_contribution_per_click == null) return {
    value: 'Unavailable',
    hint: summary?.revenue_status === 'PARTIAL' ? 'Partial price coverage cannot produce a comparable period value.' : 'Complete priced clicks are required.',
  }
  return {
    value: formatCurrency(summary.standard_contribution_per_click),
    hint: summary.standard_contribution_status === 'PARTIAL_COST' ? 'Based on available cost evidence.' : 'Standard Contribution ÷ total period clicks.',
  }
}
