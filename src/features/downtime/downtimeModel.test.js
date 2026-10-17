import assert from 'node:assert/strict'
import test from 'node:test'
import { causeLabel, currentMonthPeriod, elapsedMinutesBetween, formatAvailabilityPercent, formatElapsedMinutes, formatProductionDowntimeMinutes, mapDowntimeError, newClientRequestId } from './downtimeModel.js'

test('elapsed minutes floors to whole minutes and never goes negative', () => {
  assert.equal(elapsedMinutesBetween('2026-01-01T00:00:00Z', '2026-01-01T02:00:00Z'), 120)
  assert.equal(elapsedMinutesBetween('2026-01-01T00:00:30Z', '2026-01-01T00:01:00Z'), 0)
  assert.equal(elapsedMinutesBetween('2026-01-01T02:00:00Z', '2026-01-01T00:00:00Z'), 0)
})

test('formatElapsedMinutes renders days/hours/minutes only when non-zero', () => {
  assert.equal(formatElapsedMinutes(45), '45m')
  assert.equal(formatElapsedMinutes(125), '2j 5m')
  assert.equal(formatElapsedMinutes(1500), '1h 1j 0m')
  assert.equal(formatElapsedMinutes(null), '—')
})

test('availability and production downtime render "Not configured" rather than a fake zero', () => {
  assert.equal(formatAvailabilityPercent(null), 'Not configured')
  assert.equal(formatAvailabilityPercent(97.3), '97.3%')
  assert.equal(formatProductionDowntimeMinutes(null), 'Not configured')
  assert.equal(formatProductionDowntimeMinutes(90), '1j 30m')
})

test('currentMonthPeriod returns the full calendar month in the given timezone', () => {
  const period = currentMonthPeriod('Asia/Jakarta')
  assert.match(period.periodStart, /^\d{4}-\d{2}-01$/)
  assert.match(period.periodEnd, /^\d{4}-\d{2}-\d{2}$/)
  assert.ok(period.periodStart <= period.periodEnd)
})

test('cause label falls back to the raw value for an unknown cause', () => {
  assert.equal(causeLabel('mechanical'), 'Kerusakan mekanis')
  assert.equal(causeLabel('unknown_cause'), 'unknown_cause')
})

test('mapDowntimeError gives an understandable message per status', () => {
  assert.match(mapDowntimeError({ status: 409 }), /already has an active downtime/)
  assert.match(mapDowntimeError({ status: 403 }), /not authorized/)
  assert.match(mapDowntimeError({ status: 422, errors: { cause: ['Cause is required.'] } }), /Cause is required/)
  assert.match(mapDowntimeError({ status: 500 }), /could not be completed/)
})

test('newClientRequestId produces a non-empty, distinct token each call, to prevent duplicate submissions', () => {
  const first = newClientRequestId()
  const second = newClientRequestId()
  assert.ok(first && second)
  assert.notEqual(first, second)
})
