import assert from 'node:assert/strict'
import test from 'node:test'
import { projectLaravelMachineComponents, projectLaravelReplacementHistory } from './laravelComponentProjection.js'

const MACHINE_ID = '708e199e-7f77-4219-b278-37d0b94821d4'

// Mirrors the real Tuparev machine shape established during migration reconciliation:
// 28 machine_components, 58 component_lifecycles total (47 status='closed' historical records,
// 11 status='unknown' rows carrying a real installed_counter but no started_at), 17 rows with
// zero current lifecycle evidence at all, and 0 rows with a real active lifecycle.
function productionFixture() {
  let lifecycleIndex = 0
  return Array.from({ length: 28 }, (_, index) => {
    const closedCount = index < 19 ? 2 : 1
    const closedLifecycles = Array.from({ length: closedCount }, (_, historyIndex) => {
      lifecycleIndex += 1
      const installed = 1000000 + index * 1000 + historyIndex * 100
      return { id: `lifecycle-${lifecycleIndex}`, status: 'closed', installed_counter: String(installed), removed_counter: String(installed + 50000), actual_usage: '50000', source: 'legacy_import', created_at: `2026-08-${String((lifecycleIndex % 28) + 1).padStart(2, '0')}T00:00:00Z` }
    })

    // Rows 0-10 (11 of the 28) carry a status='unknown' lifecycle with a real installed_counter
    // derived from the machine's own prior closed-lifecycle chain, but started_at/installed_at are
    // deliberately null — there is no factual installation date. Rows 11-27 (17 of the 28) have no
    // current lifecycle row at all: zero evidence.
    const baselineLifecycles = index < 11
      ? (() => {
          lifecycleIndex += 1
          const lastClosed = closedLifecycles[closedLifecycles.length - 1]
          return [{ id: `lifecycle-${lifecycleIndex}`, status: 'unknown', installed_counter: String(Number(lastClosed.removed_counter)), removed_counter: null, started_at: null, installed_at: null, source: 'reconciliation_baseline', created_at: '2026-08-27T00:00:00Z' }]
        })()
      : []

    return {
      id: `assignment-${index + 1}`,
      machine_id: MACHINE_ID,
      component_id: `component-${index + 1}`,
      component: { code: `C1070-${index + 1}`, name: `Component ${index + 1}`, tracking_method: 'counter_based' },
      profile_slot: { baseline_expected_clicks: 100000, tracking_method: 'counter_based' },
      slot_code: `SLOT-${String(index + 1).padStart(2, '0')}`,
      source_type: 'inherited',
      status: 'configured',
      display_order: index,
      baseline_expected_clicks: 100000,
      latest_effective_counter: '1441597.0000',
      configuration_state: index < 11 ? 'BASELINE_KNOWN' : 'UNKNOWN',
      baseline_lifecycle_id: index < 11 ? `lifecycle-${lifecycleIndex}` : null,
      baseline_installed_counter: index < 11 ? baselineLifecycles[0].installed_counter : null,
      lifecycles: [...closedLifecycles, ...baselineLifecycles],
    }
  })
}

test('Tuparev-shaped 28-row fixture yields exactly 0 active / 11 baseline_known / 17 uninitialized-unknown', () => {
  const rows = projectLaravelMachineComponents(productionFixture())
  assert.equal(rows.length, 28)

  const active = rows.filter((row) => row.lifecycle_status === 'active')
  const baselineKnown = rows.filter((row) => row.lifecycle_status === 'baseline_known')
  const uninitializedUnknown = rows.filter((row) => row.lifecycle_status === 'unknown')

  assert.equal(active.length, 0)
  assert.equal(baselineKnown.length, 11)
  assert.equal(uninitializedUnknown.length, 17)
  assert.equal(active.length + baselineKnown.length + uninitializedUnknown.length, 28)
  assert.ok(rows.every((row) => row.latest_effective_counter === 1441597))
})

