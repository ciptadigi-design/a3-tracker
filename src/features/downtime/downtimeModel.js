// Operator-facing workflow: every label is Indonesian (the stored cause values
// stay the stable English enum keys the API already accepts).
export const downtimeCauses = [
  { value: 'paper_jam', label: 'Paper Jam' },
  { value: 'layar_blank', label: 'Layar Blank' },
  { value: 'hasil_cetak_tidak_optimal', label: 'Hasil cetak tidak optimal' },
  { value: 'hasil_kotor', label: 'Hasil Kotor' },
  { value: 'hasil_pudar', label: 'Hasil Pudar' },
  { value: 'hasil_berbayang', label: 'Hasil Berbayang' },
  { value: 'hasil_bergaris', label: 'Hasil Bergaris' },
  { value: 'hasil_tidak_menempel', label: 'Hasil Tidak Menempel' },
  { value: 'mesin_berisik', label: 'Mesin berisik' },
  { value: 'toner_tidak_detect', label: 'Toner tidak detect' },
  { value: 'mesin_mati_total', label: 'Mesin Mati Total' },
  { value: 'layar_berubah_warna', label: 'Layar Berubah Warna' },
  { value: 'error_code', label: 'Error code' },
]

// Causes the form used to offer. Never offered again, but events already stored
// with these keys must keep rendering a readable label in history and reports.
export const legacyDowntimeCauses = [
  { value: 'mechanical', label: 'Kerusakan Mekanis' },
  { value: 'consumable', label: 'Consumable Habis' },
  { value: 'scheduled_maintenance', label: 'Maintenance Terjadwal' },
  { value: 'other', label: 'Lainnya' },
]

export function causeLabel(cause) {
  return [...downtimeCauses, ...legacyDowntimeCauses].find((item) => item.value === cause)?.label || cause
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
  if (error?.status === 409) return 'Mesin ini sudah memiliki downtime aktif, atau downtime ini sudah ditutup. Muat ulang lalu coba lagi.'
  if (error?.status === 403) return 'Peran Anda tidak diizinkan melakukan tindakan downtime ini.'
  if (error?.status === 422) return error?.errors && Object.keys(error.errors).length ? Object.values(error.errors).flat().join(' ') : 'Periksa isian formulir lalu coba lagi.'
  return 'Permintaan tidak dapat diselesaikan. Silakan coba lagi.'
}

