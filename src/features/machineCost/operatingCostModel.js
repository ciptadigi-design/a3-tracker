export const operatingCostCategories = [
  ['electricity', 'Electricity'], ['service_contract', 'Service Contract'], ['routine_service', 'Routine Service'],
  ['labor', 'Labor Allocation'], ['rental_or_lease', 'Rental / Lease'], ['depreciation', 'Depreciation'],
  ['calibration', 'Calibration'], ['cleaning_material', 'Cleaning Material'], ['external_technician', 'External Technician'],
  ['software_or_license', 'Software / License'], ['other_operating', 'Other Operating'],
].map(([value, label]) => ({ value, label }))

export const categoryLabels = Object.fromEntries(operatingCostCategories.map((item) => [item.value, item.label]))

export function validOperatingCostDraft(value) {
  return value && ['one_time', 'daily_proration_v1'].includes(value.allocationMethod)
    && ['category', 'amount', 'effectiveAt', 'periodStart', 'periodEnd', 'operationalPersonId', 'externalReference', 'description', 'notes', 'clientRequestId']
      .every((field) => typeof value[field] === 'string')
}

export function operatingCostValidation(values) {
  if (!operatingCostCategories.some((item) => item.value === values.category)) return 'Choose an operating cost category.'
  if (!/^\d+(\.\d{1,2})?$/.test(values.amount) || Number(values.amount) <= 0) return 'Enter a positive amount with at most two decimal places.'
  if (!values.description.trim()) return 'Description is required.'
  if (values.allocationMethod === 'one_time' && !values.effectiveAt) return 'Effective date and time are required.'
  if (values.allocationMethod === 'daily_proration_v1' && (!values.periodStart || !values.periodEnd || values.periodEnd < values.periodStart)) return 'Choose a valid inclusive period.'
  return null
}
