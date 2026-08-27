import { supabase } from './client.js'

const machineFields = `
  id,
  account_id,
  branch_id,
  machine_code,
  display_name,
  serial_number,
  installed_on,
  status,
  timezone,
  notes,
  is_active,
  archived_at,
  updated_at,
  machine_models (
    id,
    manufacturer_id,
    model_code,
    name,
    color_capability,
    manufacturers (id, code, name)
  )
`

export async function loadMachines({ accountId, branchId }) {
  let query = supabase
    .from('machines')
    .select(machineFields)
    .eq('account_id', accountId)
    .order('display_name')

  if (branchId) query = query.eq('branch_id', branchId)

  const { data, error } = await query
  if (error) throw error
  return data ?? []
}

export async function loadMachine({ accountId, machineId }) {
  const { data, error } = await supabase
    .from('machines')
    .select(machineFields)
    .eq('account_id', accountId)
    .eq('id', machineId)
    .maybeSingle()

  if (error) throw error
  return data
}

export async function loadMachineCatalog(accountId) {
  const [manufacturerResult, modelResult] = await Promise.all([
    supabase
      .from('manufacturers')
      .select('id, code, name')
      .or(`account_id.is.null,account_id.eq.${accountId}`)
      .eq('is_active', true)
      .order('name'),
    supabase
      .from('machine_models')
      .select('id, manufacturer_id, model_code, name, machine_category, color_capability')
      .or(`account_id.is.null,account_id.eq.${accountId}`)
      .eq('is_active', true)
      .order('name'),
  ])

  if (manufacturerResult.error) throw manufacturerResult.error
  if (modelResult.error) throw modelResult.error
  return {
    manufacturers: manufacturerResult.data ?? [],
    models: modelResult.data ?? [],
  }
}

function cleanOptionalValue(value) {
  const cleaned = value?.trim()
  return cleaned || null
}

export async function createMachine({ accountId, values }) {
  const { data, error } = await supabase
    .from('machines')
    .insert({
      account_id: accountId,
      branch_id: values.branchId,
      machine_model_id: values.machineModelId,
      machine_code: values.machineCode.trim(),
      display_name: values.displayName.trim(),
      serial_number: cleanOptionalValue(values.serialNumber),
      installed_on: values.installedOn || null,
      status: values.status,
      timezone: cleanOptionalValue(values.timezone),
      notes: cleanOptionalValue(values.notes),
      is_active: true,
    })
    .select(machineFields)
    .single()

  if (error) throw error
  return data
}

export async function updateMachine({ accountId, machineId, values }) {
  const { data, error } = await supabase
    .from('machines')
    .update({
      branch_id: values.branchId,
      machine_code: values.machineCode.trim(),
      display_name: values.displayName.trim(),
      serial_number: cleanOptionalValue(values.serialNumber),
      installed_on: values.installedOn || null,
      status: values.status,
      timezone: cleanOptionalValue(values.timezone),
      notes: cleanOptionalValue(values.notes),
    })
    .eq('account_id', accountId)
    .eq('id', machineId)
    .eq('is_active', true)
    .select(machineFields)
    .single()

  if (error) throw error
  return data
}

export async function retireMachine({ accountId, machineId }) {
  const { data, error } = await supabase
    .from('machines')
    .update({ status: 'retired', is_active: false })
    .eq('account_id', accountId)
    .eq('id', machineId)
    .eq('is_active', true)
    .select(machineFields)
    .single()

  if (error) throw error
  return data
}
