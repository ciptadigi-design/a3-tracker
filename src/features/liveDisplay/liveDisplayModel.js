import { normalizeDailyPerformance, requiredPacePresentation, targetStatusPresentation } from '../clickTargets/clickTargetModel.js'
import { resolveMachineCostPeriod } from '../machineCost/machineCostPeriods.js'

/**
 * V1.1: the backend already orders `loadCounterHistory` newest-first
 * (`ORDER BY observed_at DESC, created_at DESC, id DESC` - see
 * OperationsController::counters), so this used to just trust array
 * position. Production acceptance found a Recent Activity list that looked
 * out of order (a 16:49 reading displayed *between* two readings showing
 * 20:0x, against a ~17:13 header clock) - display-only, since
 * `observed_at` round-trips as an unambiguous UTC `...Z` instant
 * (`CounterReading::$casts`, Carbon's default JSON serialization always
 * normalizes to UTC) and the SQL order is already authoritative on that
 * same column. The actual cause was display formatting, not sort order: the
 * row only ever showed a bare "HH:mm", so once the trailing page of up to
 * 50 readings legitimately spans more than one calendar day, two same-clock
 * times from different days look "out of order" with no date to disambiguate
 * them. Fixed here two ways, deliberately redundant with the backend rather
 * than dependent on it: (1) explicitly re-sort by parsed `observed_at`
 * descending before trimming, so the displayed order is deterministic from
 * the authoritative timestamp even if a future API/ordering regression ever
 * returns an unsorted page, and (2) `formatActivityTimestamp` below always
 * renders a short date alongside the time.
 */
function sortByObservedAtDescending(readings) {
  return [...readings].sort((a, b) => new Date(b.observed_at).getTime() - new Date(a.observed_at).getTime())
}

/**
 * Most recent effective counter readings, newest first, trimmed to the TV's
 * fixed display count. Includes corrected/voided rows in the input (needed
 * so CorrectCounterDialog can act on them elsewhere) - the TV only ever
 * shows the effective lineage, same filter CounterHistory.jsx already
 * applies for "latest effective".
 */
export function recentActivityRows(history, limit = 5) {
  return sortByObservedAtDescending((history ?? []).filter((reading) => reading.status === 'effective'))
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
  const effective = (history ?? []).filter((reading) => reading.status === 'effective')
  return sortByObservedAtDescending(effective)[0] ?? null
}

const activityDateFormatter = (timezone) => new Intl.DateTimeFormat('en-GB', { timeZone: timezone, month: 'short', day: 'numeric' })
const activityTimeFormatter = (timezone) => new Intl.DateTimeFormat('en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit' })

/**
 * "3 Oct, 16:49" - always includes the date, not just "16:49", so entries
 * spanning more than one calendar day (the trailing page can hold several
 * days of readings) never look ambiguously out of order on a glance-only TV.
 */
export function formatActivityTimestamp(observedAt, timezone) {
  const date = new Date(observedAt)
  return `${activityDateFormatter(timezone).format(date)}, ${activityTimeFormatter(timezone).format(date)}`
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
