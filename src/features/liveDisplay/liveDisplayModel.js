import { normalizeDailyPerformance, requiredPacePresentation, targetStatusPresentation } from '../clickTargets/clickTargetModel.js'
import { resolveMachineCostPeriod } from '../machineCost/machineCostPeriods.js'

/**
 * Most recent effective counter readings, newest first, trimmed to the TV's
 * fixed display count. `loadCounterHistory` already orders newest-first and
 * includes corrected/voided rows (needed so CorrectCounterDialog can act on
 * them) - the TV only ever shows the effective lineage, same filter
 * CounterHistory.jsx already applies for "latest effective".
 */
export function recentActivityRows(history, limit = 5) {
  return (history ?? [])
    .filter((reading) => reading.status === 'effective')
    .slice(0, limit)
    .map((reading) => ({
      id: reading.reading_id,
      observedAt: reading.observed_at,
      shiftCode: reading.shift_code ?? null,
      operatorName: reading.operator_name_snapshot ?? null,
      usage: reading.usage ?? null,
      counter: reading.reading_value,
    }))
}

export function latestEffectiveReading(history) {
  return (history ?? []).find((reading) => reading.status === 'effective') ?? null
}

export function isCounterHistoryEmpty(history) {
  return !(history ?? []).some((reading) => reading.status === 'effective')
}

/**
 * Today's actual clicks, read from the same monthly projection the Monthly
 * Target panel uses - never recomputed independently (Overview's own
 * `todayRow` derivation in OverviewPage.jsx is mirrored exactly here).
 */
export function todayActualFromProjection(projection, timezone) {
  if (!projection) return null
  const rows = normalizeDailyPerformance(projection.daily)
  const todayKey = resolveMachineCostPeriod({ preset: 'today', timezone: projection.period?.timezone || timezone }).start
  const todayRow = rows.find((row) => row.date === todayKey)
  return todayRow?.actual ?? null
}

/**
 * Maps the authoritative click-target projection into the exact fields the
 * Monthly Target panel renders. Business semantics (ahead/behind, required
 * pace, expected-by-today) stay entirely backend-sourced; this only selects
 * and labels fields, same division of responsibility as periodCardPresentation
 * in clickTargetModel.js.
 */
export function targetSummaryFromProjection(projection) {
  if (!projection) return null
  const [statusLabel, statusTone] = targetStatusPresentation(projection.target_status)
  const requiredPace = projection.required_pace_status === 'NO_ACTIVE_DAYS_REMAINING'
    ? { value: null, hint: 'No active days remain' }
    : requiredPacePresentation(projection)

  return {
    actual: projection.actual ?? null,
    periodTarget: projection.period_target ?? null,
    achievementPercentage: projection.achievement_percentage ?? null,
    statusLabel,
    statusTone,
    paceVariance: projection.pace_variance ?? null,
    expectedByToday: projection.expected_by_today ?? null,
    remaining: projection.remaining ?? null,
    requiredPaceValue: requiredPace.value,
    requiredPaceHint: requiredPace.hint,
    notConfigured: projection.target_status === 'NOT_CONFIGURED',
  }
}

const OFFLINE_FAILURE_THRESHOLD = 3

/**
 * Status the display shows after a successful refresh: EMPTY only when the
 * machine genuinely has no usable counter history yet, FRESH otherwise.
 */
export function deriveStatusOnSuccess({ historyEmpty }) {
  return historyEmpty ? 'empty' : 'fresh'
}

/**
 * Status the display shows after a failed refresh. With no prior good data
 * there is nothing to protect, so a single failure already means OFFLINE.
 * With prior good data, a failure degrades to STALE first and only becomes
 * OFFLINE after repeated consecutive failures - the previously loaded
 * numbers must never disappear because of one blip.
 */
export function deriveStatusOnFailure({ hasData, failureCount }) {
  if (!hasData) return 'offline'
  return failureCount >= OFFLINE_FAILURE_THRESHOLD ? 'offline' : 'stale'
}

export { OFFLINE_FAILURE_THRESHOLD }

/**
 * Pure gate AnimatedNumber uses to decide whether a count-up transition
 * should run at all. Kept separate from the component so "no replay on an
 * unchanged value" and "no animation when reduced motion is requested" are
 * directly testable without rendering anything.
 */
export function shouldAnimateNumber({ previous, next, reducedMotion }) {
  if (reducedMotion) return false
  if (previous == null || next == null) return false
  return previous !== next
}
