import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMachine } from '../../services/supabase/machines.js'

export function useMachine(accountId, branchId, machineId) {
  const [machine, setMachine] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!accountId || !branchId || !machineId) { setMachine(null); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const row = await loadMachine({ accountId, branchId, machineId })
      if (requestId.current === request) setMachine(row)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [accountId, branchId, machineId])

  useEffect(() => { refresh() }, [refresh])
  return { machine, isLoading, error, refresh, setMachine }
}
