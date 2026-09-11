const totalFormatter = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 })

function safeNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) ? number : 0
}

// The single canonical formatter for every user-facing IDR value in the app,
// aggregate totals and per-click/per-unit economics (Cost / Click, Selling Price /
// Click, Contribution / Click, ...) alike. M2.17.5 changed the product decision that
// previously kept per-click figures fractional (via a since-removed formatIdrUnit) -
// all monetary Rupiah is now whole-Rupiah at the display boundary. This never
// changes the underlying calculation: callers keep full numeric precision until the
// moment they hand a value to this formatter for rendering. Explicit
// minimumFractionDigits keeps this deterministic across full-ICU and small-ICU
// runtimes, unlike a bare maximumFractionDigits option.
export function formatIdrTotal(value) { return totalFormatter.format(safeNumber(value)) }
