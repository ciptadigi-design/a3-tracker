import { useCallback, useEffect, useRef, useState } from 'react'
import { loadReviewBenchmark } from '../../../services/maintenance.js'
import { mapMaintenanceError } from '../maintenanceUtils.js'

export function useReviewBenchmark({ importId, cohort, enabled = true }) {
  const [summary, setSummary] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!enabled || !importId) { setSummary(null); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadReviewBenchmark(importId, { cohort })
      if (requestId.current === request) setSummary(data)
    } catch (loadError) {
      if (requestId.current === request) setError(mapMaintenanceError(loadError))
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [importId, cohort, enabled])

  useEffect(() => { refresh() }, [refresh])

  return { summary, isLoading, error, refresh }
}
