export function normalizeErrorCodeInput(value) {
  const trimmed = String(value ?? '').trim()
  if (!trimmed || !/^[a-z0-9\s-]+$/i.test(trimmed)) return null
  let compact = trimmed.toUpperCase().replace(/[\s-]+/g, '')
  if (!compact.startsWith('C')) compact = `C${compact}`
  if (compact.length < 2) return null
  return `C-${compact.slice(1)}`
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
