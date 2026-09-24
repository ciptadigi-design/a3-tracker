import { useCallback, useEffect, useState } from 'react'
import { loadOfficialErrorEntry } from '../../../services/maintenance.js'

export function useOfficialErrorEntry(entryId) {
  const [entry, setEntry] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try { setEntry(await loadOfficialErrorEntry(entryId)) }
    catch (nextError) { setError(nextError); setEntry(null) }
    finally { setIsLoading(false) }
  }, [entryId])

  useEffect(() => { load() }, [load])
  return { entry, isLoading, error, refresh: load }
}
