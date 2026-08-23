import { useCallback, useEffect, useState } from 'react'
import { loadMachineCatalog } from '../../services/supabase/machines.js'

export function useMachineCatalog(enabled = true) {
  const [catalog, setCatalog] = useState({ manufacturers: [], models: [] })
  const [isLoading, setIsLoading] = useState(enabled)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!enabled) return
    setIsLoading(true)
    setError(null)
    try {
      setCatalog(await loadMachineCatalog())
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [enabled])

  useEffect(() => { refresh() }, [refresh])
  return { ...catalog, isLoading, error, refresh }
}
