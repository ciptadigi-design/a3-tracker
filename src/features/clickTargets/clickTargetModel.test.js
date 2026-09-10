import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  comparisonPeriodLabel,
  comparisonPresentation,
  dailyPerformanceTooltip,
  exclusionReasonLabel,
  formatClicks,
  formatCompactClicks,
  formatCompactSignedClicks,
  formatPercentage,
  formatSignedClicks,
  hasAnyPlannedOrActual,
  normalizeDailyPerformance,
  periodCardPresentation,
  requiredPacePresentation,
  targetStatusPresentation,
  todayContextPresentation,
} from './clickTargetModel.js'

test('formatClicks renders Unavailable for null and never NaN/undefined', () => {
  assert.equal(formatClicks(null), 'Unavailable')
  assert.equal(formatClicks(undefined), 'Unavailable')
  assert.equal(formatClicks(0), '0')
  assert.equal(formatClicks(1234), '1,234')
})

test('formatSignedClicks prefixes a plus sign only for positive values', () => {
  assert.equal(formatSignedClicks(500), '+500')
  assert.equal(formatSignedClicks(-500), '-500')
  assert.equal(formatSignedClicks(0), '0')
  assert.equal(formatSignedClicks(null), 'Unavailable')
})

test('formatPercentage never leaks NaN', () => {
  assert.equal(formatPercentage(49.14), '49.1%')
  assert.equal(formatPercentage(null), 'Unavailable')
})

test('targetStatusPresentation covers every backend status', () => {
  for (const status of ['NOT_CONFIGURED', 'ON_TRACK', 'AHEAD', 'BEHIND', 'ACHIEVED', 'NO_ACTIVE_DAYS']) {
    const [label, tone] = targetStatusPresentation(status)
    assert.ok(label)
    assert.ok(tone)
  }
  assert.deepEqual(targetStatusPresentation('SOMETHING_UNKNOWN'), ['Unavailable', 'neutral'])
})

test('requiredPacePresentation renders achieved and no-active-days states truthfully', () => {
  assert.equal(requiredPacePresentation({ required_pace_status: 'ACHIEVED', required_daily_pace: 0 }).value, '0')
  assert.equal(requiredPacePresentation({ required_pace_status: 'NO_ACTIVE_DAYS_REMAINING', required_daily_pace: null }).value, '—')
  assert.equal(requiredPacePresentation({ required_pace_status: 'OK', required_daily_pace: 1200 }).value, '1,200')
  assert.equal(requiredPacePresentation(null).value, '—')
})

test('normalizeDailyPerformance preserves null (not zero) for missing evidence', () => {
  const rows = normalizeDailyPerformance([
    { date: '2026-09-01', calendar_status: 'ACTIVE', planned_clicks: 100, actual_clicks: null, variance: null, achievement_percentage: null },
    { date: '2026-09-02', calendar_status: 'EXCLUDED', exclusion_reason: 'store_closed', planned_clicks: 0, actual_clicks: null },
  ])
  assert.equal(rows[0].actual, null)
  assert.equal(rows[0].planned, 100)
  assert.equal(rows[1].calendarStatus, 'EXCLUDED')
  assert.equal(rows[1].exclusionReason, 'store_closed')
})

test('normalizeDailyPerformance handles an empty array without throwing', () => {
  assert.deepEqual(normalizeDailyPerformance(undefined), [])
  assert.deepEqual(normalizeDailyPerformance([]), [])
})

test('hasAnyPlannedOrActual is false only when every row is fully empty', () => {
  assert.equal(hasAnyPlannedOrActual([{ actual: null, planned: null }]), false)
  assert.equal(hasAnyPlannedOrActual([{ actual: null, planned: 0 }]), true)
  assert.equal(hasAnyPlannedOrActual([{ actual: 10, planned: null }]), true)
})

test('exclusionReasonLabel maps known reasons and falls back gracefully', () => {
  assert.equal(exclusionReasonLabel('store_closed'), 'Store Closed')
  assert.equal(exclusionReasonLabel('planned_maintenance'), 'Planned Maintenance')
  assert.equal(exclusionReasonLabel(null), 'Excluded')
  assert.equal(exclusionReasonLabel('unknown_future_reason'), 'unknown_future_reason')
})

test('dailyPerformanceTooltip never implies failure for excluded dates', () => {
  const tooltip = dailyPerformanceTooltip({ calendarStatus: 'EXCLUDED', exclusionReason: 'religious_holiday' })
  assert.equal(tooltip, 'Excluded: Religious Holiday')
  assert.ok(!tooltip.toLowerCase().includes('missed'))
})

