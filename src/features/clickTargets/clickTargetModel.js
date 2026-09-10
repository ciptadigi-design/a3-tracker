const clicksFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
const percentFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 })

export function formatClicks(value) {
  return value == null ? 'Unavailable' : clicksFormatter.format(Number(value))
}

export function formatSignedClicks(value) {
  if (value == null) return 'Unavailable'
  const number = Number(value)
  const sign = number > 0 ? '+' : ''
  return `${sign}${clicksFormatter.format(number)}`
}

export function formatPercentage(value) {
  return value == null ? 'Unavailable' : `${percentFormatter.format(Number(value))}%`
}

const compactUnits = [[1_000_000_000, 'B'], [1_000_000, 'M'], [1_000, 'K']]

/**
 * Compact visualization-only notation ("1.2K", "10K", "1.2M") for chart bar
 * labels. Deliberately separate from formatClicks (exact, comma-grouped) -
 * this is presentation-only and must never replace an authoritative value.
 * Uses "." as the decimal separator regardless of locale to avoid the
 * ambiguity a locale-grouping comma would create right next to a "K"/"M"
 * suffix, and always rounds to at most one decimal with no trailing ".0".
 */
export function formatCompactClicks(value) {
  if (value == null) return null
  const number = Number(value)
  if (!Number.isFinite(number)) return null
  const sign = number < 0 ? '-' : ''
  const abs = Math.abs(number)
  for (const [threshold, suffix] of compactUnits) {
    if (abs >= threshold) {
      const rounded = Math.round((abs / threshold) * 10) / 10
      const text = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1)
      return `${sign}${text}${suffix}`
    }
  }
  return `${sign}${Math.round(abs)}`
}

export function formatCompactSignedClicks(value) {
  if (value == null) return null
  const number = Number(value)
  const sign = number > 0 ? '+' : number < 0 ? '-' : ''
  return `${sign}${formatCompactClicks(Math.abs(number))}`
}

const targetStatusPresentationMap = {
  NOT_CONFIGURED: ['Not configured', 'neutral'],
  ON_TRACK: ['On track', 'blue'],
  AHEAD: ['Ahead', 'green'],
  BEHIND: ['Behind', 'warning'],
  ACHIEVED: ['Achieved', 'green'],
  NO_ACTIVE_DAYS: ['No active days', 'neutral'],
}

export function targetStatusPresentation(status) {
  return targetStatusPresentationMap[status] ?? ['Unavailable', 'neutral']
}

const requiredPaceStatusPresentationMap = {
  OK: 'per remaining active day',
  ACHIEVED: 'Target already achieved',
  NO_ACTIVE_DAYS_REMAINING: 'No active days remain this month',
  NOT_CONFIGURED: 'No target configured',
}

export function requiredPacePresentation(projection) {
  const status = projection?.required_pace_status
  if (status === 'OK') return { value: formatClicks(projection.required_daily_pace), hint: requiredPaceStatusPresentationMap.OK }
  return { value: status === 'ACHIEVED' ? formatClicks(0) : '—', hint: requiredPaceStatusPresentationMap[status] ?? 'Unavailable' }
}

/**
 * Normalizes the backend projection's `daily` rows into the exact shape the
 * grouped Actual/Target bar chart needs. Values stay `null` (never 0) when
 * there is genuinely no evidence, so the chart can distinguish "no data" from
 * "zero clicks" the same way the existing Daily Click Trend does.
 */
export function normalizeDailyPerformance(daily) {
  return (daily ?? []).map((row) => ({
    date: row.date,
    calendarStatus: row.calendar_status,
    exclusionReason: row.exclusion_reason ?? null,
    exclusionNotes: row.exclusion_notes ?? null,
    actual: row.actual_clicks == null ? null : Number(row.actual_clicks),
    planned: row.planned_clicks == null ? null : Number(row.planned_clicks),
    variance: row.variance == null ? null : Number(row.variance),
    achievementPercentage: row.achievement_percentage == null ? null : Number(row.achievement_percentage),
    previousMonth: row.previous_month ?? null,
  }))
}

export function hasAnyPlannedOrActual(rows) {
  return rows.some((row) => row.actual != null || row.planned != null)
}

export const exceptionTypes = [
  ['family_gathering', 'Family Gathering'],
  ['store_closed', 'Store Closed'],
  ['religious_holiday', 'Religious Holiday'],
  ['planned_maintenance', 'Planned Maintenance'],
  ['special_event', 'Special Event'],
  ['other', 'Other'],
]

const exclusionReasonLabels = Object.fromEntries(exceptionTypes)

