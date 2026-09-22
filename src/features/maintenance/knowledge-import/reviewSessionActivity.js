// V1.11 - Review Workflow Benchmark: the ACTIVE-TIME MODEL, kept as pure, framework-free
// functions so it can be unit-tested without mounting React or faking timers end-to-end.
//
// Naive wall-clock (published_at - opened_at) overcounts a reviewer who leaves the tab open for
// hours. Instead: a coarse tick (every TICK_MS while the review dialog is open) only counts
// toward active_seconds when the document is visible AND the reviewer interacted within the
// last INACTIVITY_THRESHOLD_MS. Local aggregation only - useReviewSession.js flushes the
// accumulated total periodically/on stage transitions, never per tick.

export const TICK_MS = 5000
export const INACTIVITY_THRESHOLD_MS = 60000
export const FLUSH_INTERVAL_MS = 30000

/**
 * How many active seconds this one tick contributes.
 *
 * @param {{ nowMs: number, lastActivityMs: number, isVisible: boolean, tickMs?: number }} args
 * @returns {number} 0 or the tick duration in whole seconds
 */
export function activeSecondsForTick({ nowMs, lastActivityMs, isVisible, tickMs = TICK_MS }) {
  if (!isVisible) return 0
  if (nowMs - lastActivityMs > INACTIVITY_THRESHOLD_MS) return 0

  return Math.round(tickMs / 1000)
}

/** A tiny local accumulator: counters that only ever grow between flushes, plus a monotonic client_seq for idempotency. */
export function createAccumulator() {
  return { activeSeconds: 0, sourcePageViews: 0, authoringEdits: 0, validationFailures: 0, stage: null, clientSeq: 0 }
}

export function hasPendingData(acc) {
  return acc.activeSeconds > 0 || acc.sourcePageViews > 0 || acc.authoringEdits > 0 || acc.validationFailures > 0 || acc.stage !== null
}

/** Drains the accumulator into a heartbeat payload and resets the drained fields (stage/clientSeq are NOT reset by the caller - see useReviewSession.js). */
export function drainForHeartbeat(acc) {
  const payload = {
    active_seconds: acc.activeSeconds,
    source_page_views: acc.sourcePageViews,
    authoring_edits: acc.authoringEdits,
    validation_failures: acc.validationFailures,
    client_seq: acc.clientSeq + 1,
  }
  if (acc.stage) payload.stage = acc.stage

  return payload
}
