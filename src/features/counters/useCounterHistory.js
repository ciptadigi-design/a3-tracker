import { useCallback, useEffect, useState } from 'react'
import { loadCounterHistory } from '../../services/supabase/counters.js'

export function useCounterHistory(accountId, machineId) {
  const [history, setHistory] = useState([])
  const [profiles, setProfiles] = useState([])
  const [isLoading, setIsLoading] = useState(Boolean(accountId && machineId))
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
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
      setHistory(data.history)
      setProfiles(data.profiles)
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [accountId, machineId])

  useEffect(() => { refresh() }, [refresh])
  return { history, profiles, isLoading, error, refresh }
}
