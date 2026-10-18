import assert from 'node:assert/strict'
import test from 'node:test'
import { causeLabel, currentMonthPeriod, describeMachineStatus, elapsedMinutesBetween, formatAvailabilityPercent, formatElapsedMinutes, formatProductionDowntimeMinutes, mapDowntimeError, newClientRequestId, normalizeActiveDowntime, summarizeMachineHealth } from './downtimeModel.js'

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

test('a missing or unparseable actual_start_at never produces "NaNm" (regression for production Machine Detail bug)', () => {
  assert.equal(elapsedMinutesBetween(undefined), null)
  assert.equal(elapsedMinutesBetween(null), null)
  assert.equal(elapsedMinutesBetween(''), null)
  assert.equal(elapsedMinutesBetween('not-a-date'), null)
  assert.equal(elapsedMinutesBetween('2026-01-01T00:00:00Z', 'not-a-date'), null)
  assert.equal(formatElapsedMinutes(elapsedMinutesBetween(undefined)), '—')
  assert.equal(formatElapsedMinutes(NaN), '—')
})

test('availability and production downtime render "Belum dikonfigurasi" rather than a fake zero', () => {
  assert.equal(formatAvailabilityPercent(null), 'Belum dikonfigurasi')
  assert.equal(formatAvailabilityPercent(97.3), '97.3%')
  assert.equal(formatProductionDowntimeMinutes(null), 'Belum dikonfigurasi')
  assert.equal(formatProductionDowntimeMinutes(90), '1j 30m')
})

test('currentMonthPeriod returns the full calendar month in the given timezone', () => {
  const period = currentMonthPeriod('Asia/Jakarta')
  assert.match(period.periodStart, /^\d{4}-\d{2}-01$/)
  assert.match(period.periodEnd, /^\d{4}-\d{2}-\d{2}$/)
  assert.ok(period.periodStart <= period.periodEnd)
})

test('cause label falls back to the raw value for an unknown cause', () => {
  assert.equal(causeLabel('mechanical'), 'Mechanical Failure')
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

const activeEvent = { id: 'evt-1', machine_id: 'm-1', cause: 'mechanical', error_code_text: 'C-3913', actual_start_at: '2026-01-01T00:00:00Z', actual_end_at: null }

test('an idle machine ({ data: null }) is Normal, not a phantom active event (production P0 bug)', () => {
  assert.equal(normalizeActiveDowntime({ data: null }), null)
  assert.equal(normalizeActiveDowntime(null), null)
  assert.equal(describeMachineStatus(normalizeActiveDowntime({ data: null })).state, 'normal')
})

test('an active event is unwrapped from the API envelope and reported as Berhenti', () => {
  const event = normalizeActiveDowntime({ data: activeEvent })
  assert.equal(event.id, 'evt-1')
  assert.equal(describeMachineStatus(event).state, 'stopped')
})

test('a response body without an identifiable event is rejected instead of guessed as Normal or Berhenti', () => {
  assert.throws(() => normalizeActiveDowntime({ data: {} }), /tidak valid/)
  assert.throws(() => normalizeActiveDowntime({ data: 'oops' }), /tidak valid/)
})

test('a stopped machine with a missing or invalid start shows "Tidak tersedia", never NaN or a fake zero, and cannot be completed', () => {
  for (const actual_start_at of [undefined, null, '', 'garbage']) {
    const status = describeMachineStatus({ ...activeEvent, actual_start_at })
    assert.equal(status.state, 'stopped')
    assert.equal(status.startText, 'Tidak tersedia')
    assert.equal(status.durationText, 'Tidak tersedia')
    assert.equal(status.elapsedMinutes, null)
    assert.equal(status.canComplete, false)
  }
  const valid = describeMachineStatus(activeEvent, 'Asia/Jakarta')
  assert.equal(valid.canComplete, true)
  assert.doesNotMatch(valid.durationText, /NaN|Tidak tersedia/)
  assert.equal(valid.causeText, 'Mechanical Failure')
  assert.equal(valid.errorCode, 'C-3913')
})

test('Overview health and Machine Detail agree: a machine with an active event is Stopped in both and never Operational', () => {
  const machines = [{ id: 'm-1' }, { id: 'm-2' }]
  const detailForM1 = describeMachineStatus(normalizeActiveDowntime({ data: activeEvent }))
  const detailForM2 = describeMachineStatus(normalizeActiveDowntime({ data: null }))
  const health = summarizeMachineHealth({ machines, attention: [{ machine_id: 'm-1', downtime_event_id: 'evt-1' }] })
  assert.equal(detailForM1.state, 'stopped')
  assert.equal(detailForM2.state, 'normal')
  assert.equal(health.stoppedCount, 1)
  assert.equal(health.operationalCount, 1)
  assert.deepEqual(health.stoppedMachines.map((item) => item.machine_id), ['m-1'])
})

test('an active event for a machine missing from the machine list is still counted as stopped', () => {
  const health = summarizeMachineHealth({ machines: [{ id: 'm-2' }], attention: [{ machine_id: 'm-1' }] })
  assert.equal(health.stoppedCount, 1)
  assert.equal(health.operationalCount, 1)
})

test('single-machine scope with no active events is fully operational and counts no phantom stops', () => {
  const health = summarizeMachineHealth({ machines: [{ id: 'm-1' }], attention: [] })
  assert.deepEqual({ o: health.operationalCount, s: health.stoppedCount }, { o: 1, s: 0 })
})