test('a status=unknown lifecycle with a real installed_counter projects as baseline_known, never active/initialized', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-1', machine_id: MACHINE_ID, component_id: 'component-1', component: { code: 'C1', name: 'Drum' }, slot_code: 'SLOT-01', source_type: 'inherited', status: 'configured', display_order: 0, baseline_expected_clicks: 100000,
    lifecycles: [{ id: 'lifecycle-1', status: 'unknown', installed_counter: '1250000', removed_counter: null, started_at: null, installed_at: null, source: 'reconciliation_baseline' }],
  }])

  assert.equal(row.lifecycle_status, 'baseline_known')
  assert.equal(row.installed_counter, null, 'installed_counter (active-lifecycle field) must stay null for a non-active row')
  assert.equal(row.baseline_installed_counter, 1250000, 'the real known installed_counter must be surfaced, not silently dropped')
  assert.equal(row.health_status, 'unknown', 'no health tier may be computed without a real install date')
  assert.equal(row.remaining_percent, null)
  assert.equal(row.current_usage, null)
  assert.ok(!('started_at' in row) || row.started_at == null, 'no fabricated started_at may appear')
})

test('a machine_component with zero lifecycle rows projects as uninitialized/unknown', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-2', machine_id: MACHINE_ID, component_id: 'component-2', component: { code: 'C2', name: 'Belt' }, slot_code: 'SLOT-02', source_type: 'inherited', status: 'configured', display_order: 1, baseline_expected_clicks: 100000,
    lifecycles: [],
  }])

  assert.equal(row.lifecycle_status, 'unknown')
  assert.equal(row.baseline_installed_counter, null)
  assert.equal(row.installed_counter, null)
  assert.equal(row.health_status, 'unknown')
})

test('a status=active lifecycle with a real started_at is still classified active/initialized (regression)', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-3', machine_id: MACHINE_ID, component_id: 'component-3', component: { code: 'C3', name: 'Fuser' }, slot_code: 'SLOT-03', source_type: 'inherited', status: 'configured', display_order: 2, baseline_expected_clicks: 100000, latest_effective_counter: '1300000',
    lifecycles: [{ id: 'lifecycle-3', status: 'active', installed_counter: '1200000', removed_counter: null, started_at: '2026-01-15T00:00:00Z', source: 'manual' }],
  }])

  assert.equal(row.lifecycle_status, 'active')
  assert.equal(row.installed_counter, 1200000)
  assert.equal(row.current_usage, 100000)
  assert.notEqual(row.health_status, 'unknown')
})

// M2.12I.2: the canonical new-Supabase machine_component_health view computes usage/remaining/health
// purely from status='active' + installed_counter + expected life + latest counter — it never
// references started_at at all. This proves the Laravel projection already matches that contract:
// an active lifecycle with started_at left null still gets full, real, non-fabricated health figures.
test('a status=active lifecycle with no started_at still projects full real health (never falls back to baseline_known/unknown)', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-healthy', machine_id: MACHINE_ID, component_id: 'component-healthy', component: { code: 'IBT', name: 'IBT' }, slot_code: 'IBT', source_type: 'inherited', status: 'configured', display_order: 0, baseline_expected_clicks: 200000, latest_effective_counter: '1441597',
    lifecycles: [{ id: 'lifecycle-healthy', status: 'active', installed_counter: '1406276', removed_counter: null, started_at: null, source: 'reconciliation_active' }],
  }])

  assert.equal(row.lifecycle_status, 'active')
  assert.notEqual(row.lifecycle_status, 'baseline_known')
  assert.notEqual(row.lifecycle_status, 'unknown')
  assert.equal(row.installed_counter, 1406276)
  assert.equal(row.current_usage, 1441597 - 1406276)
  assert.equal(row.remaining_percent, Math.round(((200000 - (1441597 - 1406276)) / 200000) * 10000) / 100)
  assert.equal(row.health_status, 'healthy')
})

test('a status=active lifecycle with no started_at correctly reaches overdue (negative remaining percent) health', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-overdue', machine_id: MACHINE_ID, component_id: 'component-overdue', component: { code: 'CHARGING_CORONA_K', name: 'Charging Corona Black' }, slot_code: 'CHARGING_CORONA_K', source_type: 'inherited', status: 'configured', display_order: 0, baseline_expected_clicks: 40000, latest_effective_counter: '1441597',
    lifecycles: [{ id: 'lifecycle-overdue', status: 'active', installed_counter: '1283795', removed_counter: null, started_at: null, source: 'reconciliation_active' }],
  }])

  assert.equal(row.lifecycle_status, 'active')
  assert.ok(row.remaining_percent < 0, 'a component used well past its expected life must show a negative remaining percentage, never be clamped or hidden')
  assert.equal(row.health_status, 'overdue')
})

