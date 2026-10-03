import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  deriveStatusOnFailure,
  deriveStatusOnSuccess,
  isCounterHistoryEmpty,
  latestEffectiveReading,
  recentActivityRows,
  shouldAnimateNumber,
  targetSummaryFromProjection,
  todayActualFromProjection,
} from './liveDisplayModel.js'

const effective = (overrides) => ({ status: 'effective', reading_id: 'r1', reading_value: 100, usage: 10, observed_at: '2026-10-03T08:00:00Z', shift_code: 'S1', operator_name_snapshot: 'Angga', ...overrides })

test('recentActivityRows keeps only effective readings, newest first, trimmed to the limit', () => {
  const history = [
    effective({ reading_id: 'r3', reading_value: 300 }),
    { status: 'voided', reading_id: 'void', reading_value: 999 },
    effective({ reading_id: 'r2', reading_value: 200 }),
    effective({ reading_id: 'r1', reading_value: 100 }),
  ]
  const rows = recentActivityRows(history, 2)
  assert.deepEqual(rows.map((row) => row.id), ['r3', 'r2'])
  assert.equal(rows[0].counter, 300)
})

test('recentActivityRows omits operator/shift gracefully when unavailable rather than inventing them', () => {
  const rows = recentActivityRows([effective({ shift_code: null, operator_name_snapshot: null })])
  assert.equal(rows[0].shiftCode, null)
  assert.equal(rows[0].operatorName, null)
})

test('latestEffectiveReading skips corrected/voided rows ahead of the latest effective one', () => {
  const history = [{ status: 'voided', reading_id: 'void' }, effective({ reading_id: 'latest' })]
  assert.equal(latestEffectiveReading(history).reading_id, 'latest')
  assert.equal(latestEffectiveReading([]), null)
  assert.equal(latestEffectiveReading([{ status: 'voided' }]), null)
})

test('isCounterHistoryEmpty is true only when no effective reading exists', () => {
  assert.equal(isCounterHistoryEmpty([]), true)
  assert.equal(isCounterHistoryEmpty([{ status: 'voided' }]), true)
  assert.equal(isCounterHistoryEmpty([effective()]), false)
})

test('todayActualFromProjection reads the projection daily row matching today, never recomputing it', () => {
  const todayKey = new Date().toISOString().slice(0, 10)
  const projection = { period: { timezone: 'UTC' }, daily: [{ date: todayKey, actual_clicks: 1243 }] }
  assert.equal(todayActualFromProjection(projection, 'UTC'), 1243)
  assert.equal(todayActualFromProjection(null, 'UTC'), null)
  assert.equal(todayActualFromProjection({ period: { timezone: 'UTC' }, daily: [] }, 'UTC'), null)
})

test('targetSummaryFromProjection maps backend fields without altering their values', () => {
  const projection = {
    actual: 48431,
    period_target: 50000,
    achievement_percentage: 96.9,
    target_status: 'AHEAD',
    pace_variance: 6761,
    expected_by_today: 41670,
    remaining: 1569,
    required_pace_status: 'OK',
    required_pace: 262,
  }
  const summary = targetSummaryFromProjection(projection)
  assert.equal(summary.actual, 48431)
  assert.equal(summary.periodTarget, 50000)
  assert.equal(summary.statusLabel, 'Ahead')
  assert.equal(summary.statusTone, 'green')
  assert.equal(summary.requiredPaceValue, '262')
  assert.equal(summary.notConfigured, false)
})

test('targetSummaryFromProjection flags NOT_CONFIGURED without fabricating numbers', () => {
  const summary = targetSummaryFromProjection({ target_status: 'NOT_CONFIGURED' })
  assert.equal(summary.notConfigured, true)
})

test('targetSummaryFromProjection returns null when there is no projection at all', () => {
  assert.equal(targetSummaryFromProjection(null), null)
})

test('deriveStatusOnSuccess is EMPTY only when history has no effective reading', () => {
  assert.equal(deriveStatusOnSuccess({ historyEmpty: true }), 'empty')
  assert.equal(deriveStatusOnSuccess({ historyEmpty: false }), 'fresh')
})

test('deriveStatusOnFailure: no prior data means OFFLINE immediately', () => {
  assert.equal(deriveStatusOnFailure({ hasData: false, failureCount: 1 }), 'offline')
})

test('deriveStatusOnFailure: prior data degrades to STALE before OFFLINE', () => {
  assert.equal(deriveStatusOnFailure({ hasData: true, failureCount: 1 }), 'stale')
  assert.equal(deriveStatusOnFailure({ hasData: true, failureCount: 2 }), 'stale')
  assert.equal(deriveStatusOnFailure({ hasData: true, failureCount: 3 }), 'offline')
  assert.equal(deriveStatusOnFailure({ hasData: true, failureCount: 9 }), 'offline')
})

test('shouldAnimateNumber only fires for an actual value change, not every render', () => {
  assert.equal(shouldAnimateNumber({ previous: 100, next: 200, reducedMotion: false }), true)
  assert.equal(shouldAnimateNumber({ previous: 100, next: 100, reducedMotion: false }), false, 'a poll returning the same value must not replay the transition')
  assert.equal(shouldAnimateNumber({ previous: null, next: 100, reducedMotion: false }), false, 'no previous value means first render, not a transition')
  assert.equal(shouldAnimateNumber({ previous: 100, next: null, reducedMotion: false }), false)
})

test('shouldAnimateNumber respects prefers-reduced-motion', () => {
  assert.equal(shouldAnimateNumber({ previous: 100, next: 200, reducedMotion: true }), false)
})
