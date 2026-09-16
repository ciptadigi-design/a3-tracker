import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMaintenanceKnowledge } from '../../services/maintenance.js'

export function useMaintenanceKnowledge({ approvalStatus } = {}) {
  const [entries, setEntries] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadMaintenanceKnowledge({ approvalStatus })
      if (requestId.current === request) setEntries(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [approvalStatus])

  useEffect(() => { refresh() }, [refresh])
  return { entries, isLoading, error, refresh }
}
