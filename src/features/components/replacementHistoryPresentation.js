export function historyForMachine(history, machineId) {
  return (Array.isArray(history) ? history : []).filter((event) => event.machine_id === machineId)
}

export function latestReplacementEvent(rows) {
  return rows.reduce((latest, event) => {
    const at = Date.parse(event?.replaced_at)
    if (Number.isNaN(at)) return latest
    return !latest || at > Date.parse(latest.replaced_at) ? event : latest
  }, null)
}

export function replacementHistoryComponentOptions(rows) {
  const byId = new Map()
  for (const event of rows) {
    if (event.component_id && !byId.has(event.component_id)) byId.set(event.component_id, event.component_name ?? event.component_id)
  }
  return [...byId.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
}

export const LEARNING_STATUS_OPTIONS = [
  { value: 'all', label: 'All statuses' },
  { value: 'eligible', label: 'Learning eligible' },
  { value: 'excluded', label: 'Excluded' },
]

export function filterReplacementHistory(rows, { componentId = 'all', learningStatus = 'all' } = {}) {
  return rows.filter((event) => {
    if (componentId !== 'all' && event.component_id !== componentId) return false
    if (learningStatus === 'eligible' && !event.include_in_adaptive_learning) return false
    if (learningStatus === 'excluded' && event.include_in_adaptive_learning) return false
    return true
  })
}