export function newClientRequestId() {
  return typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

/** Month input value (YYYY-MM) -> inclusive Y-m-d bounds. */
export function monthToPeriod(monthValue) {
  const match = /^(\d{4})-(\d{2})$/.exec(monthValue || '')
  if (!match) return null
  const [, year, month] = match
  const lastDay = new Date(Number(year), Number(month), 0).getDate()
  return { periodStart: `${year}-${month}-01`, periodEnd: `${year}-${month}-${String(lastDay).padStart(2, '0')}` }
}

export function currentMonthValue(timezone = 'UTC') {
  const { periodStart } = currentMonthPeriod(timezone)
  return periodStart.slice(0, 7)
}

/** Status of a downtime history row: an event without an end is still active. */
export function describeEventStatus(event) {
  return event?.actual_end_at ? { state: 'closed', label: 'Selesai' } : { state: 'active', label: 'Berhenti' }
}

/** Elapsed/production downtime for a history row, preferring the server's numbers. */
export function describeEventDurations(event) {
  const elapsed = Number.isFinite(event?.elapsed_minutes) ? event.elapsed_minutes : elapsedMinutesBetween(event?.actual_start_at, event?.actual_end_at)
  return {
    elapsedText: elapsed == null ? UNAVAILABLE_LABEL : formatElapsedMinutes(elapsed),
    productionText: event?.production_downtime_minutes == null ? NOT_CONFIGURED_LABEL : formatElapsedMinutes(event.production_downtime_minutes),
  }
}

/** Active-downtime card data for the Kondisi Mesin workspace (Overview attention items and active events share it). */
export function describeActiveEntry(item, timezone = 'UTC') {
  const status = describeMachineStatus(item, timezone)
  const note = item?.latest_note?.note || null
  return { ...status, latestNote: note, noteAt: item?.latest_note?.occurred_at ? formatDowntimeDateTime(item.latest_note.occurred_at, timezone) : null }
}

/** Deep link from Machine Detail into the Maintenance workspace with the machine preselected. */
export function machineConditionPath(machineId, action = 'manage') {
  return `/maintenance/machine-condition?machine=${encodeURIComponent(machineId)}&action=${action === 'report' ? 'report' : 'manage'}`
}

/** Machine already has an active event: block a second report client-side (the API also returns 409). */
export function hasActiveDowntime(activeEntries, machineId) {
  return (activeEntries || []).some((item) => item.machine_id === machineId)
}

// --- Operational schedule (Settings > Jadwal Operasional) ---------------------

export const WEEK_DAYS = [
  { day: 1, label: 'Senin' }, { day: 2, label: 'Selasa' }, { day: 3, label: 'Rabu' }, { day: 4, label: 'Kamis' },
  { day: 5, label: 'Jumat' }, { day: 6, label: 'Sabtu' }, { day: 0, label: 'Minggu' },
]

export const exceptionTypes = [
  { value: 'holiday', label: 'Libur Produksi', operating: false },
  { value: 'closed', label: 'Tutup', operating: false },
  { value: 'overtime', label: 'Lembur', operating: true },
  { value: 'extra_shift', label: 'Shift Tambahan', operating: true },
  { value: 'other', label: 'Perubahan Jam Operasional', operating: true },
]

export const exceptionTypeLabel = (type) => exceptionTypes.find((item) => item.value === type)?.label || type

const trimTime = (value) => (typeof value === 'string' ? value.slice(0, 5) : '')

/** A new, unconfigured week: every day non-operating and EMPTY times - no invented operating hours. */
export function emptyWeek() {
  return WEEK_DAYS.map(({ day }) => ({ day_of_week: day, is_operating: false, start_time: '', end_time: '', crosses_midnight: false }))
}

/** Saved rows -> editable week (always 7 rows, Monday first). */
export function weekFromRows(rows) {
  const byDay = new Map((rows || []).map((row) => [Number(row.day_of_week), row]))
  return WEEK_DAYS.map(({ day }) => {
    const row = byDay.get(day)
    return row ? { day_of_week: day, is_operating: Boolean(row.is_operating), start_time: trimTime(row.start_time), end_time: trimTime(row.end_time), crosses_midnight: Boolean(row.crosses_midnight) } : { day_of_week: day, is_operating: false, start_time: '', end_time: '', crosses_midnight: false }
  })
}

/** Returns an Indonesian error string, or null when the week can be saved. */
export function validateWeek(week) {
  if (!Array.isArray(week) || week.length !== 7) return 'Jadwal harus mencakup tujuh hari.'
  for (const row of week) {
    if (!row.is_operating) continue
    const label = WEEK_DAYS.find((item) => item.day === row.day_of_week)?.label
    if (!row.start_time || !row.end_time) return `${label}: isi jam mulai dan jam selesai, atau tandai hari non-operasional.`
    if (row.crosses_midnight ? row.start_time === row.end_time : row.end_time <= row.start_time) return `${label}: jam selesai harus setelah jam mulai (atau tandai melewati tengah malam).`
  }
  if (!week.some((row) => row.is_operating)) return 'Tandai minimal satu hari operasional.'
  return null
}

export function weekToPayload(week) {
  return week.map((row) => ({
    day_of_week: row.day_of_week,
    is_operating: row.is_operating,
    start_time: row.is_operating ? row.start_time : null,
    end_time: row.is_operating ? row.end_time : null,
    crosses_midnight: row.is_operating ? Boolean(row.crosses_midnight) : false,
  }))
}

export function validateException(values) {
  if (!values.exception_date) return 'Pilih tanggal pengecualian.'
  const type = exceptionTypes.find((item) => item.value === values.type)
  if (!type) return 'Pilih jenis pengecualian.'
  if (type.operating) {
    if (!values.override_start_time || !values.override_end_time) return 'Isi jam mulai dan jam selesai untuk hari yang tetap beroperasi.'
    if (values.crosses_midnight ? values.override_start_time === values.override_end_time : values.override_end_time <= values.override_start_time) return 'Jam selesai harus setelah jam mulai (atau tandai melewati tengah malam).'
  }
  return null
}

/** API payload for an exception. machine_id stays null for a branch-wide exception - never broadened silently. */
export function exceptionToPayload(values) {
  const type = exceptionTypes.find((item) => item.value === values.type)
  return {
    machine_id: values.machine_id || null,
    exception_date: values.exception_date,
    type: values.type,
    is_operating: Boolean(type?.operating),
    override_start_time: type?.operating ? values.override_start_time : null,
    override_end_time: type?.operating ? values.override_end_time : null,
    crosses_midnight: type?.operating ? Boolean(values.crosses_midnight) : false,
    note: values.note?.trim() || null,
  }
}

/**
 * Branch access for Settings > Jadwal Operasional. `branches` is the server-scoped
 * Settings list (already limited to the signed-in account's assignments); this only
 * decides presentation: none -> empty state, single -> locked selector, multiple -> free choice.
 */
export function resolveScheduleBranches(branches) {
  const available = (Array.isArray(branches) ? branches : []).filter((item) => item && item.is_active !== false)
  const mode = available.length === 0 ? 'none' : available.length === 1 ? 'single' : 'multiple'
  return { branches: available, mode, defaultBranchId: available[0]?.id ?? '' }
}

/** A branch selection is only valid while it is still one of the authorized branches. */
export function effectiveScheduleBranchId(branches, selectedId) {
  return branches.some((item) => item.id === selectedId) ? selectedId : (branches[0]?.id ?? '')
}

export const NO_RECOVERY_LABEL = 'Belum ada pemulihan'

/**
 * Presentation-only mapping of the server's owner KPI payload (GET /downtime/overview).
 * All numbers are computed by the backend; this never derives a KPI. Missing schedule
 * data renders "Belum dikonfigurasi" instead of a percentage, and an average recovery
 * only exists for completed incidents.
 */
export function describeOwnerKpis(data) {
  const status = data?.schedule_status ?? 'not_configured'
  return {
    scheduleStatus: status,
    scheduleMissing: status === 'not_configured',
    schedulePartial: status === 'partial',
    availability: formatAvailabilityPercent(data?.availability_percent),
    productionDowntime: formatProductionDowntimeMinutes(data?.production_downtime_minutes),
    eventCount: String(data?.event_count ?? 0),
    ongoingCount: data?.ongoing_event_count ?? 0,
    completedCount: data?.completed_event_count ?? 0,
    recovery: data?.average_recovery_minutes == null ? NO_RECOVERY_LABEL : formatElapsedMinutes(data.average_recovery_minutes),
  }
}
