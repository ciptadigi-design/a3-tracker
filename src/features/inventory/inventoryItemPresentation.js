export function inventoryItemLabel(item) {
  const sku = String(item?.sku ?? '').trim()
  return sku ? `${sku} · ${item.name}` : item?.name ?? ''
}

export function inventoryItemScope(item) {
  if (item?.components?.name) return `Component: ${item.components.name}`
  if (String(item?.notes ?? '').includes('LEGACY_IMPORT')) return 'Legacy item · Unspecified component'
  return item?.category || 'Uncategorized'
}
