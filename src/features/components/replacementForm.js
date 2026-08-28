export function normalizeCounterInput(value) {
  const normalized = String(value).replace(/[\s,]/g, '')
  return /^\d*$/.test(normalized) ? normalized : null
}

export function formatCounterInput(value) {
  const normalized = normalizeCounterInput(value)
  if (normalized == null || normalized === '') return ''
  return Number(normalized).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

export function resolveReplacementPic(people, selectedId) {
  if (selectedId === 'manual') return { mode: 'manual', person: null, stale: false }
  const person = people.find((candidate) => candidate.id === selectedId) ?? null
  if (!selectedId) return { mode: 'unselected', person: null, stale: false }
  if (!person?.is_active) return { mode: 'stale', person, stale: true }
  return { mode: 'operational', person, stale: false }
}
