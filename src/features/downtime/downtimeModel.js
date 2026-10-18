// Categorical labels stay in English, matching maintenanceUtils.js's
// ticketTypeLabels/ticketPriorityLabels convention - operator-facing prose
// is Indonesian, but enum/category labels are English everywhere they're
// shown, including the English-only Owner Overview page.
export const downtimeCauses = [
  { value: 'error_code', label: 'Error Code' },
  { value: 'mechanical', label: 'Mechanical Failure' },
  { value: 'consumable', label: 'Consumable Empty' },
  { value: 'scheduled_maintenance', label: 'Scheduled Maintenance' },
  { value: 'other', label: 'Other' },
]

export function causeLabel(cause) {
  return downtimeCauses.find((item) => item.value === cause)?.label || cause
}

/**
 * Whole-minute elapsed time, floor-rounded like the backend's elapsedMinutes().
 * Returns null (never NaN, never a misleading 0) when startIso/endIso can't be parsed.
 */
export function elapsedMinutesBetween(startIso, endIso = null) {
  if (!startIso) return null
  const start = new Date(startIso).getTime()
  if (Number.isNaN(start)) return null
  const end = endIso ? new Date(endIso).getTime() : Date.now()
  if (Number.isNaN(end)) return null
  return Math.max(0, Math.floor((end - start) / 60000))
}

export function formatElapsedMinutes(totalMinutes) {
  if (totalMinutes == null || Number.isNaN(totalMinutes)) return '—'
  const days = Math.floor(totalMinutes / 1440)
  const hours = Math.floor((totalMinutes % 1440) / 60)
  const minutes = totalMinutes % 60
  const parts = []
  if (days > 0) parts.push(`${days}h`)
  if (hours > 0 || days > 0) parts.push(`${hours}j`)
  parts.push(`${minutes}m`)
  return parts.join(' ')
}

export function formatDowntimeDateTime(value, timezone) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('id-ID', { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

export function formatAvailabilityPercent(value) {
  return value == null ? 'Not configured' : `${Number(value).toFixed(1)}%`
}

export function formatProductionDowntimeMinutes(value) {
  return value == null ? 'Not configured' : formatElapsedMinutes(value)
}

/** Current calendar month, as Y-m-d bounds, in the given IANA timezone. */
export function currentMonthPeriod(timezone = 'UTC') {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit' }).formatToParts(new Date())
  const year = parts.find((part) => part.type === 'year').value
  const month = parts.find((part) => part.type === 'month').value
  const lastDay = new Date(Number(year), Number(month), 0).getDate()
  return { periodStart: `${year}-${month}-01`, periodEnd: `${year}-${month}-${String(lastDay).padStart(2, '0')}` }
}

export function mapDowntimeError(error) {
  if (error?.status === 409) return 'This machine already has an active downtime event, or this event is already closed. Refresh and try again.'
  if (error?.status === 403) return 'Your role is not authorized to perform this downtime action.'
  if (error?.status === 422) return error?.errors ? Object.values(error.errors).flat().join(' ') : 'Check the highlighted fields and try again.'
  return 'The request could not be completed. Please try again.'
}

export function newClientRequestId() {
  return typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`
}
