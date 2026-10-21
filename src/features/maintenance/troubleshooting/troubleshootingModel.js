export function normalizeErrorCodeInput(value) {
  const trimmed = String(value ?? '').trim()
  if (!trimmed || !/^[a-z0-9\s-]+$/i.test(trimmed)) return null
  let compact = trimmed.toUpperCase().replace(/[\s-]+/g, '')
  // An explicit C- or J-code prefix is kept as-is; bare digits keep the
  // legacy default of assuming a C-code.
  if (!/^[CJ]/.test(compact)) compact = `C${compact}`
  if (compact.length < 2) return null
  return `${compact[0]}-${compact.slice(1)}`
}

export function applicabilityLabels(entry) {
  const labels = (entry?.applicabilities || []).map((item) => item.scope_label).filter(Boolean)
  return labels.length > 0 ? labels : [entry?.variant_key?.replaceAll('_', ' / ') || 'General']
}

export function machineApplicability(entry, machine) {
  if (!machine?.machine_model_id) return null
  const scopes = entry?.applicabilities || []
  if (scopes.some((scope) => scope.machine_model_id === machine.machine_model_id)) return 'match'

  const modelScoped = scopes.filter((scope) => scope.machine_model_id)
  return scopes.length > 0 && modelScoped.length === scopes.length ? 'not_applicable' : 'possible'
}

export function prioritizeForMachine(entries, machine) {
  if (!machine?.machine_model_id) return entries
  const rank = { match: 0, possible: 1, not_applicable: 2 }
  return entries.map((entry, index) => ({ entry, index }))
    .sort((left, right) => rank[machineApplicability(left.entry, machine)] - rank[machineApplicability(right.entry, machine)] || left.index - right.index)
    .map(({ entry }) => entry)
}

export function troubleshootingSearchUrl(query = '', machineId = '') {
  const params = new URLSearchParams()
  if (query) params.set('q', query)
  if (machineId) params.set('machine', machineId)
  const search = params.toString()
  return `/maintenance/troubleshooting${search ? `?${search}` : ''}`
}

export function troubleshootingDetailUrl(entryId, machineId = '') {
  const params = new URLSearchParams()
  if (machineId) params.set('machine', machineId)
  const search = params.toString()
  return `/maintenance/troubleshooting/${entryId}${search ? `?${search}` : ''}`
}

export const technicalReferenceLabels = {
  WIRING_DIAGRAM: 'Wiring',
  IO_CHECK: 'I/O',
  DIPSW: 'DIPSW',
  SERVICE_SECTION: 'Service section',
}

export function technicalReferenceLabel(type) {
  return technicalReferenceLabels[type] || String(type || 'Reference').replaceAll('_', ' ')
}

// ---- Combined search (Pengetahuan Resmi + Pengalaman Lapangan) ----------------------------------
// One input feeds two independent sources. Routing is decided here, once:
//   code     C-0213 / J-2113 (any format)  -> both sources
//   numeric  exactly 4 digits              -> both sources; official keeps its legacy "C" default,
//                                             field experiences search C and J separately
//   partial  short fragments with a digit  -> unchanged legacy official search + field text search
//   text     everything else               -> field experiences only (never turned into a fake code)
export const FIELD_MIN_QUERY_LENGTH = 2

export function classifyTroubleshootingQuery(rawValue) {
  const trimmed = String(rawValue ?? '').trim().replace(/\s+/g, ' ')
  if (!trimmed) return { kind: 'empty', query: '', officialQuery: null, fieldQuery: null, codes: [], fieldSkipReason: null }

  const codeChars = /^[a-z0-9\s-]+$/i.test(trimmed)
  const compact = codeChars ? trimmed.toUpperCase().replace(/[\s-]+/g, '') : ''
  let kind = 'text'
  if (/^\d{4}$/.test(compact)) kind = 'numeric'
  else if (/^[CJ][A-Z0-9]{4}$/.test(compact) && /\d/.test(compact.slice(1))) kind = 'code'
  else if (compact.length >= 2 && compact.length <= 5 && /^[CJ]?[A-Z0-9]+$/.test(compact) && /\d/.test(compact)) kind = 'partial'

  // Digits only are ambiguous between C and J: send them unprefixed so the backend returns both families.
  const official = kind === 'text' ? null : /^\d+$/.test(compact) ? compact : normalizeErrorCodeInput(trimmed)
  const codes = kind === 'numeric' ? [`C-${compact}`, `J-${compact}`] : kind === 'code' ? [`${compact[0]}-${compact.slice(1)}`] : []
  // The backend needs 2+ characters and, for text, at least one word of 2+ characters.
  const tooShort = trimmed.length < FIELD_MIN_QUERY_LENGTH || (kind === 'text' && !trimmed.split(' ').some((word) => word.length >= 2))
  return {
    kind,
    query: kind === 'code' ? codes[0] : trimmed,
    officialQuery: official,
    fieldQuery: tooShort ? null : trimmed,
    codes,
    fieldSkipReason: tooShort ? 'too_short' : null,
  }
}

export const fieldTestResultLabels = { normal: 'Normal', monitoring: 'Perlu Monitoring' }
// Existing status-pill palette: green for a normal result, amber for "needs monitoring".
export const fieldTestResultTone = { normal: 'done', monitoring: 'in_progress' }

// ---- Field experience detail ---------------------------------------------------------------------
// The result keeps the search (q, machine) in the URL so "Kembali" restores it. A ticket the viewer may
// open goes to the existing ticket detail; everyone else gets the read-only projection.
export function fieldExperienceUrl(ticketId, { query = '', machineId = '' } = {}) {
  const params = new URLSearchParams()
  if (query) params.set('q', query)
  if (machineId) params.set('machine', machineId)
  const search = params.toString()
  return `/maintenance/troubleshooting/experience/${ticketId}${search ? `?${search}` : ''}`
}

export function fieldExperienceTarget(item, context) {
  return item.can_open === true ? `/maintenance/tickets/${item.id}` : fieldExperienceUrl(item.id, context)
}

// Indonesian messages that say what the user can do, without revealing why the server refused.
export function fieldExperienceFailure(error) {
  const status = error?.status
  if (status === 403) return { kind: 'denied', title: 'Akses tidak tersedia', detail: 'Akun aktif Anda tidak dapat melihat pengalaman lapangan ini.', retry: false }
  if (status === 404 || status === 422) return { kind: 'not_found', title: 'Pengalaman tidak ditemukan', detail: 'Pengalaman ini tidak tersedia atau sudah tidak memenuhi syarat untuk ditampilkan.', retry: false }
  if (/failed to fetch|networkerror|load failed|fetch failed/i.test(error?.message ?? '')) return { kind: 'network', title: 'Tidak dapat terhubung', detail: 'Periksa koneksi Anda lalu coba lagi.', retry: true }
  return { kind: 'server', title: 'Pengalaman belum dapat dimuat', detail: 'Layanan sementara tidak tersedia. Silakan coba lagi.', retry: true }
}
