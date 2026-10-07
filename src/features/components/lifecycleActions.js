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

// Compatibility is explicit domain data (inventory_component_compatibilities),
// never display-name matching and never a component_id equality fallback - a
// single physical part can be compatible with several distinct component
// definitions at once (e.g. one generic item valid for all four CMYK
// positions).
export function eligibleCompatibleInventoryItems(inventoryItems, compatibilities, componentId) {
  return inventoryItems.filter((item) => compatibilities.some((c) => c.inventory_item_id === item.id && c.component_id === componentId))
}