test('a status=active lifecycle with no started_at can also land in warning territory', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-warning', machine_id: MACHINE_ID, component_id: 'component-warning', component: { code: 'CHARGING_CORONA_C', name: 'Charging Corona Cyan' }, slot_code: 'CHARGING_CORONA_C', source_type: 'inherited', status: 'configured', display_order: 0, baseline_expected_clicks: 40000, healthy_threshold_percent: 30, watch_threshold_percent: 15, warning_threshold_percent: 5, critical_threshold_percent: 0, latest_effective_counter: '1441597',
    lifecycles: [{ id: 'lifecycle-warning', status: 'active', installed_counter: '1405775', removed_counter: null, started_at: null, source: 'reconciliation_active' }],
  }])

  assert.equal(row.lifecycle_status, 'active')
  assert.equal(row.health_status, 'warning')
})

test('47 closed lifecycle rows project once into replacement history without fabricating current lifecycle state', () => {
  const fixture = productionFixture()
  fixture[0].lifecycles.push({ ...fixture[0].lifecycles[0] }) // duplicate id: dedupe should keep the count at 47
  const history = projectLaravelReplacementHistory(fixture)
  assert.equal(history.length, 47)
  assert.equal(new Set(history.map((row) => row.previous_lifecycle_id)).size, 47)
  assert.ok(history.every((row) => row.machine_id === MACHINE_ID))
})

// M2.17.5.5: TONER_C on CG-TUP-A3-01 - a Model Profile edit (16,000 -> 15,000)
// must not retroactively change what an already-installed lifecycle's expected
// life reads as. An active lifecycle's own baseline_expected_clicks_snapshot
// must always win over the machine component's current (mutable) value.
test('an active lifecycle keeps its own baseline_expected_clicks_snapshot even after the machine component baseline changes', () => {
  const [row] = projectLaravelMachineComponents([{
    id: 'assignment-toner-c', machine_id: MACHINE_ID, component_id: 'component-toner-c', component: { code: 'TONER_C', name: 'Toner Cyan' }, slot_code: 'TONER_C', source_type: 'inherited', status: 'configured', display_order: 0,
    // The Model Profile / machine component now reads the NEW 15,000 baseline...
    baseline_expected_clicks: 15000, profile_slot: { baseline_expected_clicks: 15000 }, latest_effective_counter: '1441597',
    lifecycles: [{
      id: 'lifecycle-toner-c-active', status: 'active', installed_counter: '1400000', removed_counter: null, started_at: '2026-08-01T00:00:00Z', source: 'replacement',
      // ...but this lifecycle was installed while the baseline was still 16,000.
      baseline_expected_clicks_snapshot: 16000,
    }],
  }])

  assert.equal(row.effective_expected, 16000, 'the active lifecycle must keep reporting its own install-time baseline, not the current machine component value')
})

test('a closed historical lifecycle in replacement history keeps its own baseline_expected_clicks_snapshot, not the current machine component value', () => {
  const row = {
    id: 'assignment-toner-c-hist', machine_id: MACHINE_ID, component_id: 'component-toner-c', component: { code: 'TONER_C', name: 'Toner Cyan' }, slot_code: 'TONER_C', source_type: 'inherited', status: 'configured',
    baseline_expected_clicks: 15000,
    lifecycles: [{ id: 'lifecycle-toner-c-closed', status: 'closed', installed_counter: '1300000', removed_counter: '1400000', actual_usage: '100000', baseline_expected_clicks_snapshot: 16000, replaced_at: '2026-07-01T00:00:00Z' }],
  }

  const [history] = projectLaravelReplacementHistory([row])
  assert.equal(history.expected_at_install, 16000, 'closed historical lifecycle must keep reporting its own install-time baseline, not the current machine component value')
})
