export const machineCostPeriodPresets = [
  { id: 'today', label: 'Today' },
  { id: 'this_week', label: 'This Week' },
  { id: 'this_month', label: 'This Month' },
  { id: 'last_month', label: 'Last Month' },
  { id: 'this_year', label: 'This Year' },
  { id: 'custom', label: 'Custom Range' },
]

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

export function resolveMachineCostPeriod({ preset, timezone, customStart, customEnd, now }) {
  if (preset === 'custom') return { start: customStart, end: customEnd }
  const today = utcDate(datePartsInTimezone(timezone, now))
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
    && (value.view == null || ['summary', 'details', 'operating'].includes(value.view)))
}
