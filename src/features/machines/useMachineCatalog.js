import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMachineCatalog } from '../../services/supabase/machines.js'

export function useMachineCatalog(accountId, enabled = true) {
  const [catalog, setCatalog] = useState({ manufacturers: [], models: [] })
  const [isLoading, setIsLoading] = useState(enabled)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!enabled || !accountId) { setCatalog({ manufacturers: [], models: [] }); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadMachineCatalog(accountId)
      if (requestId.current === request) setCatalog(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [accountId, enabled])

  useEffect(() => { refresh() }, [refresh])
  return { ...catalog, isLoading, error, refresh }
}
