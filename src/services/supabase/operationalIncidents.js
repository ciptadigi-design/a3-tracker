import { supabase } from './client.js'
import { operationalError } from '../../lib/appErrors.js'

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
  operator_person_id,
  operator_name_snapshot,
  responsible_person_id,
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
  resolved_by,
  resolved_at,
  resolution_note,
  voided_by,
  voided_at,
  void_reason
`

export async function loadOperationalIncidents({ accountId, branchId }) {
  const [incidentResult, profileResult, peopleResult] = await Promise.all([
    supabase
      .from('operational_incidents')
      .select(incidentFields)
      .eq('account_id', accountId)
      .eq('branch_id', branchId)
      .order('occurred_at', { ascending: false })
      .order('created_at', { ascending: false }),
    supabase.rpc('get_account_member_profiles', { target_account_id: accountId }),
    supabase.from('operational_people').select('id,name,is_active,operational_person_branches!inner(branch_id,is_active)').eq('account_id', accountId).eq('is_active', true).eq('operational_person_branches.branch_id', branchId).eq('operational_person_branches.is_active', true).order('name'),
  ])

  if (incidentResult.error) throw incidentResult.error
  return {
    incidents: incidentResult.data ?? [],
    members: profileResult.error ? [] : profileResult.data ?? [],
    people: peopleResult.error ? [] : peopleResult.data ?? [],
  }
}

export async function loadOperationalIncident({ accountId, branchId, incidentId }) {
  const incidentResult = await supabase
    .from('operational_incidents')
    .select(incidentFields)
    .eq('account_id', accountId)
    .eq('branch_id', branchId)
    .eq('id', incidentId)
    .maybeSingle()
  if (incidentResult.error) throw incidentResult.error
  if (!incidentResult.data) return { incident: null, members: [], revisions: [], people: [] }

  const [profileResult, revisionResult, peopleResult] = await Promise.all([
    supabase.rpc('get_account_member_profiles', { target_account_id: accountId }),
    supabase
      .from('operational_incident_revisions')
      .select('id, account_id, incident_id, changed_by, changed_at, change_reason, old_values, new_values, changed_fields')
      .eq('account_id', accountId)
      .eq('incident_id', incidentId)
      .order('changed_at', { ascending: true })
      .order('id', { ascending: true }),
    supabase.from('operational_people').select('id,name,is_active,operational_person_branches!inner(branch_id,is_active)').eq('account_id', accountId).eq('operational_person_branches.branch_id', branchId).eq('operational_person_branches.is_active', true).order('name'),
  ])

  if (revisionResult.error) throw revisionResult.error
  return {
    incident: incidentResult.data,
    members: profileResult.error ? [] : profileResult.data ?? [],
    revisions: revisionResult.data ?? [],
    people: peopleResult.error ? [] : peopleResult.data ?? [],
  }
}

function incidentMutationPayload(values) {
  return {
    target_base_updated_at: values.baseUpdatedAt,
    target_occurred_at: new Date(values.occurredAt).toISOString(),
    target_category: values.category,
    target_incident_type: values.incidentType,
    target_description: values.description.trim(),
    target_machine_id: values.machineId || null,
    target_invoice_number: values.invoiceNumber.trim() || null,
    target_customer_name: values.customerName.trim() || null,
    target_product_name: values.productName.trim() || null,
    target_qty_affected: values.qtyAffected === '' ? null : Number(values.qtyAffected),
    target_operator_person_id: values.operatorPersonId || null,
    target_responsible_person_id: values.responsiblePersonId || null,
    target_material_loss: values.materialLoss === '' ? 0 : Number(values.materialLoss),
    target_service_loss: values.serviceLoss === '' ? 0 : Number(values.serviceLoss),
    target_cause: values.cause.trim() || null,
    target_prevention: values.prevention.trim() || null,
    target_customer_resolution: values.customerResolution.trim() || null,
    target_change_reason: values.changeReason.trim() || null,
  }
}

export async function updateOperationalIncident({ incidentId, values }) {
  const { data, error } = await supabase.rpc('update_operational_incident_v2', {
    target_incident_id: incidentId,
    ...incidentMutationPayload(values),
  })
  if (error) throw operationalError(error, { operation: 'incident.update' }, 'The incident could not be updated.')
  return data
}

export async function solveOperationalIncident({ incidentId, resolutionNote }) {
  const { data, error } = await supabase.rpc('solve_operational_incident', {
    target_incident_id: incidentId,
    target_resolution_note: resolutionNote.trim() || null,
  })
  if (error) throw error
  return data
}

export async function createOperationalIncident({ accountId, branchId, values }) {
  const { data, error } = await supabase.rpc('create_operational_incident_v2', {
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
    target_operator_person_id: values.operatorPersonId || null,
    target_responsible_person_id: values.responsiblePersonId || null,
    target_material_loss: values.materialLoss === '' ? 0 : Number(values.materialLoss),
    target_service_loss: values.serviceLoss === '' ? 0 : Number(values.serviceLoss),
    target_cause: values.cause.trim() || null,
    target_prevention: values.prevention.trim() || null,
    target_customer_resolution: values.customerResolution.trim() || null,
  })

  if (error) throw operationalError(error, { operation: 'incident.create', accountId, branchId, clientRequestId: values.clientRequestId }, 'The incident could not be recorded.')
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
