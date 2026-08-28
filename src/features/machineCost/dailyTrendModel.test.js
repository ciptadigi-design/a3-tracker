import assert from 'node:assert/strict'
import test from 'node:test'
import { dailyClickTotal, dailyCostLabel, formatDailyClicks, hasDailyClickActivity, hasDailyTrendEvidence, normalizeDailyTrend } from './dailyTrendModel.js'

test('daily trend preserves absent cost separately from a known zero', () => {
  const rows = normalizeDailyTrend([
    { operational_date: '2026-08-27', daily_clicks: '1081', counter_readings: 1, known_daily_cost: null, cost_evidence_status: 'NONE' },
    { operational_date: '2026-08-28', daily_clicks: '0', counter_readings: 0, known_daily_cost: '0', component_events: 1, cost_evidence_status: 'COMPLETE' },
  ])
  assert.equal(rows[0].knownCost, null)
  assert.equal(rows[1].knownCost, 0)
  assert.equal(dailyCostLabel(rows[0], String), 'No known cost recorded')
  assert.equal(dailyCostLabel(rows[1], String), '0')
  assert.equal(hasDailyTrendEvidence(rows), true)
})

test('daily trend does not fabricate evidence for an empty generated date range', () => {
  const rows = normalizeDailyTrend([{ operational_date: '2026-08-01', daily_clicks: null, known_daily_cost: null, cost_evidence_status: 'NONE' }])
  assert.equal(hasDailyTrendEvidence(rows), false)
  assert.equal(hasDailyClickActivity(rows), false)
  assert.equal(dailyClickTotal(rows), 0)
})

test('daily click helpers reconcile positive activity without inventing missing usage', () => {
  const rows = normalizeDailyTrend([
    { operational_date: '2026-08-26', daily_clicks: '628', counter_readings: 1 },
    { operational_date: '2026-08-27', daily_clicks: '1081', counter_readings: 1 },
    { operational_date: '2026-08-28', daily_clicks: null, counter_readings: 0 },
  ])

  assert.equal(hasDailyClickActivity(rows), true)
  assert.equal(dailyClickTotal(rows), 1709)
  assert.equal(formatDailyClicks(rows[0].clicks), '628')
  assert.equal(formatDailyClicks(rows[1].clicks), '1,081')
})
