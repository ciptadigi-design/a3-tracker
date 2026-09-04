const CANONICAL_DATE_KEY = /^\d{4}-\d{2}-\d{2}$/

/**
 * Boundary guard: every caller of the machine-cost endpoint must supply a
 * canonical YYYY-MM-DD period. Throwing here turns a missing/undefined
 * period into an immediate, catchable client-side error instead of
 * silently serializing to the literal string "undefined" and letting
 * Laravel reject it with an opaque date_format validation error.
 */
export function machineCostQuery(periodStart, periodEnd) {
  if (!CANONICAL_DATE_KEY.test(periodStart) || !CANONICAL_DATE_KEY.test(periodEnd)) {
    throw new Error(`Machine cost period must be canonical YYYY-MM-DD strings, received period_start=${periodStart} period_end=${periodEnd}`)
  }

  return `period_start=${encodeURIComponent(periodStart)}&period_end=${encodeURIComponent(periodEnd)}`
}
