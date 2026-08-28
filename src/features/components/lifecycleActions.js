export function lifecycleActionFor({ lifecycleStatus, canInitialize, canReplace }) {
  if (lifecycleStatus === 'unknown') return canInitialize ? 'initialize' : null
  if (lifecycleStatus === 'active') return canReplace ? 'replace' : null
  return null
}

export function resolveReplacementInventorySource(value) {
  return value === 'external_untracked' ? 'external_untracked' : 'inventory'
}
