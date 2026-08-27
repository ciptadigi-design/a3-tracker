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
    default_tracking_method: values.trackingMethod, is_active: true,
  }
  const query = component
    ? supabase.from('components').update(payload).eq('id', component.id).eq('account_id', accountId)
    : supabase.from('components').insert({ ...payload, account_id: accountId })
  const { data, error } = await query.select(componentFields).single()
  if (error) throw error
  return data
}

export async function deleteComponent({ accountId, componentId }) {
  const result = await supabase.from('components').delete().eq('id', componentId).eq('account_id', accountId).select('id')
  if (!result.error) return { mode: 'deleted' }
  if (result.error.code !== '23503') throw result.error
  const archived = await supabase.from('components').update({ is_active: false }).eq('id', componentId).eq('account_id', accountId).select('id').single()
  if (archived.error) throw archived.error
  return { mode: 'archived' }
}

function profilePayload(values) {
  return {
    component_id: values.componentId, slot_code: values.slotCode.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_|_$/g, ''),
    display_order: Number(values.displayOrder), tracking_method: values.trackingMethod,
    baseline_expected_clicks: values.baselineExpectedClicks === '' ? null : Number(values.baselineExpectedClicks),
    adaptive_enabled: values.adaptiveEnabled,
    healthy_threshold_percent: Number(values.healthyThreshold), watch_threshold_percent: Number(values.watchThreshold),
    warning_threshold_percent: Number(values.warningThreshold), critical_threshold_percent: Number(values.criticalThreshold),
    notes: optional(values.notes), is_active: true,
  }
}

export async function saveProfile({ accountId, modelId, profile, values }) {
  const payload = profilePayload(values)
  const isOwned = profile?.account_id === accountId
  const query = isOwned
    ? supabase.from('machine_model_components').update(payload).eq('id', profile.id).eq('account_id', accountId)
    : supabase.from('machine_model_components').insert({ ...payload, account_id: accountId, machine_model_id: modelId })
  const { data, error } = await query.select(profileFields).single()
  if (error) throw error
  return data
}

export async function removeProfile({ accountId, modelId, profile }) {
  if (profile.account_id === accountId) {
    const result = await supabase.from('machine_model_components').delete().eq('id', profile.id).eq('account_id', accountId).select('id')
    if (!result.error) return { mode: 'deleted' }
    if (result.error.code !== '23503') throw result.error
    const archived = await supabase.from('machine_model_components').update({ is_active: false }).eq('id', profile.id).eq('account_id', accountId)
    if (archived.error) throw archived.error
    return { mode: 'archived' }
  }
  const { error } = await supabase.from('machine_model_components').insert({
    account_id: accountId, machine_model_id: modelId, component_id: profile.component_id,
    slot_code: profile.slot_code, display_order: profile.display_order, tracking_method: profile.tracking_method,
    baseline_expected_clicks: profile.baseline_expected_clicks, adaptive_enabled: profile.adaptive_enabled,
    healthy_threshold_percent: profile.healthy_threshold_percent, watch_threshold_percent: profile.watch_threshold_percent,
    warning_threshold_percent: profile.warning_threshold_percent, critical_threshold_percent: profile.critical_threshold_percent,
    notes: profile.notes, is_active: false,
  })
  if (error) throw error
  return { mode: 'archived' }
}