export function exclusionReasonLabel(reason) {
  return exclusionReasonLabels[reason] ?? reason ?? 'Excluded'
}

export function dailyPerformanceTooltip(row) {
  if (row.calendarStatus === 'EXCLUDED') return `Excluded: ${exclusionReasonLabel(row.exclusionReason)}`
  const lines = [`Actual: ${formatClicks(row.actual)}`, `Target: ${formatClicks(row.planned)}`]
  if (row.variance != null) lines.push(`Variance: ${formatSignedClicks(row.variance)}`)
  if (row.achievementPercentage != null) lines.push(`Achievement: ${formatPercentage(row.achievementPercentage)}`)

  const comparison = comparisonPresentation(row.previousMonth)
  if (comparison.available) {
    lines.push('', 'PREVIOUS MONTH', comparison.periodLabel ?? '', `${formatClicks(comparison.previousValue)} clicks`)
    lines.push(comparison.isNewActivity ? 'New activity' : `${comparison.deltaClicksText} · ${comparison.signedPercentageText}`)
  } else if (row.previousMonth) {
    // A comparison object was returned (this date was eligible) but no
    // previous-month evidence/valid shifted date exists - say so truthfully
    // rather than omitting the section entirely or implying zero.
    lines.push('', 'PREVIOUS MONTH', 'Previous month unavailable')
  }

  return lines.join('\n')
}

const comparisonToneByDirection = { UP: 'green', DOWN: 'warning', FLAT: 'blue', UNAVAILABLE: 'neutral' }
const comparisonArrowByDirection = { UP: '↑', DOWN: '↓', FLAT: '', UNAVAILABLE: '' }
const comparisonPercentFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

// Comparison percentages always keep one decimal (matches the M2.14 mockups:
// "+15.6%", "-8.2%", "0.0%") even at exactly zero - unlike formatPercentage's
// general-purpose trailing-zero trim used elsewhere (achievement, etc.).
function formatComparisonPercentage(value) {
  return `${comparisonPercentFormatter.format(Math.abs(value))}%`
}

const monthDayFormatter = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' })
const dayOnlyFormatter = new Intl.DateTimeFormat('en-US', { day: 'numeric', timeZone: 'UTC' })
const monthOnlyFormatter = new Intl.DateTimeFormat('en-US', { month: 'short', timeZone: 'UTC' })

function parseDateOnlyUTC(dateStr) {
  const [year, month, day] = dateStr.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day))
}

function isLastDayOfMonthUTC(dateStr) {
  const date = parseDateOnlyUTC(dateStr)
  const next = new Date(date.getTime())
  next.setUTCDate(date.getUTCDate() + 1)
  return next.getUTCMonth() !== date.getUTCMonth()
}

/**
 * Human-readable label for a comparison's previous-month period: a single
 * date ("Aug 10"), a date range ("Aug 7–13"), or - only when the previous
 * period is a full calendar month - just the month name ("Aug"), matching
 * M2.14's presentation convention. `monthToDate` renders "Aug MTD" instead
 * for an in-progress current-month comparison.
 */
export function comparisonPeriodLabel(comparison, { monthToDate = false } = {}) {
  const previous = comparison?.previous_period
  if (!previous?.start_date) return null
  if (previous.start_date === previous.end_date) return monthDayFormatter.format(parseDateOnlyUTC(previous.start_date))
  if (monthToDate) return `${monthOnlyFormatter.format(parseDateOnlyUTC(previous.start_date))} MTD`
  if (previous.start_date.endsWith('-01') && isLastDayOfMonthUTC(previous.end_date) && previous.start_date.slice(0, 7) === previous.end_date.slice(0, 7)) {
    return monthOnlyFormatter.format(parseDateOnlyUTC(previous.start_date))
  }
  // Same-month range: "Aug 7–13" (end date without a repeated month name).
  // Cross-month range: full "Aug 28–Sep 3" on both ends.
  const sameMonth = previous.start_date.slice(0, 7) === previous.end_date.slice(0, 7)
  const endLabel = sameMonth ? dayOnlyFormatter.format(parseDateOnlyUTC(previous.end_date)) : monthDayFormatter.format(parseDateOnlyUTC(previous.end_date))

  return `${monthDayFormatter.format(parseDateOnlyUTC(previous.start_date))}–${endLabel}`
}

/**
 * Shared M2.14 formatter for a backend comparison object (see
 * PeriodComparisonService). Backend math stays authoritative here - this
 * only formats it. Never renders Infinity/NaN: BASE_ZERO growth becomes
 * "New activity" text with a null percentage, and an unavailable comparison
 * (missing historical evidence, or an invalid shifted calendar date) is
 * reported as unavailable rather than a fabricated zero.
 */
