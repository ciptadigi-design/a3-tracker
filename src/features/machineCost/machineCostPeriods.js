export const machineCostPeriodPresets = [
  { id: 'today', label: 'Today' },
  { id: 'this_week', label: 'This Week' },
  { id: 'this_month', label: 'This Month' },
  { id: 'last_month', label: 'Last Month' },
  { id: 'this_year', label: 'This Year' },
  { id: 'custom', label: 'Custom Range' },
]

export const CANONICAL_PERIOD_TIMEZONE = 'Asia/Jakarta'

const CANONICAL_DATE_KEY = /^\d{4}-\d{2}-\d{2}$/

function datePartsInTimezone(timezone, now = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now)
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return { year: Number(values.year), month: Number(values.month), day: Number(values.day) }
}

function utcDate({ year, month, day }) { return new Date(Date.UTC(year, month - 1, day)) }
function dateKey(date) { return date.toISOString().slice(0, 10) }
function addDays(date, days) { const result = new Date(date); result.setUTCDate(result.getUTCDate() + days); return result }

/**
 * Boundary guard: the backend contract is a strict `YYYY-MM-DD` date-only value
 * (Laravel `date_format:Y-m-d`). Any other representation reaching the API —
 * an ISO timestamp, a locale-formatted string, a stale persisted draft from an
 * older client — must be rejected here rather than forwarded and rejected by
 * the server with a raw validation error.
 */
export function normalizePeriodDateKey(value) {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  if (CANONICAL_DATE_KEY.test(trimmed)) {
    const [year, month, day] = trimmed.split('-').map(Number)
    const roundTrip = utcDate({ year, month, day })
    return dateKey(roundTrip) === trimmed ? trimmed : null
  }
  return null
}

export function resolveMachineCostPeriod({ preset, timezone, customStart, customEnd, now }) {
  if (preset === 'custom') return { start: normalizePeriodDateKey(customStart), end: normalizePeriodDateKey(customEnd) }
  const today = utcDate(datePartsInTimezone(timezone || CANONICAL_PERIOD_TIMEZONE, now))
  if (preset === 'today') return { start: dateKey(today), end: dateKey(today) }
  if (preset === 'this_week') {
    const mondayOffset = (today.getUTCDay() + 6) % 7
    return { start: dateKey(addDays(today, -mondayOffset)), end: dateKey(today) }
  }
  if (preset === 'last_month') {
    const start = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() - 1, 1))
    const end = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 0))
    return { start: dateKey(start), end: dateKey(end) }
  }
  if (preset === 'this_year') return { start: `${today.getUTCFullYear()}-01-01`, end: dateKey(today) }
  const start = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 1))
  return { start: dateKey(start), end: dateKey(today) }
}

export function validMachineCostFilters(value) {
  return Boolean(value
    && machineCostPeriodPresets.some((preset) => preset.id === value.preset)
    && (value.machineId === null || typeof value.machineId === 'string')
    && typeof value.customStart === 'string'
    && typeof value.customEnd === 'string'
    && (value.view == null || ['summary', 'operating'].includes(value.view)))
}
