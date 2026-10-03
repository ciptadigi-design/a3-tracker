import { useEffect, useMemo, useRef, useState } from 'react'
import { loadCounterHistory } from '../../services/counters.js'
import { loadClickTargetProjection } from '../../services/clickTargets.js'
import { resolveMachineCostPeriod } from '../machineCost/machineCostPeriods.js'
import { createLivePoller } from './livePoller.js'
import { deriveStatusOnFailure, deriveStatusOnSuccess, isCounterHistoryEmpty } from './liveDisplayModel.js'

/**
 * Owns the TV display's data lifecycle: bounded 30s polling (counter history
 * + click-target projection together, both authoritative existing sources),
 * tab-visibility pause/resume, and failure backoff - all delegated to
 * createLivePoller so the timer/backoff rules live in one testable place.
 * Last-good data is never cleared by a failed refresh; only `status` and
 * `error` change.
 */
export function useLiveDisplayData({ accountId, machineId, timezone, enabled = true }) {
  const [state, setState] = useState({
    status: 'loading',
    history: [],
    profiles: [],
    projection: null,
    error: null,
    lastSyncedAt: null,
  })
  const hasDataRef = useRef(false)

  const fetchData = useMemo(() => async () => {
    const period = resolveMachineCostPeriod({ preset: 'this_month', timezone })
    const [historyResult, projection] = await Promise.all([
      loadCounterHistory({ accountId, machineId }),
      loadClickTargetProjection({ accountId, machineId, periodStart: period.start, periodEnd: period.end, periodPreset: 'this_month' }),
    ])
    return { history: historyResult.history, profiles: historyResult.profiles, projection }
  }, [accountId, machineId, timezone])

  useEffect(() => {
    if (!enabled) return undefined
    hasDataRef.current = false

    const poller = createLivePoller({
      fetchData,
      onResult(result) {
        if (result.success) {
          hasDataRef.current = true
          const historyEmpty = isCounterHistoryEmpty(result.data.history)
          setState({
            status: deriveStatusOnSuccess({ historyEmpty }),
            history: result.data.history,
            profiles: result.data.profiles,
            projection: result.data.projection,
            error: null,
            lastSyncedAt: new Date(),
          })
        } else {
          setState((previous) => ({
            ...previous,
            status: deriveStatusOnFailure({ hasData: hasDataRef.current, failureCount: result.failureCount }),
            error: result.error,
          }))
        }
      },
    })

    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') poller.resume()
      else poller.pause()
    }

    poller.start()
    document.addEventListener('visibilitychange', handleVisibilityChange)

    return () => {
      document.removeEventListener('visibilitychange', handleVisibilityChange)
      poller.stop()
    }
  }, [enabled, fetchData])

  return state
}