export function comparisonPresentation(comparison, { monthToDate = false } = {}) {
  if (!comparison || !comparison.available) {
    return {
      available: false,
      tone: 'neutral',
      direction: 'UNAVAILABLE',
      arrow: '',
      isNewActivity: false,
      magnitudeText: null,
      signedPercentageText: null,
      deltaClicksText: null,
      previousValue: null,
      previousValueText: null,
      periodLabel: null,
      unavailableText: 'Previous month unavailable',
    }
  }

  const direction = comparison.direction
  const isNewActivity = comparison.comparison_status === 'BASE_ZERO'
  const deltaPercentage = comparison.delta_percentage
  const previousValue = comparison.previous_period?.clicks ?? null

  return {
    available: true,
    tone: comparisonToneByDirection[direction] ?? 'neutral',
    direction,
    arrow: comparisonArrowByDirection[direction] ?? '',
    isNewActivity,
    magnitudeText: deltaPercentage == null ? null : formatComparisonPercentage(deltaPercentage),
    signedPercentageText: deltaPercentage == null ? null : `${deltaPercentage > 0 ? '+' : deltaPercentage < 0 ? '-' : ''}${formatComparisonPercentage(deltaPercentage)}`,
    deltaClicksText: comparison.delta_clicks == null ? null : formatSignedClicks(comparison.delta_clicks),
    previousValue,
    previousValueText: previousValue == null ? null : `Previous ${formatClicks(previousValue)}`,
    periodLabel: comparisonPeriodLabel(comparison, { monthToDate }),
    unavailableText: null,
  }
}

/**
 * Presentation for a Today/Week/Month summary card. `card` is the backend's
 * { actual, planned, achievement_percentage, variance, comparison? } shape.
 * `monthToDate` labels the comparison period "vs Aug MTD" instead of "vs Aug"
 * for an in-progress current month (see This Month card).
 */
export function periodCardPresentation(card, { monthToDate = false } = {}) {
  if (!card || card.planned == null) return { actual: formatClicks(card?.actual ?? null), planned: 'Not configured', achievement: null, varianceLabel: null, tone: 'neutral', comparison: comparisonPresentation(card?.comparison, { monthToDate }) }
  const variance = card.variance
  const tone = variance == null ? 'neutral' : variance > 0 ? 'green' : variance < 0 ? 'warning' : 'blue'

  return {
    actual: formatClicks(card.actual),
    planned: formatClicks(card.planned),
    achievement: card.achievement_percentage == null ? null : formatPercentage(card.achievement_percentage),
    varianceLabel: variance == null ? null : formatSignedClicks(variance),
    tone,
    comparison: comparisonPresentation(card.comparison, { monthToDate }),
  }
}

/**
 * Lightweight "Today" context for the Daily Click Performance header,
 * replacing the removed large Today KPI card. `todayRow` is today's entry
 * from normalizeDailyPerformance's output (or undefined if today isn't in
 * the currently loaded month). Uses the same target projection as the rest
 * of Overview - no separate Today target logic. Appends a same-date
 * previous-month comparison when available (M2.14); silently omits it
 * (never fabricates 0%/Infinity%) when there is no usable previous evidence.
 */
export function todayContextPresentation(todayRow) {
  if (!todayRow) return { label: 'Today', detail: null, tone: 'neutral' }
  if (todayRow.calendarStatus === 'EXCLUDED') {
    return { label: 'Today · Excluded', detail: exclusionReasonLabel(todayRow.exclusionReason), tone: 'neutral' }
  }

  const comparison = comparisonPresentation(todayRow.previousMonth)
  const comparisonSuffix = !comparison.available
    ? ''
    : comparison.isNewActivity
      ? ` · New activity vs ${comparison.periodLabel}`
      : comparison.signedPercentageText
        ? ` · ${comparison.signedPercentageText} vs ${comparison.periodLabel}`
        : ''

  const actualCompact = formatCompactClicks(todayRow.actual ?? 0)
  if (todayRow.planned == null) {
    return { label: `Today · ${actualCompact} clicks${comparisonSuffix}`, detail: 'Target not configured', tone: 'neutral' }
  }
  const plannedCompact = formatCompactClicks(todayRow.planned)
  const variance = todayRow.variance
  const tone = variance == null ? 'neutral' : variance > 0 ? 'green' : variance < 0 ? 'warning' : 'blue'

  return {
    label: `Today · ${actualCompact} / ${plannedCompact} planned${comparisonSuffix}`,
    detail: variance == null ? null : formatCompactSignedClicks(variance),
    tone,
  }
}
