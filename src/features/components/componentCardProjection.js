function logicalSlotKey(row) {
  return `${row.machine_id}:${String(row.slot_code).trim().toLowerCase()}`
}

export function projectCurrentComponentCards(rows) {
  const currentBySlot = new Map()
  for (const row of rows) {
    if (!['unknown', 'active'].includes(row.lifecycle_status)) continue
    if (row.component_code === 'TEST_COMPONENT' || row.slot_code === 'TEST_COMPONENT') continue
    const key = logicalSlotKey(row)
    const current = currentBySlot.get(key)
    if (!current || (current.lifecycle_status === 'unknown' && row.lifecycle_status === 'active')) currentBySlot.set(key, row)
  }
  return [...currentBySlot.values()].sort((left, right) => Number(left.display_order ?? 0) - Number(right.display_order ?? 0))
}
