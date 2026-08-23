import { supabase } from './client.js'

export async function loadCounterHistory({ accountId, machineId }) {
  const [historyResult, profilesResult] = await Promise.all([
    supabase
      .from('machine_counter_history')
      .select('reading_id, account_id, machine_id, counter_type_code, reading_value, previous_value, usage, observed_at, shift_code, entered_by, source, status, correction_reason, notes, previous_reading_id, corrects_reading_id, created_at')
      .eq('account_id', accountId)
      .eq('machine_id', machineId)
      .eq('counter_type_code', 'total_impressions')
      .order('observed_at', { ascending: false })
      .order('created_at', { ascending: false })
      .limit(100),
    supabase.rpc('get_account_member_profiles', { target_account_id: accountId }),
  ])

  if (historyResult.error) throw historyResult.error
  return {
    history: historyResult.data ?? [],
    profiles: profilesResult.error ? [] : profilesResult.data ?? [],
  }
}

export async function recordCounterReading({ accountId, machineId, readingValue, observedAt, shiftCode, notes, clientRequestId }) {
  const { data, error } = await supabase.rpc('record_machine_counter', {
    target_account_id: accountId,
    target_machine_id: machineId,
    target_reading_value: readingValue,
    target_observed_at: observedAt,
    target_client_request_id: clientRequestId,
    target_shift_code: shiftCode || null,
    target_notes: notes?.trim() || null,
    target_counter_type_code: 'total_impressions',
  })

  if (error) throw error
  return data
}

export async function correctCounterReading({ readingId, correctionReason, replacementValue, replacementNotes, clientRequestId }) {
  const { data, error } = await supabase.rpc('correct_machine_counter', {
    target_reading_id: readingId,
    target_correction_reason: correctionReason.trim(),
    target_replacement_value: replacementValue,
    target_client_request_id: replacementValue == null ? null : clientRequestId,
    target_replacement_notes: replacementNotes?.trim() || null,
  })

  if (error) throw error
  return data
}
