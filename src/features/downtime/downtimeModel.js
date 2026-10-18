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

export const NOT_CONFIGURED_LABEL = 'Belum dikonfigurasi'
export const UNAVAILABLE_LABEL = 'Tidak tersedia'

export function formatAvailabilityPercent(value) {
  return value == null ? NOT_CONFIGURED_LABEL : `${Number(value).toFixed(1)}%`
}

export function formatProductionDowntimeMinutes(value) {
  return value == null ? NOT_CONFIGURED_LABEL : formatElapsedMinutes(value)
}

function isValidTimestamp(value) {
  return Boolean(value) && !Number.isNaN(new Date(value).getTime())
}

/**
 * GET /downtime/machines/{id}/active answers { data: null } for an idle
 * machine. The generic unwrapData() falls back to the whole payload when
 * data is null, which turned "no active downtime" into a truthy object and
 * rendered an idle machine as "Berhenti". Returns the event, or null when
 * there is none; throws rather than guessing when the body has no event id.
 */
export function normalizeActiveDowntime(payload) {
  if (payload == null) return null
  const event = Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload
  if (event == null) return null
  if (typeof event !== 'object' || !event.id) throw new Error('Respons status downtime tidak valid.')
  return event
}

/** Single source of truth for a machine's status, used by Machine Detail. */
export function describeMachineStatus(event, timezone = 'UTC') {
  if (!event) return { state: 'normal', label: 'Normal' }
  const startValid = isValidTimestamp(event.actual_start_at)
  const elapsedMinutes = startValid ? elapsedMinutesBetween(event.actual_start_at) : null
  return {
    state: 'stopped',
    label: 'Berhenti',
    startText: startValid ? formatDowntimeDateTime(event.actual_start_at, timezone) : UNAVAILABLE_LABEL,
    causeText: event.cause ? causeLabel(event.cause) : UNAVAILABLE_LABEL,
    errorCode: event.error_code_text || null,
    elapsedMinutes,
    durationText: elapsedMinutes == null ? UNAVAILABLE_LABEL : formatElapsedMinutes(elapsedMinutes),
    canComplete: startValid,
  }
}

/**
 * Overview counts from the same active-event list Machine Detail reflects:
 * a machine with an active downtime event is never counted as operational,
 * even if it is missing from the machine list.
 */
export function summarizeMachineHealth({ machines = [], attention = [] }) {
  const stoppedIds = new Set(attention.map((item) => item.machine_id))
  const stoppedByMachine = new Map()
  for (const item of attention) if (!stoppedByMachine.has(item.machine_id)) stoppedByMachine.set(item.machine_id, item)
  return {
    operationalCount: machines.filter((machine) => !stoppedIds.has(machine.id)).length,
    stoppedCount: stoppedIds.size,
    stoppedMachines: [...stoppedByMachine.values()],
  }
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
