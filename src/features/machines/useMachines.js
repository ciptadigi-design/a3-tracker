import { useCallback, useEffect, useState } from 'react'
import { loadMachines } from '../../services/supabase/machines.js'

export function useMachines(accountId, branchId) {
  const [machines, setMachines] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!accountId) return
    setIsLoading(true)
    setError(null)
    try {
      setMachines(await loadMachines({ accountId, branchId }))
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [accountId, branchId])

  useEffect(() => { refresh() }, [refresh])
  return { machines, isLoading, error, refresh }
}
