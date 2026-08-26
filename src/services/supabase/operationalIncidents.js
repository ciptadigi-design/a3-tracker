import { supabase } from './client.js'

const incidentFields = `
  id,
  account_id,
  branch_id,
  machine_id,
  occurred_at,
  invoice_number,
  customer_name_snapshot,
  product_name_snapshot,
  category,
  incident_type,
  qty_affected,
  responsible_user_id,
  responsible_name_snapshot,
  material_loss,
  service_loss,
  penalty_multiplier,
  assessed_loss,
  description,
  cause,
  prevention,
  customer_resolution,
  status,
  created_by,
  created_at,
  updated_by,
  updated_at,
  voided_by,
  voided_at,
  void_reason
`

export async function loadOperationalIncidents({ accountId, branchId }) {
  const [incidentResult, profileResult] = await Promise.all([
    supabase
      .from('operational_incidents')
      .select(incidentFields)
      .eq('account_id', accountId)
      .eq('branch_id', branchId)
      .order('occurred_at', { ascending: false })
      .order('created_at', { ascending: false }),
    supabase.rpc('get_account_member_profiles', { target_account_id: accountId }),
  ])

  if (incidentResult.error) throw incidentResult.error
  return {
    incidents: incidentResult.data ?? [],
    members: profileResult.error ? [] : profileResult.data ?? [],
  }
}

export async function loadOperationalIncident({ accountId, incidentId }) {
  const [incidentResult, profileResult] = await Promise.all([
    supabase
      .from('operational_incidents')
      .select(incidentFields)
      .eq('account_id', accountId)
      .eq('id', incidentId)
      .maybeSingle(),
    supabase.rpc('get_account_member_profiles', { target_account_id: accountId }),
  ])

  if (incidentResult.error) throw incidentResult.error
  return {
    incident: incidentResult.data,
    members: profileResult.error ? [] : profileResult.data ?? [],
  }
}

export async function createOperationalIncident({ accountId, branchId, values }) {
  const { data, error } = await supabase.rpc('create_operational_incident', {
    target_account_id: accountId,
    target_branch_id: branchId,
    target_occurred_at: new Date(values.occurredAt).toISOString(),
    target_category: values.category,
    target_incident_type: values.incidentType,
    target_description: values.description.trim(),
    target_client_request_id: values.clientRequestId,
    target_machine_id: values.machineId || null,
    target_invoice_number: values.invoiceNumber.trim() || null,
    target_customer_name: values.customerName.trim() || null,
    target_product_name: values.productName.trim() || null,
    target_qty_affected: values.qtyAffected === '' ? null : Number(values.qtyAffected),
    target_responsible_user_id: values.responsibleUserId || null,
    target_responsible_name: values.responsibleName.trim() || null,
    target_material_loss: values.materialLoss === '' ? 0 : Number(values.materialLoss),
    target_service_loss: values.serviceLoss === '' ? 0 : Number(values.serviceLoss),
    target_cause: values.cause.trim() || null,
    target_prevention: values.prevention.trim() || null,
    target_customer_resolution: values.customerResolution.trim() || null,
  })

  if (error) throw error
  return data
}

export async function resolveOperationalIncident(incidentId) {
  const { data, error } = await supabase.rpc('resolve_operational_incident', {
    target_incident_id: incidentId,
  })
  if (error) throw error
  return data
}

export async function voidOperationalIncident({ incidentId, reason }) {
  const { data, error } = await supabase.rpc('void_operational_incident', {
    target_incident_id: incidentId,
    target_void_reason: reason.trim(),
  })
  if (error) throw error
  return data
}

