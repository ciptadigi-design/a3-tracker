/**
 * Plain, React-free polling controller (same spirit as createOverviewRequestGate
 * in overviewPeriod.js) so the interval/backoff/visibility behavior the TV
 * display needs is testable without rendering a hook. There is always at
 * most one pending timer: every scheduling path clears the previous one
 * first, which is what rules out duplicate timers from repeated start/resume
 * calls rather than any flag bookkeeping.
 *
 * Backoff is approximate 30s / 60s / 120s on consecutive failures, capped at
 * 120s, and resets to the 30s base the moment a refresh succeeds again.
 */
export function createLivePoller({ fetchData, onResult, baseDelayMs = 30_000, backoffDelaysMs = [30_000, 60_000, 120_000] }) {
  let timer = null
  let paused = false
  let destroyed = false
  let requestId = 0
  let failureCount = 0

  function clearTimer() {
    if (timer != null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function scheduleNext(delay) {
    clearTimer()
    if (paused || destroyed) return
    timer = setTimeout(refreshNow, delay)
  }

  async function refreshNow() {
    clearTimer()
    if (destroyed) return
    const id = ++requestId
    try {
      const data = await fetchData()
      if (id !== requestId || destroyed) return
      failureCount = 0
      onResult({ success: true, data })
      scheduleNext(baseDelayMs)
    } catch (error) {
      if (id !== requestId || destroyed) return
      failureCount += 1
      onResult({ success: false, error, failureCount })
      scheduleNext(backoffDelaysMs[Math.min(failureCount - 1, backoffDelaysMs.length - 1)])
    }
  }

  return {
    // Starts (or restarts) the loop with an immediate refresh. Safe to call
    // more than once - any in-flight fetch from a previous call is ignored
    // once it resolves, because refreshNow bumps requestId up front.
    start() {
      paused = false
      refreshNow()
    },
    // Stops scheduling further refreshes without an immediate refetch -
    // used when the document becomes hidden. The in-flight fetch (if any)
    // is still allowed to resolve, but its result will not schedule a next
    // timer while paused.
    pause() {
      paused = true
      clearTimer()
    },
    // Resumes a paused loop with one immediate refresh, then restarts the
    // normal interval. A no-op when not currently paused.
    resume() {
      if (!paused) return
      paused = false
      refreshNow()
    },
    // Permanently stops the loop (component unmount). No further timers are
    // scheduled and any in-flight fetch's result is discarded.
    stop() {
      destroyed = true
      clearTimer()
    },
    refreshNow,
    isPaused: () => paused,
  }
}
