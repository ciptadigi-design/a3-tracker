const numeric = (value) => value == null || value === '' ? null : Number(value)

function lifecycleTime(lifecycle) {
  return lifecycle.ended_at || lifecycle.started_at || lifecycle.created_at || ''
}

function latestActiveLifecycle(lifecycles = []) {
  return lifecycles
    .filter((lifecycle) => lifecycle.status === 'active')
    .sort((left, right) => lifecycleTime(right).localeCompare(lifecycleTime(left)) || String(right.id).localeCompare(String(left.id)))[0] ?? null
}

// BASELINE_KNOWN: a status='unknown' lifecycle with a real installed_counter (derived from the machine's own
// prior closed-lifecycle chain) but no started_at/installed_at — there is no factual installation date, so this
// must never be treated as an active lifecycle or used to compute usage/health.
function latestBaselineLifecycle(lifecycles = []) {
  return lifecycles
    .filter((lifecycle) => lifecycle.status === 'unknown' && lifecycle.installed_counter != null)
    .sort((left, right) => lifecycleTime(right).localeCompare(lifecycleTime(left)) || String(right.id).localeCompare(String(left.id)))[0] ?? null
}

function healthFor(remainingPercent, row) {
  if (remainingPercent == null) return 'unknown'
  if (remainingPercent > numeric(row.healthy_threshold_percent ?? 30)) return 'healthy'
  if (remainingPercent > numeric(row.watch_threshold_percent ?? 15)) return 'watch'
  if (remainingPercent > numeric(row.warning_threshold_percent ?? 5)) return 'warning'
  if (remainingPercent > numeric(row.critical_threshold_percent ?? 0)) return 'critical'
  return 'overdue'
}

export function projectLaravelMachineComponents(rows = []) {
  return rows
    .filter((row) => row.status === 'configured')
    .map((row) => {
      const lifecycle = latestActiveLifecycle(row.lifecycles)
      const baselineLifecycle = lifecycle ? null : latestBaselineLifecycle(row.lifecycles)
      const latestCounter = numeric(row.latest_effective_counter)
      const installedCounter = numeric(lifecycle?.installed_counter)
      // Historical baseline provenance only — never a live "installed since counter X, currently active"
      // narrative, since the real installation date is unknown for these rows.
      const baselineInstalledCounter = numeric(baselineLifecycle?.installed_counter ?? row.baseline_installed_counter)
      const expected = numeric(lifecycle?.expected_at_install ?? lifecycle?.baseline_expected_clicks_snapshot ?? row.baseline_expected_clicks ?? row.profile_slot?.baseline_expected_clicks)
      const currentUsage = lifecycle && latestCounter != null && installedCounter != null ? latestCounter - installedCounter : null
      const remainingClicks = currentUsage != null && expected != null ? expected - currentUsage : null
      const remainingPercent = remainingClicks != null && expected > 0 ? Math.round((remainingClicks / expected) * 10000) / 100 : null
      const lifecycleStatus = lifecycle ? 'active' : (baselineLifecycle || row.configuration_state === 'BASELINE_KNOWN') ? 'baseline_known' : 'unknown'

      return {
        ...row,
        ...(lifecycle || {}),
        machine_id: row.machine_id,
        assignment_id: row.id,
        component_id: row.component_id,
        component_name: row.component?.name,
        component_code: row.component?.code,
        slot_code: row.slot_code,
        source_type: row.source_type,
        tracking_method: row.tracking_method ?? row.profile_slot?.tracking_method ?? row.component?.tracking_method,
        lifecycle_status: lifecycleStatus,
        lifecycle_id: lifecycle?.id ?? null,
        baseline_lifecycle_id: baselineLifecycle?.id ?? row.baseline_lifecycle_id ?? null,
        installed_counter: installedCounter,
        baseline_installed_counter: baselineInstalledCounter,
        current_profile_baseline: numeric(row.baseline_expected_clicks ?? row.profile_slot?.baseline_expected_clicks),
        effective_expected: expected,
        expected_at_install: expected,
        expected_source: lifecycle ? 'Baseline snapshot' : lifecycleStatus === 'baseline_known' ? 'Historical baseline · installation date unknown' : 'Not initialized',
        latest_effective_counter: latestCounter,
        latest_counter_observed_at: row.latest_counter_observed_at ?? null,
        current_usage: currentUsage,
        remaining_clicks: remainingClicks,
        remaining_percent: remainingPercent,
        estimated_replacement_counter: installedCounter != null && expected != null ? installedCounter + expected : null,
        // No health tier is ever computed from a baseline that lacks a real install date.
        health_status: lifecycle ? healthFor(remainingPercent, row) : 'unknown',
      }
    })
    .sort((left, right) => Number(left.display_order ?? 0) - Number(right.display_order ?? 0) || String(left.slot_code).localeCompare(String(right.slot_code)))
}

export function projectLaravelReplacementHistory(rows = []) {
  const history = new Map()
  for (const row of rows) {
    for (const lifecycle of row.lifecycles || []) {
      if (lifecycle.status !== 'closed' || history.has(lifecycle.id)) continue
      const installed = numeric(lifecycle.installed_counter)
      const removed = numeric(lifecycle.removed_counter)
      const actualUsage = numeric(lifecycle.actual_usage) ?? (installed != null && removed != null ? removed - installed : null)
      const expected = numeric(lifecycle.expected_at_install ?? lifecycle.baseline_expected_clicks_snapshot ?? row.baseline_expected_clicks ?? row.profile_slot?.baseline_expected_clicks)
      history.set(lifecycle.id, {
        replacement_event_id: `lifecycle:${lifecycle.id}`,
        previous_lifecycle_id: lifecycle.id,
        machine_id: row.machine_id,
        component_id: row.component_id,
        component_code: row.component?.code,
        component_name: row.component?.name,
        slot_code: row.slot_code,
        slot_code_snapshot: row.slot_code,
        tracking_method: row.tracking_method ?? row.profile_slot?.tracking_method ?? row.component?.tracking_method,
        previous_installed_counter: installed,
        replacement_counter: removed,
        actual_usage: actualUsage,
        expected_at_install: expected,
        performance_percent: expected > 0 && actualUsage != null ? Math.round((actualUsage / expected) * 10000) / 100 : null,
        replaced_at: lifecycle.ended_at ?? null,
        replacement_reason: null,
        condition_at_removal: null,
        include_in_adaptive_learning: false,
        performed_by_name_snapshot: null,
        new_installed_counter: null,
        new_expected_at_install: null,
        new_lifecycle_status: null,
        inventory_source: null,
        notes: lifecycle.notes,
      })
    }
  }
  return [...history.values()].sort((left, right) => String(right.replaced_at ?? '').localeCompare(String(left.replaced_at ?? '')) || left.replacement_event_id.localeCompare(right.replacement_event_id))
}
