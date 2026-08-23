export const operationalStatuses = [
  { value: 'active', label: 'Active' },
  { value: 'down', label: 'Down' },
  { value: 'maintenance', label: 'Maintenance' },
]

export function createMachineFormValues({ branchId = '', machine = null } = {}) {
  return {
    branchId: machine?.branch_id ?? branchId,
    manufacturerId: machine?.machine_models?.manufacturer_id ?? '',
    machineModelId: machine?.machine_models?.id ?? '',
    machineCode: machine?.machine_code ?? '',
    displayName: machine?.display_name ?? '',
    serialNumber: machine?.serial_number ?? '',
    installedOn: machine?.installed_on ?? '',
    status: machine?.status === 'retired' ? 'active' : machine?.status ?? 'active',
    timezone: machine?.timezone ?? '',
    notes: machine?.notes ?? '',
  }
}

export function validateMachineForm(values, { branches, models, mode }) {
  const errors = {}
  if (!values.branchId) errors.branchId = 'Choose a branch.'
  else if (!branches.some((branch) => branch.id === values.branchId)) errors.branchId = 'Choose a branch from this account.'

  if (mode === 'create') {
    if (!values.manufacturerId) errors.manufacturerId = 'Choose a manufacturer.'
    if (!values.machineModelId) errors.machineModelId = 'Choose a machine model.'
    else if (!models.some((model) => model.id === values.machineModelId && model.manufacturer_id === values.manufacturerId)) errors.machineModelId = 'Choose a model from the selected manufacturer.'
  }

  if (!values.machineCode.trim()) errors.machineCode = 'Machine code is required.'
  if (!values.displayName.trim()) errors.displayName = 'Display name is required.'
  if (!operationalStatuses.some((status) => status.value === values.status)) errors.status = 'Choose a valid operational status.'
  return errors
}

export function mapMachineMutationError(error) {
  const source = `${error?.message ?? ''} ${error?.details ?? ''} ${error?.hint ?? ''}`
  if (error?.code === '23505' && source.includes('machines_account_machine_code_normalized_key')) {
    return { field: 'machineCode', message: 'That machine code is already reserved in this account.' }
  }
  if (error?.code === '23505' && source.includes('machines_account_model_serial_normalized_key')) {
    return { field: 'serialNumber', message: 'That serial number is already registered for this model in this account.' }
  }
  if (error?.code === '42501') return { message: 'Your account role does not allow machine changes.' }
  if (error?.code === '23514' && source.includes('timezone')) return { field: 'timezone', message: 'Enter a valid IANA timezone, such as Asia/Jakarta.' }
  if (error?.code === 'PGRST116') return { message: 'The machine changed or is no longer available. Refresh and try again.' }
  return { message: error?.message || 'The machine could not be saved. Please try again.' }
}
