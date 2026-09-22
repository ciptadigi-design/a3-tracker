import { useEffect, useRef } from 'react'
import { abandonReviewSession, heartbeatReviewSession, startReviewSession } from '../../../services/maintenance.js'
import { FLUSH_INTERVAL_MS, INACTIVITY_THRESHOLD_MS, TICK_MS, activeSecondsForTick, createAccumulator, drainForHeartbeat, hasPendingData } from './reviewSessionActivity.js'

/**
 * V1.11 - Review Workflow Benchmark instrumentation for the code-group review UI. Measures the
 * EXISTING human review->publish workflow; never authors, approves or publishes anything, and a
 * telemetry failure never blocks the real review/publish workflow (every network call here is
 * fire-and-forget, wrapped, and silently dropped on error).
 *
 * Usage: recordSourceView() on an evidence page view, recordAuthoringEdit() on a draft field
 * change, recordValidationFailure() when publish validation blocks the reviewer,
 * setStage('AUTHORING_STARTED' | 'AUTHORING_SAVED' | ...) on a workflow transition, and
 * markPublished() once the real governed publish succeeds (this also stops local tracking -
 * the server already completed the session from the publish call itself; this just tells the
 * hook not to keep accumulating).
 */
export function useReviewSession({ importId, code, enabled = true }) {
  const sessionIdRef = useRef(null)
  const accRef = useRef(null)
  const lastActivityRef = useRef(null)
  const isVisibleRef = useRef(null)
  const doneRef = useRef(false)
  const lastFlushRef = useRef(null)

  useEffect(() => {
    if (!enabled || !importId || !code) return undefined
    let cancelled = false
    doneRef.current = false
    accRef.current = createAccumulator()
    lastActivityRef.current = Date.now()
    isVisibleRef.current = typeof document === 'undefined' || document.visibilityState !== 'hidden'
    lastFlushRef.current = Date.now()

    startReviewSession(importId, code).then((session) => {
      if (!cancelled) sessionIdRef.current = session.id
    }).catch(() => { /* telemetry only - never blocks the review UI */ })

    const markActivity = () => { lastActivityRef.current = Date.now() }
    const onVisibility = () => { isVisibleRef.current = document.visibilityState !== 'hidden'; if (isVisibleRef.current) markActivity() }

    // Deliberately a SMALL, fixed set of listeners (not one per DOM event) - just enough to
    // know "the reviewer is present", not what they clicked or typed.
    window.addEventListener('click', markActivity)
    window.addEventListener('keydown', markActivity)
    window.addEventListener('scroll', markActivity, true)
    document.addEventListener('visibilitychange', onVisibility)

    function flush(stageOverride) {
      if (stageOverride) accRef.current.stage = stageOverride
      if (!sessionIdRef.current || doneRef.current || !hasPendingData(accRef.current)) return
      const payload = drainForHeartbeat(accRef.current)
      const sessionId = sessionIdRef.current
      accRef.current = createAccumulator()
      accRef.current.clientSeq = payload.client_seq
      lastFlushRef.current = Date.now()
      heartbeatReviewSession(sessionId, payload).catch(() => { /* telemetry only */ })
    }

    const tick = setInterval(() => {
      const now = Date.now()
      accRef.current.activeSeconds += activeSecondsForTick({ nowMs: now, lastActivityMs: lastActivityRef.current, isVisible: isVisibleRef.current, tickMs: TICK_MS })
      if (now - lastFlushRef.current >= FLUSH_INTERVAL_MS) flush()
    }, TICK_MS)

    return () => {
      cancelled = true
      clearInterval(tick)
      window.removeEventListener('click', markActivity)
      window.removeEventListener('keydown', markActivity)
      window.removeEventListener('scroll', markActivity, true)
      document.removeEventListener('visibilitychange', onVisibility)
      // Best-effort final flush on navigating away; never awaited, never blocks unmount.
      flush()
    }
  }, [importId, code, enabled])

  function recordSourceView() { accRef.current.sourcePageViews += 1 }
  function recordAuthoringEdit() { accRef.current.authoringEdits += 1 }
  function recordValidationFailure() { accRef.current.validationFailures += 1 }

  function setStage(stage) {
    accRef.current.stage = stage
    // Stage transitions matter to the funnel timestamps - flush promptly rather than waiting
    // for the next periodic tick, but still coalesced with whatever counters have accumulated.
    if (!sessionIdRef.current || doneRef.current || !hasPendingData(accRef.current)) return
    const payload = drainForHeartbeat(accRef.current)
    const sessionId = sessionIdRef.current
    accRef.current = createAccumulator()
    accRef.current.clientSeq = payload.client_seq
    lastFlushRef.current = Date.now()
    heartbeatReviewSession(sessionId, payload).catch(() => { /* telemetry only */ })
  }

  function markPublished() {
    doneRef.current = true
  }

  function abandon() {
    if (!sessionIdRef.current || doneRef.current) return
    doneRef.current = true
    abandonReviewSession(sessionIdRef.current).catch(() => { /* telemetry only */ })
  }

  return { recordSourceView, recordAuthoringEdit, recordValidationFailure, setStage, markPublished, abandon }
}

export const REVIEW_SESSION_INACTIVITY_THRESHOLD_MS = INACTIVITY_THRESHOLD_MS
