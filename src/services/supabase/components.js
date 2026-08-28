import { supabase } from './client.js'

const componentFields = 'id, account_id, code, name, category, description, manufacturer_id, part_number, default_tracking_method, is_active, archived_at, updated_at'
const profileFields = `id, account_id, machine_model_id, component_id, slot_code, display_order, tracking_method,
baseline_expected_clicks, adaptive_enabled, healthy_threshold_percent, watch_threshold_percent,
warning_threshold_percent, critical_threshold_percent, notes, is_active, archived_at, updated_at,
components (${componentFields})`

export async function loadComponentFoundation({ accountId } = {}) {
  const [manufacturers, models, components, profiles, intelligence, samples] = await Promise.all([
    supabase.from('manufacturers').select('id, account_id, code, name').or(`account_id.is.null,account_id.eq.${accountId}`).order('name'),
    supabase.from('machine_models').select('id, account_id, manufacturer_id, model_code, name, manufacturers(id, name)').or(`account_id.is.null,account_id.eq.${accountId}`).order('name'),
    supabase.from('components').select(componentFields).order('name'),
    supabase.from('machine_model_components').select(profileFields).order('display_order'),
    accountId
      ? supabase.from('component_adaptive_intelligence').select('*').eq('account_id', accountId)
      : Promise.resolve({ data: [], error: null }),
    accountId
      ? supabase.from('component_adaptive_sample_diagnostics').select('*').eq('account_id', accountId).order('replaced_at', { ascending: false })
      : Promise.resolve({ data: [], error: null }),
  ])
  for (const result of [manufacturers, models, components, profiles, intelligence, samples]) if (result.error) throw result.error
  return {
    manufacturers: manufacturers.data ?? [], models: models.data ?? [], components: components.data ?? [], profiles: profiles.data ?? [],
    intelligence: intelligence.data ?? [], intelligenceSamples: samples.data ?? [],
  }
}

export async function adoptIntelligenceRecommendation({ accountId, profileId, baseline, sampleFingerprint, algorithmVersion, clientRequestId, reason }) {
  const { data, error } = await supabase.rpc('adopt_component_intelligence_recommendation', {
    target_account_id: accountId,
    target_effective_profile_id: profileId,
    target_current_baseline: baseline,
    target_sample_fingerprint: sampleFingerprint,
    target_algorithm_version: algorithmVersion,
    target_client_request_id: clientRequestId,
    target_reason: reason?.trim() || null,
  })
  if (error) throw error
  return data
}

export function effectiveProfiles(profiles, accountId, modelId) {
  const bySlot = new Map()
  profiles.filter((item) => item.machine_model_id === modelId && item.account_id == null)
    .forEach((item) => bySlot.set(item.slot_code.toUpperCase(), item))
  profiles.filter((item) => item.machine_model_id === modelId && item.account_id === accountId)
    .forEach((item) => bySlot.set(item.slot_code.toUpperCase(), item))
  return [...bySlot.values()].sort((a, b) => a.display_order - b.display_order || a.slot_code.localeCompare(b.slot_code))
}

const optional = (value) => value?.trim() || null
const normalizedCategory = (value) => value?.trim().replace(/\s+/g, ' ') || null

export async function saveComponent({ accountId, component, values }) {
  const payload = {
    code: values.code.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_|_$/g, ''),
    name: values.name.trim(), category: normalizedCategory(values.category), description: optional(values.description),
    manufacturer_id: values.manufacturerId || null, part_number: optional(values.partNumber),
    default_tracking_method: values.trackingMethod,
  }
  const query = component
    ? supabase.from('components').update(payload).eq('id', component.id).eq('account_id', accountId)
    : supabase.from('components').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(componentFields).single()
  if (error) throw error
  return data
}

export async function setComponentStatus({ accountId, componentId, action, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_component_catalog_status', { target_account_id: accountId, target_component_id: componentId, target_action: action, target_client_request_id: clientRequestId })
  if (error) throw error
  return data
}

function profilePayload(values) {
  return {
    component_id: values.componentId, slot_code: values.slotCode.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_|_$/g, ''),
    display_order: Number(values.displayOrder), tracking_method: values.trackingMethod,
    baseline_expected_clicks: values.baselineExpectedClicks === '' ? null : Number(values.baselineExpectedClicks),
    adaptive_enabled: values.adaptiveEnabled,
    healthy_threshold_percent: Number(values.healthyThreshold), watch_threshold_percent: Number(values.watchThreshold),
    warning_threshold_percent: Number(values.warningThreshold), critical_threshold_percent: Number(values.criticalThreshold),
    notes: optional(values.notes),
  }
}

export async function saveProfile({ accountId, modelId, profile, values }) {
  const payload = profilePayload(values)
  const { data, error } = await supabase.rpc('save_machine_model_component_profile', {
    target_account_id: accountId, target_machine_model_id: modelId, target_profile_id: profile?.id ?? null,
    target_component_id: payload.component_id, target_slot_code: payload.slot_code, target_display_order: payload.display_order,
    target_tracking_method: payload.tracking_method, target_baseline_expected_clicks: payload.baseline_expected_clicks,
    target_adaptive_enabled: payload.adaptive_enabled, target_healthy_threshold: payload.healthy_threshold_percent,
    target_watch_threshold: payload.watch_threshold_percent, target_warning_threshold: payload.warning_threshold_percent,
    target_critical_threshold: payload.critical_threshold_percent, target_notes: payload.notes, target_client_request_id: values.clientRequestId,
  })
  if (error) throw error
  return data
}

export async function setProfileStatus({ accountId, profileId, action, clientRequestId }) {
  const { data, error } = await supabase.rpc('manage_machine_component_profile', { target_account_id: accountId, target_profile_id: profileId, target_action: action, target_client_request_id: clientRequestId })
  if (error) throw error
  return data
}

export async function addMachineComponent({ accountId, machineId, values }) {
  const { data, error } = await supabase.rpc('add_machine_component_assignment', { target_account_id: accountId, target_machine_id: machineId, target_component_id: values.componentId, target_slot_code: values.slotCode, target_tracking_method: values.trackingMethod, target_baseline_expected_clicks: values.baselineExpectedClicks === '' ? null : Number(values.baselineExpectedClicks), target_notes: values.notes?.trim() || null, target_client_request_id: values.clientRequestId })
  if (error) throw error
  return data
}

export async function removeMachineComponent({ accountId, assignmentId, reason, clientRequestId }) {
  const { data, error } = await supabase.rpc('remove_machine_component_assignment', { target_account_id: accountId, target_assignment_id: assignmentId, target_reason: reason, target_client_request_id: clientRequestId })
  if (error) throw error
  return data
}

export async function clearMachineComponentExclusion({ accountId, machineId, profileId, clientRequestId }) {
  const { data, error } = await supabase.rpc('clear_machine_component_exclusion', { target_account_id: accountId, target_machine_id: machineId, target_profile_id: profileId, target_client_request_id: clientRequestId })
  if (error) throw error
  return data
}

export async function syncMachineComponents({ accountId, machineId }) {
  const { data, error } = await supabase.rpc('sync_machine_component_assignments', { target_account_id: accountId, target_machine_id: machineId })
  if (error) throw error
  return data
}
