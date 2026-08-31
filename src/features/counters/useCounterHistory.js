import { useCallback, useEffect, useRef, useState } from 'react'
import { loadCounterHistory } from '../../services/counters.js'

export function useCounterHistory(accountId, machineId) {
  const [history, setHistory] = useState([])
  const [profiles, setProfiles] = useState([])
  const [isLoading, setIsLoading] = useState(Boolean(accountId && machineId))
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!accountId || !machineId) {
      setHistory([])
      setProfiles([])
      setIsLoading(false)
      return
    }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadCounterHistory({ accountId, machineId })
      if (requestId.current === request) {
        setHistory(data.history)
        setProfiles(data.profiles)
      }
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [accountId, machineId])

  useEffect(() => { refresh() }, [refresh])
  return { history, profiles, isLoading, error, refresh }
}
