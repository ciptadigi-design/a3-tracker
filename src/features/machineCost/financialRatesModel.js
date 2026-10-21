import { formatIdrTotal } from './currencyFormat.js'

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

/** Input shows Rupiah (e.g. "Rp 50.000"); state keeps plain digits. Blank stays blank (not configured), never 0. */
export function formatRateInput(digits) {
  return digits === '' || digits == null ? '' : formatIdrTotal(Number(digits))
}

export function parseRateInput(text) {
  return String(text ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, 13)
}
