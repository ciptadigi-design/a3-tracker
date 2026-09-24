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

export const technicalReferenceLabels = {
  WIRING_DIAGRAM: 'Wiring',
  IO_CHECK: 'I/O',
  DIPSW: 'DIPSW',
  SERVICE_SECTION: 'Service section',
}

export function technicalReferenceLabel(type) {
  return technicalReferenceLabels[type] || String(type || 'Reference').replaceAll('_', ' ')
}
