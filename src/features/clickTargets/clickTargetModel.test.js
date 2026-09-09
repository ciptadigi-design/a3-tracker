import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  dailyPerformanceTooltip,
  exclusionReasonLabel,
  formatClicks,
  formatPercentage,
  formatSignedClicks,
  hasAnyPlannedOrActual,
  normalizeDailyPerformance,
  periodCardPresentation,
  requiredPacePresentation,
  targetStatusPresentation,
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
