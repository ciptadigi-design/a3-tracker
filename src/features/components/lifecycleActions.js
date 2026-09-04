export function lifecycleActionFor({ lifecycleStatus, canInitialize, canReplace }) {
  // 'baseline_known' has a real installed_counter but no factual installation date — it is still
  // eligible for Initialize (to confirm the real date), never for Replace.
  if (lifecycleStatus === 'unknown' || lifecycleStatus === 'baseline_known') return canInitialize ? 'initialize' : null
  if (lifecycleStatus === 'active') return canReplace ? 'replace' : null
  return null
}

export function resolveReplacementInventorySource(value) {
  return value === 'external_untracked' ? 'external_untracked' : 'inventory'
}
