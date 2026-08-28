const referenceLabels = {
  opening_balance: 'Opening Balance',
  purchase_receipt: 'Purchase Receipt',
  component_replacement: 'Component Replacement',
  toner_refill: 'Toner Refill',
  maintenance: 'Maintenance',
  manual_adjustment: 'Manual Adjustment',
  stock_transfer: 'Stock Transfer',
}

const movementLabels = {
  opening_balance: 'Opening Balance', receipt: 'Receipt', issue: 'Issue', adjustment_in: 'Adjustment In',
  adjustment_out: 'Adjustment Out', transfer_in: 'Transfer In', transfer_out: 'Transfer Out',
}

export function buildMovementDetail(row, relatedMovement, { formatCurrency, formatQuantity, formatTime }) {
  const positive = Number(row.quantity) > 0
  const sections = [{
    title: 'Movement',
    fields: [
      ['Quantity', `${positive ? '+' : ''}${formatQuantity(row.quantity)} ${row.unit_snapshot}`],
      ['Location', row.location_name],
      ['PIC', row.operational_person_name_snapshot || 'Not recorded'],
      ['Time', formatTime(row.occurred_at)],
    ],
  }]
  const referenceFields = [['Type', referenceLabels[row.reference_type] || row.reference_type?.replaceAll('_', ' ') || 'Not recorded']]
  if (row.reference_type === 'component_replacement' || row.reference_type === 'toner_refill') {
    if (row.replacement_machine_code || row.replacement_machine_name) referenceFields.push(['Machine', [row.replacement_machine_code, row.replacement_machine_name].filter(Boolean).join(' · ')])
    if (row.replacement_component_name) referenceFields.push(['Component', row.replacement_component_name])
    referenceFields.push(['Replacement context', movementLabels[row.movement_type] || 'Inventory issue'])
  }
  if (row.reference_type === 'purchase_receipt') {
    if (row.receipt_supplier_name) referenceFields.push(['Supplier', row.receipt_supplier_name])
    if (row.receipt_purchase_number) referenceFields.push(['Purchase Number', row.receipt_purchase_number])
    if (row.receipt_number) referenceFields.push(['Receipt', row.receipt_number])
    if (row.receipt_unit_price != null) referenceFields.push(['Unit Acquisition Price', `${formatCurrency(row.receipt_unit_price, row.receipt_currency_code)} / ${row.unit_snapshot}`])
  }
  if (row.movement_type.startsWith('transfer_')) {
    const outbound = row.movement_type === 'transfer_out' ? row : relatedMovement
    const inbound = row.movement_type === 'transfer_in' ? row : relatedMovement
    if (outbound?.location_name) referenceFields.push(['Source', outbound.location_name])
    if (inbound?.location_name) referenceFields.push(['Destination', inbound.location_name])
  }
  sections.push({ title: 'Reference', fields: referenceFields })

  const inbound = Number(row.inbound_cost_layer_count ?? 0) > 0
  const outbound = Number(row.cost_layer_count ?? 0) > 0
  const costFields = []
  if (row.reference_type === 'purchase_receipt' && row.receipt_acquisition_value != null) costFields.push(['Receipt acquisition value', formatCurrency(row.receipt_acquisition_value, row.receipt_currency_code)])
  if (inbound) {
    const unknown = Number(row.inbound_unknown_cost_quantity ?? 0)
    costFields.push(['Cost basis', row.inbound_cost_is_complete ? 'Known' : unknown > 0 ? 'Partially known' : 'Unknown'])
    costFields.push(['Known acquisition cost', formatCurrency(row.inbound_known_acquisition_cost)])
    if (unknown > 0) costFields.push(['Unknown-cost quantity', `${formatQuantity(unknown)} ${row.unit_snapshot}`])
    costFields.push(['FIFO lineage', `${row.inbound_cost_layer_count} inbound cost layer${Number(row.inbound_cost_layer_count) === 1 ? '' : 's'}`])
  } else if (outbound) {
    const unknown = Number(row.unknown_cost_quantity ?? 0)
    costFields.push(['Cost basis', row.cost_is_complete ? 'Known' : unknown > 0 ? 'Partially known' : 'Unknown'])
    costFields.push(['Allocated acquisition cost', formatCurrency(row.allocated_cost)])
    if (unknown > 0) costFields.push(['Unknown-cost quantity', `${formatQuantity(unknown)} ${row.unit_snapshot}`])
    costFields.push(['Purchase / receipt lineage', `${row.cost_layer_count} FIFO allocation layer${Number(row.cost_layer_count) === 1 ? '' : 's'}`])
  }
  if (!costFields.length) costFields.push(['Cost basis', 'No monetary cost evidence recorded for this movement'])
  sections.push({ title: 'Cost Evidence', fields: costFields })

  const auditFields = [['Entered By', row.created_by_name_snapshot || 'Not recorded']]
  if (row.reason) auditFields.push(['Reason', row.reason])
  if (row.notes) auditFields.push(['Notes', row.notes])
  sections.push({ title: 'Audit', fields: auditFields })
  return { movementType: movementLabels[row.movement_type] || row.movement_type?.replaceAll('_', ' '), sections }
}
