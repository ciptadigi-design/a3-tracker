import assert from 'node:assert/strict'
import test from 'node:test'
import { INACTIVITY_THRESHOLD_MS, TICK_MS, activeSecondsForTick, createAccumulator, drainForHeartbeat, hasPendingData } from './reviewSessionActivity.js'

// V1.11 - the active-time model itself (see the file's own docblock for the rationale): pure
// functions, tested directly rather than through a DOM/timer harness.

test('a visible, recently-active tick counts its full duration', () => {
  const now = 1_000_000
  assert.equal(activeSecondsForTick({ nowMs: now, lastActivityMs: now - 1000, isVisible: true }), Math.round(TICK_MS / 1000))
})

test('a background/hidden tab never accumulates active time, however recent the last activity', () => {
  const now = 1_000_000
  assert.equal(activeSecondsForTick({ nowMs: now, lastActivityMs: now - 100, isVisible: false }), 0)
})

test('a tick beyond the inactivity threshold stops accumulating even while visible', () => {
  const now = 1_000_000
  assert.equal(activeSecondsForTick({ nowMs: now, lastActivityMs: now - (INACTIVITY_THRESHOLD_MS + 1), isVisible: true }), 0)
})

test('activity just inside the threshold still counts', () => {
  const now = 1_000_000
  assert.equal(activeSecondsForTick({ nowMs: now, lastActivityMs: now - (INACTIVITY_THRESHOLD_MS - 1), isVisible: true }), Math.round(TICK_MS / 1000))
})

test('resuming activity after a gap counts again on the next tick (no permanent lockout)', () => {
  const now = 1_000_000
  // A long-idle gap produced 0 on its tick...
  assert.equal(activeSecondsForTick({ nowMs: now, lastActivityMs: now - 5 * INACTIVITY_THRESHOLD_MS, isVisible: true }), 0)
  // ...but a fresh activity timestamp on the very next tick counts normally again.
  assert.equal(activeSecondsForTick({ nowMs: now + TICK_MS, lastActivityMs: now + TICK_MS, isVisible: true }), Math.round(TICK_MS / 1000))
})

test('accumulator starts empty and hasPendingData is false until something is recorded', () => {
  const acc = createAccumulator()
  assert.equal(hasPendingData(acc), false)
  acc.authoringEdits += 1
  assert.equal(hasPendingData(acc), true)
})

test('drainForHeartbeat builds a bounded payload and increments client_seq', () => {
  const acc = createAccumulator()
  acc.activeSeconds = 30
  acc.sourcePageViews = 2
  acc.stage = 'AUTHORING_STARTED'
  const payload = drainForHeartbeat(acc)
  assert.deepEqual(payload, { active_seconds: 30, source_page_views: 2, authoring_edits: 0, validation_failures: 0, client_seq: 1, stage: 'AUTHORING_STARTED' })
})

test('drainForHeartbeat omits stage entirely when none was set', () => {
  const acc = createAccumulator()
  acc.activeSeconds = 5
  const payload = drainForHeartbeat(acc)
  assert.equal('stage' in payload, false)
})
