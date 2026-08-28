export function inventoryItemLabel(item) {
  const sku = String(item?.sku ?? '').trim()
  return sku ? `${sku} · ${item.name}` : item?.name ?? ''
}

export function inventoryItemScope(item) {
  return item?.components?.name ? `Component: ${item.components.name}` : item?.category || 'Uncategorized'
}
