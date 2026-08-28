export { inventoryItemLabel } from './inventoryItemPresentation.js'

function normalized(value) {
  return String(value ?? '').trim().toLocaleLowerCase()
}

export function discoverPurchaseItems(items, components, query) {
  const term = normalized(query)
  const eligibleItems = items.filter((item) => item.is_active !== false)
  const itemResults = eligibleItems.filter((item) => {
    if (!term) return true
    return [item.sku, item.name, item.components?.code, item.components?.name]
      .some((value) => normalized(value).includes(term))
  })
  const linkedComponentIds = new Set(eligibleItems.map((item) => item.component_id).filter(Boolean))
  const missingComponents = components.filter((component) => component.is_active !== false && !linkedComponentIds.has(component.id))
    .filter((component) => term && [component.code, component.name].some((value) => normalized(value).includes(term)))

  return { itemResults, missingComponents }
}
