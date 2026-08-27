import { supabase } from './client.js'

const personFields = 'id, account_id, name, linked_user_id, code, is_active, notes, created_at, updated_at, archived_at'
const manufacturerFields = 'id, account_id, code, name, website, notes, is_active, created_at, updated_at, archived_at'
const modelFields = 'id, account_id, manufacturer_id, model_code, name, machine_category, color_capability, description, notes, is_active, created_at, updated_at, archived_at, manufacturers(id, account_id, code, name)'

function optional(value) {
  return value?.trim() || null
}

export async function loadOperationalMasters({ accountId, includeArchived = false }) {
  const peopleQuery = supabase.from('operational_people').select(personFields).eq('account_id', accountId).order('name')
  const manufacturersQuery = supabase.from('manufacturers').select(manufacturerFields).or(`account_id.is.null,account_id.eq.${accountId}`).order('name')
  const modelsQuery = supabase.from('machine_models').select(modelFields).or(`account_id.is.null,account_id.eq.${accountId}`).order('name')
  if (!includeArchived) {
    peopleQuery.eq('is_active', true)
    manufacturersQuery.eq('is_active', true)
    modelsQuery.eq('is_active', true)
  }
  const [people, manufacturers, models] = await Promise.all([peopleQuery, manufacturersQuery, modelsQuery])
  for (const result of [people, manufacturers, models]) if (result.error) throw result.error
  return { people: people.data ?? [], manufacturers: manufacturers.data ?? [], models: models.data ?? [] }
}

export async function saveOperationalPerson({ accountId, personId, values }) {
  const payload = { name: values.name.trim(), code: optional(values.code), notes: optional(values.notes), is_active: values.isActive }
  const query = personId
    ? supabase.from('operational_people').update(payload).eq('id', personId).eq('account_id', accountId)
    : supabase.from('operational_people').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(personFields).single()
  if (error) throw error
  return data
}

export async function deleteOperationalPerson({ accountId, personId }) {
  const { data, error } = await supabase.from('operational_people').delete().eq('id', personId).eq('account_id', accountId).select('id')
  if (error) throw error
  return data?.[0] ?? null
}

export async function saveManufacturer({ accountId, manufacturerId, values }) {
  const payload = { code: values.code.trim(), name: values.name.trim(), website: optional(values.website), notes: optional(values.notes), is_active: values.isActive }
  const query = manufacturerId
    ? supabase.from('manufacturers').update(payload).eq('id', manufacturerId).eq('account_id', accountId)
    : supabase.from('manufacturers').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(manufacturerFields).single()
  if (error) throw error
  return data
}

export async function deleteManufacturer({ accountId, manufacturerId }) {
  const { data, error } = await supabase.from('manufacturers').delete().eq('id', manufacturerId).eq('account_id', accountId).select('id')
  if (error) throw error
  return data?.[0] ?? null
}

export async function saveMachineModel({ accountId, modelId, values }) {
  const payload = {
    manufacturer_id: values.manufacturerId,
    model_code: values.modelCode.trim(),
    name: values.name.trim(),
    machine_category: values.machineCategory,
    color_capability: values.colorCapability,
    description: optional(values.description),
    notes: optional(values.notes),
    is_active: values.isActive,
  }
  const query = modelId
    ? supabase.from('machine_models').update(payload).eq('id', modelId).eq('account_id', accountId)
    : supabase.from('machine_models').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(modelFields).single()
  if (error) throw error
  return data
}

export async function deleteMachineModel({ accountId, modelId }) {
  const { data, error } = await supabase.from('machine_models').delete().eq('id', modelId).eq('account_id', accountId).select('id')
  if (error) throw error
  return data?.[0] ?? null
}
