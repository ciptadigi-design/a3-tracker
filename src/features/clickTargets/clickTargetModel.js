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
  return lines.join('\n')
}

/**
 * Presentation for a Today/Week/Month summary card. `card` is the backend's
 * { actual, planned, achievement_percentage, variance } shape.
 */
export function periodCardPresentation(card) {
  if (!card || card.planned == null) return { actual: formatClicks(card?.actual ?? null), planned: 'Not configured', achievement: null, varianceLabel: null, tone: 'neutral' }
  const variance = card.variance
  const tone = variance == null ? 'neutral' : variance > 0 ? 'green' : variance < 0 ? 'warning' : 'blue'

  return {
    actual: formatClicks(card.actual),
    planned: formatClicks(card.planned),
    achievement: card.achievement_percentage == null ? null : formatPercentage(card.achievement_percentage),
    varianceLabel: variance == null ? null : formatSignedClicks(variance),
    tone,
  }
}

/**
 * Lightweight "Today" context for the Daily Click Performance header,
 * replacing the removed large Today KPI card. `todayRow` is today's entry
 * from normalizeDailyPerformance's output (or undefined if today isn't in
 * the currently loaded month). Uses the same target projection as the rest
 * of Overview - no separate Today target logic.
 */
export function todayContextPresentation(todayRow) {
  if (!todayRow) return { label: 'Today', detail: null, tone: 'neutral' }
  if (todayRow.calendarStatus === 'EXCLUDED') {
    return { label: 'Today · Excluded', detail: exclusionReasonLabel(todayRow.exclusionReason), tone: 'neutral' }
  }
  const actualCompact = formatCompactClicks(todayRow.actual ?? 0)
  if (todayRow.planned == null) {
    return { label: `Today · ${actualCompact} clicks`, detail: 'Target not configured', tone: 'neutral' }
  }
  const plannedCompact = formatCompactClicks(todayRow.planned)
  const variance = todayRow.variance
  const tone = variance == null ? 'neutral' : variance > 0 ? 'green' : variance < 0 ? 'warning' : 'blue'

  return {
    label: `Today · ${actualCompact} / ${plannedCompact} planned`,
    detail: variance == null ? null : formatCompactSignedClicks(variance),
    tone,
  }
}