test('dailyPerformanceTooltip includes variance and achievement for active dates', () => {
  const tooltip = dailyPerformanceTooltip({ calendarStatus: 'ACTIVE', actual: 3891, planned: 3000, variance: 891, achievementPercentage: 129.7 })
  assert.match(tooltip, /Actual: 3,891/)
  assert.match(tooltip, /Target: 3,000/)
  assert.match(tooltip, /Variance: \+891/)
  assert.match(tooltip, /Achievement: 129.7%/)
})

test('periodCardPresentation renders a truthful not-configured state without fake zeros', () => {
  const presentation = periodCardPresentation({ actual: 0, planned: null, achievement_percentage: null, variance: null })
  assert.equal(presentation.planned, 'Not configured')
  assert.equal(presentation.tone, 'neutral')
})

test('periodCardPresentation tone reflects ahead/behind/on-track variance', () => {
  assert.equal(periodCardPresentation({ actual: 100, planned: 90, variance: 10 }).tone, 'green')
  assert.equal(periodCardPresentation({ actual: 80, planned: 90, variance: -10 }).tone, 'warning')
  assert.equal(periodCardPresentation({ actual: 90, planned: 90, variance: 0 }).tone, 'blue')
})

test('periodCardPresentation never leaks NaN/undefined strings', () => {
  const presentation = periodCardPresentation(null)
  assert.equal(presentation.actual, 'Unavailable')
  assert.equal(presentation.planned, 'Not configured')
})

test('formatCompactClicks matches the M2.13.2 compact notation table exactly', () => {
  const cases = [
    [0, '0'], [946, '946'], [999, '999'],
    [1000, '1K'], [1200, '1.2K'], [1249, '1.2K'], [1250, '1.3K'],
    [1667, '1.7K'], [3636, '3.6K'], [10000, '10K'], [12500, '12.5K'],
    [20038, '20K'], [100000, '100K'], [1200000, '1.2M'],
  ]
  for (const [input, expected] of cases) assert.equal(formatCompactClicks(input), expected, `formatCompactClicks(${input})`)
})

test('formatCompactClicks never produces a trailing .0', () => {
  assert.equal(formatCompactClicks(1000), '1K')
  assert.equal(formatCompactClicks(10000), '10K')
  assert.equal(formatCompactClicks(2000000), '2M')
  assert.ok(!formatCompactClicks(1000).includes('.0'))
})

test('formatCompactClicks uses "." not a locale comma for the decimal separator', () => {
  assert.equal(formatCompactClicks(1200), '1.2K')
  assert.ok(!formatCompactClicks(1200).includes(','))
})

test('formatCompactClicks returns null (never a fabricated number) for null/undefined/non-finite', () => {
  assert.equal(formatCompactClicks(null), null)
  assert.equal(formatCompactClicks(undefined), null)
  assert.equal(formatCompactClicks(NaN), null)
})

test('formatCompactSignedClicks mirrors the exact compact table with a sign', () => {
  assert.equal(formatCompactSignedClicks(2200), '+2.2K')
  assert.equal(formatCompactSignedClicks(-2200), '-2.2K')
  assert.equal(formatCompactSignedClicks(0), '0')
  assert.equal(formatCompactSignedClicks(null), null)
})

test('todayContextPresentation renders the excluded state without implying failure', () => {
  const presentation = todayContextPresentation({ calendarStatus: 'EXCLUDED', exclusionReason: 'planned_maintenance' })
  assert.equal(presentation.label, 'Today · Excluded')
  assert.equal(presentation.detail, 'Planned Maintenance')
})

test('todayContextPresentation renders the no-target-configured state truthfully', () => {
  const presentation = todayContextPresentation({ calendarStatus: 'ACTIVE', actual: 0, planned: null, variance: null })
  assert.equal(presentation.label, 'Today · 0 clicks')
  assert.equal(presentation.detail, 'Target not configured')
})

test('todayContextPresentation renders actual/planned/variance in compact notation using the same target projection', () => {
  const presentation = todayContextPresentation({ calendarStatus: 'ACTIVE', actual: 3891, planned: 1667, variance: 2224 })
  assert.equal(presentation.label, 'Today · 3.9K / 1.7K planned')
  assert.equal(presentation.detail, '+2.2K')
  assert.equal(presentation.tone, 'green')
})

test('todayContextPresentation falls back to a neutral placeholder when today has no row at all', () => {
  const presentation = todayContextPresentation(undefined)
  assert.equal(presentation.label, 'Today')
  assert.equal(presentation.detail, null)
})

// ---- M2.14: period-over-period comparison presentation --------------------

