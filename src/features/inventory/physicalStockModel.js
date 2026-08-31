/**
 * Presentation ordering for the Physical Stock list.
 * Quantities are canonical numeric balances produced by the inventory service.
 */
export function sortPhysicalStockRows(rows) {
  return [...rows].sort((left, right) => {
    const leftQuantity = Number(left.total)
    const rightQuantity = Number(right.total)
    const leftKnown = left.total !== null && left.total !== undefined && left.total !== '' && Number.isFinite(leftQuantity)
    const rightKnown = right.total !== null && right.total !== undefined && right.total !== '' && Number.isFinite(rightQuantity)

    if (leftKnown !== rightKnown) return leftKnown ? -1 : 1
    if (leftKnown && leftQuantity !== rightQuantity) return rightQuantity - leftQuantity

    const nameOrder = String(left.item?.name ?? '').localeCompare(String(right.item?.name ?? ''))
    if (nameOrder !== 0) return nameOrder
    return String(left.item?.id ?? '').localeCompare(String(right.item?.id ?? ''))
  })
}
