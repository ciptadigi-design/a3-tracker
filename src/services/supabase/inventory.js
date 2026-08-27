import { supabase } from './client.js'

const itemFields = 'id,account_id,component_id,sku,name,category,unit,minimum_stock,notes,is_active,created_at,updated_at,archived_at,components(id,account_id,code,name,category)'
const locationFields = 'id,account_id,branch_id,code,name,notes,is_active,created_at,updated_at,archived_at,branches(id,code,name)'

function optional(value) { return value?.trim() || null }

export async function loadInventory({ accountId, includeArchived = false }) {
  const itemsQuery = supabase.from('inventory_items').select(itemFields).eq('account_id', accountId).order('name')
  const locationsQuery = supabase.from('inventory_locations').select(locationFields).eq('account_id', accountId).order('name')
  if (!includeArchived) { itemsQuery.eq('is_active', true); locationsQuery.eq('is_active', true) }
  const [items, locations, balances, totals, movements, components, people] = await Promise.all([
    itemsQuery,
    locationsQuery,
    supabase.from('inventory_stock_balances').select('account_id,inventory_item_id,location_id,quantity').eq('account_id', accountId),
    supabase.from('inventory_item_totals').select('account_id,inventory_item_id,quantity').eq('account_id', accountId),
    supabase.from('inventory_movement_history').select('*').eq('account_id', accountId).order('occurred_at', { ascending: false }).order('created_at', { ascending: false }).limit(500),
    supabase.from('components').select('id,account_id,code,name,category,is_active').or(`account_id.is.null,account_id.eq.${accountId}`).eq('is_active', true).order('name'),
    supabase.from('operational_people').select('id,account_id,name,code,is_active').eq('account_id', accountId).eq('is_active', true).order('name'),
  ])
  for (const result of [items, locations, balances, totals, movements, components, people]) if (result.error) throw result.error
  return {
    items: items.data ?? [], locations: locations.data ?? [], balances: balances.data ?? [],
    totals: totals.data ?? [], movements: movements.data ?? [], components: components.data ?? [], people: people.data ?? [],
  }
}

export async function saveInventoryItem({ accountId, itemId, values }) {
  const payload = {
    component_id: values.componentId || null, sku: values.sku.trim(), name: values.name.trim(),
    category: optional(values.category), unit: values.unit, minimum_stock: values.minimumStock === '' ? null : values.minimumStock,
    notes: optional(values.notes), is_active: values.isActive,
  }
  const query = itemId
    ? supabase.from('inventory_items').update(payload).eq('id', itemId).eq('account_id', accountId)
    : supabase.from('inventory_items').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(itemFields).single()
  if (error) throw error
  return data
}

export async function deleteInventoryItem({ accountId, itemId }) {
  const { data, error } = await supabase.from('inventory_items').delete().eq('id', itemId).eq('account_id', accountId).select('id')
  if (error) throw error
  return data?.[0] ?? null
}

export async function saveInventoryLocation({ accountId, locationId, values }) {
  const payload = { branch_id: values.branchId || null, code: values.code.trim(), name: values.name.trim(), notes: optional(values.notes), is_active: values.isActive }
  const query = locationId
    ? supabase.from('inventory_locations').update(payload).eq('id', locationId).eq('account_id', accountId)
    : supabase.from('inventory_locations').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(locationFields).single()
  if (error) throw error
  return data
}

export async function deleteInventoryLocation({ accountId, locationId }) {
  const { data, error } = await supabase.from('inventory_locations').delete().eq('id', locationId).eq('account_id', accountId).select('id')
  if (error) throw error
  return data?.[0] ?? null
}

export async function initializeInventoryStock({ accountId, values, clientRequestId }) {
  const { data, error } = await supabase.rpc('initialize_inventory_stock', {
    target_account_id: accountId, target_inventory_item_id: values.itemId, target_location_id: values.locationId,
    target_quantity: values.quantity, target_occurred_at: values.occurredAt,
    target_operational_person_id: values.personId, target_notes: optional(values.notes), target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}

export async function adjustInventoryStock({ accountId, values, clientRequestId }) {
  const delta = values.direction === 'in' ? values.quantity : `-${values.quantity}`
  const { data, error } = await supabase.rpc('adjust_inventory_stock', {
    target_account_id: accountId, target_inventory_item_id: values.itemId, target_location_id: values.locationId,
    target_quantity_delta: delta, target_occurred_at: values.occurredAt,
    target_operational_person_id: values.personId, target_reason: values.reason,
    target_notes: optional(values.notes), target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}

export async function transferInventoryStock({ accountId, values, clientRequestId }) {
  const { data, error } = await supabase.rpc('transfer_inventory_stock', {
    target_account_id: accountId, target_inventory_item_id: values.itemId,
    target_source_location_id: values.sourceLocationId, target_destination_location_id: values.destinationLocationId,
    target_quantity: values.quantity, target_occurred_at: values.occurredAt,
    target_operational_person_id: values.personId, target_notes: optional(values.notes), target_client_request_id: clientRequestId,
  })
  if (error) throw error
  return data
}
