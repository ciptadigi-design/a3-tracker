import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMachines } from '../../services/supabase/machines.js'

export function useMachines(accountId, branchId) {
  const [machines, setMachines] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!accountId) { setMachines([]); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const rows = await loadMachines({ accountId, branchId })
      if (requestId.current === request) setMachines(rows)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [accountId, branchId])

  useEffect(() => { refresh() }, [refresh])
  return { machines, isLoading, error, refresh }
}