test('comparisonPeriodLabel renders a single date for a same-day comparison', () => {
  const comparison = { previous_period: { start_date: '2026-08-10', end_date: '2026-08-10' } }
  assert.equal(comparisonPeriodLabel(comparison), 'Aug 10')
})

test('comparisonPeriodLabel renders a date range for a week comparison', () => {
  const comparison = { previous_period: { start_date: '2026-08-07', end_date: '2026-08-13' } }
  assert.equal(comparisonPeriodLabel(comparison), 'Aug 7–13')
})

test('comparisonPeriodLabel renders "MTD" for an in-progress month comparison', () => {
  const comparison = { previous_period: { start_date: '2026-08-01', end_date: '2026-08-10' } }
  assert.equal(comparisonPeriodLabel(comparison, { monthToDate: true }), 'Aug MTD')
})

test('comparisonPeriodLabel renders just the month name for a full calendar month', () => {
  const comparison = { previous_period: { start_date: '2026-08-01', end_date: '2026-08-31' } }
  assert.equal(comparisonPeriodLabel(comparison), 'Aug')
})

test('comparisonPeriodLabel returns null when there is no previous period at all', () => {
  assert.equal(comparisonPeriodLabel(null), null)
  assert.equal(comparisonPeriodLabel({ available: false, previous_period: null }), null)
})

test('comparisonPresentation formats normal growth with a signed percentage and up arrow', () => {
  const presentation = comparisonPresentation({
    available: true, comparison_status: 'OK', direction: 'UP',
    previous_period: { start_date: '2026-08-10', end_date: '2026-08-10', clicks: 2120 },
    delta_clicks: 330, delta_percentage: 15.6,
  })
  assert.equal(presentation.available, true)
  assert.equal(presentation.tone, 'green')
  assert.equal(presentation.arrow, '↑')
  assert.equal(presentation.signedPercentageText, '+15.6%')
  assert.equal(presentation.magnitudeText, '15.6%')
  assert.equal(presentation.deltaClicksText, '+330')
  assert.equal(presentation.previousValueText, 'Previous 2,120')
  assert.equal(presentation.periodLabel, 'Aug 10')
})

test('comparisonPresentation formats a decline with a down arrow and negative sign', () => {
  const presentation = comparisonPresentation({
    available: true, comparison_status: 'OK', direction: 'DOWN',
    previous_period: { start_date: '2026-08-07', end_date: '2026-08-13', clicks: 6302 },
    delta_clicks: -517, delta_percentage: -8.2,
  })
  assert.equal(presentation.tone, 'warning')
  assert.equal(presentation.arrow, '↓')
  assert.equal(presentation.signedPercentageText, '-8.2%')
  assert.equal(presentation.deltaClicksText, '-517')
})

test('comparisonPresentation never renders Infinity/NaN for base-zero growth', () => {
  const presentation = comparisonPresentation({
    available: true, comparison_status: 'BASE_ZERO', direction: 'UP',
    previous_period: { start_date: '2026-08-10', end_date: '2026-08-10', clicks: 0 },
    delta_clicks: 500, delta_percentage: null,
  })
  assert.equal(presentation.available, true)
  assert.equal(presentation.isNewActivity, true)
  assert.equal(presentation.signedPercentageText, null)
  assert.ok(!JSON.stringify(presentation).includes('Infinity'))
  assert.ok(!JSON.stringify(presentation).includes('NaN'))
})

test('comparisonPresentation renders a flat state truthfully for zero vs zero', () => {
  const presentation = comparisonPresentation({
    available: true, comparison_status: 'OK', direction: 'FLAT',
    previous_period: { start_date: '2026-08-10', end_date: '2026-08-10', clicks: 0 },
    delta_clicks: 0, delta_percentage: 0,
  })
  assert.equal(presentation.signedPercentageText, '0.0%')
  assert.equal(presentation.tone, 'blue')
})

test('comparisonPresentation reports unavailable without fabricating a zero previous value', () => {
  const presentation = comparisonPresentation({ available: false, comparison_status: 'UNAVAILABLE', direction: 'UNAVAILABLE', previous_period: null, delta_clicks: null, delta_percentage: null })
  assert.equal(presentation.available, false)
  assert.equal(presentation.previousValue, null)
  assert.equal(presentation.unavailableText, 'Previous month unavailable')
})

test('comparisonPresentation treats a missing/null comparison the same as unavailable', () => {
  assert.equal(comparisonPresentation(null).available, false)
  assert.equal(comparisonPresentation(undefined).available, false)
})

