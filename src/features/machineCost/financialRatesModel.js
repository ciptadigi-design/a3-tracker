const toText = (value) => (value == null ? '' : String(value))

export function ratesToForm(rates) {
  return { operatingCostPerHour: toText(rates?.operating_cost_per_hour), estimatedRevenuePerHour: toText(rates?.estimated_revenue_per_hour) }
}

function parseRate(text) {
  const trimmed = String(text ?? '').trim()
  if (trimmed === '') return { ok: true, value: null }
  return /^\d{1,13}$/.test(trimmed) ? { ok: true, value: Number(trimmed) } : { ok: false }
}

/** Whole-Rupiah payload (blank -> null = not configured), or null when a field is not a non-negative integer. */
export function ratesFromForm(form) {
  const cost = parseRate(form?.operatingCostPerHour)
  const revenue = parseRate(form?.estimatedRevenuePerHour)
  return cost.ok && revenue.ok ? { operating_cost_per_hour: cost.value, estimated_revenue_per_hour: revenue.value } : null
}
