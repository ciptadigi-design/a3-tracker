const totalFormatter = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 })
const unitFormatter = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 2 })

function safeNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) ? number : 0
}

// Aggregate/total monetary values (Component Consumption, Error / Waste, posted
// operating-cost amounts, …): no fractional Rupiah is ever shown. Explicit
// minimumFractionDigits keeps this deterministic across full-ICU and small-ICU
// runtimes, unlike a bare maximumFractionDigits option.
export function formatIdrTotal(value) { return totalFormatter.format(safeNumber(value)) }

// Unit-economics monetary values (Cost / Click and other "/ click" figures):
// up to 2 fractional digits, trailing zeroes dropped naturally because
// minimumFractionDigits is 0.
export function formatIdrUnit(value) { return unitFormatter.format(safeNumber(value)) }