test('periodCardPresentation surfaces the comparison alongside the existing card fields', () => {
  const presentation = periodCardPresentation({
    actual: 5785, planned: 11669, achievement_percentage: 49.6, variance: -5884,
    comparison: {
      available: true, comparison_status: 'OK', direction: 'DOWN',
      previous_period: { start_date: '2026-08-07', end_date: '2026-08-13', clicks: 6302 },
      delta_clicks: -517, delta_percentage: -8.2,
    },
  })
  assert.equal(presentation.actual, '5,785')
  assert.equal(presentation.comparison.available, true)
  assert.equal(presentation.comparison.periodLabel, 'Aug 7–13')
  assert.equal(presentation.comparison.signedPercentageText, '-8.2%')
})

test('periodCardPresentation labels an in-progress month comparison as MTD', () => {
  const presentation = periodCardPresentation({
    actual: 20038, planned: 50000, achievement_percentage: 40.1, variance: -29962,
    comparison: {
      available: true, comparison_status: 'OK', direction: 'UP',
      previous_period: { start_date: '2026-08-01', end_date: '2026-08-10', clicks: 17828 },
      delta_clicks: 2210, delta_percentage: 12.4,
    },
  }, { monthToDate: true })
  assert.equal(presentation.comparison.periodLabel, 'Aug MTD')
})

test('todayContextPresentation appends the previous-month comparison when available', () => {
  const presentation = todayContextPresentation({
    calendarStatus: 'ACTIVE', actual: 2450, planned: 1667, variance: 783,
    previousMonth: {
      available: true, comparison_status: 'OK', direction: 'UP',
      previous_period: { start_date: '2026-08-10', end_date: '2026-08-10', clicks: 2120 },
      delta_clicks: 330, delta_percentage: 15.6,
    },
  })
  assert.equal(presentation.label, 'Today · 2.5K / 1.7K planned · +15.6% vs Aug 10')
})

test('todayContextPresentation renders base-zero growth as "New activity", never a percentage', () => {
  const presentation = todayContextPresentation({
    calendarStatus: 'ACTIVE', actual: 500, planned: 1667, variance: -1167,
    previousMonth: {
      available: true, comparison_status: 'BASE_ZERO', direction: 'UP',
      previous_period: { start_date: '2026-08-10', end_date: '2026-08-10', clicks: 0 },
      delta_clicks: 500, delta_percentage: null,
    },
  })
  assert.match(presentation.label, /New activity vs Aug 10$/)
  assert.ok(!presentation.label.includes('Infinity'))
})

test('todayContextPresentation omits the comparison suffix entirely when unavailable', () => {
  const presentation = todayContextPresentation({
    calendarStatus: 'ACTIVE', actual: 2450, planned: 1667, variance: 783,
    previousMonth: { available: false, comparison_status: 'UNAVAILABLE', direction: 'UNAVAILABLE', previous_period: null, delta_clicks: null, delta_percentage: null },
  })
  assert.equal(presentation.label, 'Today · 2.5K / 1.7K planned')
})

test('dailyPerformanceTooltip appends an exact previous-month block when available', () => {
  const tooltip = dailyPerformanceTooltip({
    calendarStatus: 'ACTIVE', actual: 2450, planned: 1667, variance: 783, achievementPercentage: 147,
    previousMonth: {
      available: true, comparison_status: 'OK', direction: 'UP',
      previous_period: { start_date: '2026-08-10', end_date: '2026-08-10', clicks: 2120 },
      delta_clicks: 330, delta_percentage: 15.6,
    },
  })
  assert.match(tooltip, /PREVIOUS MONTH/)
  assert.match(tooltip, /Aug 10/)
  assert.match(tooltip, /2,120 clicks/)
  assert.match(tooltip, /\+330 · \+15\.6%/)
})

test('dailyPerformanceTooltip has no previous-month section when the row carries none', () => {
  const tooltip = dailyPerformanceTooltip({ calendarStatus: 'ACTIVE', actual: 100, planned: 100, variance: 0, achievementPercentage: 100, previousMonth: null })
  assert.ok(!tooltip.includes('PREVIOUS MONTH'))
})

test('dailyPerformanceTooltip states unavailable truthfully instead of omitting or faking zero', () => {
  const tooltip = dailyPerformanceTooltip({
    calendarStatus: 'ACTIVE', actual: 100, planned: 100, variance: 0, achievementPercentage: 100,
    previousMonth: { available: false, comparison_status: 'UNAVAILABLE', direction: 'UNAVAILABLE', previous_period: null, delta_clicks: null, delta_percentage: null },
  })
  assert.match(tooltip, /PREVIOUS MONTH/)
  assert.match(tooltip, /Previous month unavailable/)
})
