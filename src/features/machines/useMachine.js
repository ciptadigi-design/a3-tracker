import { useCallback, useEffect, useState } from 'react'
import { loadMachine } from '../../services/supabase/machines.js'

export function useMachine(accountId, machineId) {
  const [machine, setMachine] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!accountId || !machineId) return
    setIsLoading(true)
    setError(null)
    try {
      setMachine(await loadMachine({ accountId, machineId }))
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [accountId, machineId])

  useEffect(() => { refresh() }, [refresh])
  return { machine, isLoading, error, refresh, setMachine }
}
