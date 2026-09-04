import test from 'node:test'
import assert from 'node:assert/strict'
import { CANONICAL_PERIOD_TIMEZONE, normalizePeriodDateKey, resolveMachineCostPeriod } from './machineCostPeriods.js'

const now = new Date('2026-09-04T18:30:00Z') // 2026-09-05 01:30 Asia/Jakarta

test('explicit August range resolves via the last_month preset', () => {
  const period = resolveMachineCostPeriod({ preset: 'last_month', timezone: CANONICAL_PERIOD_TIMEZONE, now })
  assert.deepEqual(period, { start: '2026-08-01', end: '2026-08-31' })
})

test('current month resolves inclusive of today', () => {
  const period = resolveMachineCostPeriod({ preset: 'this_month', timezone: CANONICAL_PERIOD_TIMEZONE, now })
  assert.deepEqual(period, { start: '2026-09-01', end: '2026-09-05' })
})

test('month boundary: last day of a 31-day month does not roll into the next month', () => {
  const boundary = new Date('2026-01-31T23:00:00Z') // still 2026-02-01 in Jakarta
  const period = resolveMachineCostPeriod({ preset: 'this_month', timezone: CANONICAL_PERIOD_TIMEZONE, now: boundary })
  assert.deepEqual(period, { start: '2026-02-01', end: '2026-02-01' })
})

test('Asia/Jakarta normalization: a UTC evening timestamp is already the next Jakarta day', () => {
  const period = resolveMachineCostPeriod({ preset: 'today', timezone: CANONICAL_PERIOD_TIMEZONE, now })
  assert.deepEqual(period, { start: '2026-09-05', end: '2026-09-05' })
})

test('no UTC off-by-one: UTC and Asia/Jakarta diverge by exactly the timezone offset, never silently drift', () => {
  const utcPeriod = resolveMachineCostPeriod({ preset: 'today', timezone: 'UTC', now })
  const jakartaPeriod = resolveMachineCostPeriod({ preset: 'today', timezone: CANONICAL_PERIOD_TIMEZONE, now })
  assert.equal(utcPeriod.start, '2026-09-04')
  assert.equal(jakartaPeriod.start, '2026-09-05')
})

test('malformed custom period values are rejected, never forwarded to the API', () => {
  assert.equal(normalizePeriodDateKey('2026-9-4'), null)
  assert.equal(normalizePeriodDateKey('09/04/2026'), null)
  assert.equal(normalizePeriodDateKey('2026-09-04T00:00:00Z'), null)
  assert.equal(normalizePeriodDateKey(new Date().toString()), null)
  assert.equal(normalizePeriodDateKey('2026-02-30'), null) // not a real calendar day
  assert.equal(normalizePeriodDateKey(''), null)
  assert.equal(normalizePeriodDateKey(null), null)
  assert.equal(normalizePeriodDateKey(undefined), null)
})

test('malformed custom period values never reach a valid resolved period', () => {
  const period = resolveMachineCostPeriod({ preset: 'custom', customStart: '09/04/2026', customEnd: '2026-09-04T00:00:00Z' })
  assert.deepEqual(period, { start: null, end: null })
})

test('well-formed custom period values pass through unchanged', () => {
  const period = resolveMachineCostPeriod({ preset: 'custom', customStart: '2026-08-01', customEnd: '2026-08-31' })
  assert.deepEqual(period, { start: '2026-08-01', end: '2026-08-31' })
})
