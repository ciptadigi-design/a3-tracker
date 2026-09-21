import { useCallback, useEffect, useRef, useState } from 'react'
import { loadCodeGroup } from '../../../services/maintenance.js'

// V1.7.2 - one normalized code with EVERY underlying occurrence, individually. Fetched only when
// a reviewer opens a group, so the list never carries per-occurrence payloads.
export function useCodeGroupDetail({ importId, code, version = 0 }) {
  const [group, setGroup] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!importId || !code) { setGroup(null); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadCodeGroup(importId, code)
      if (requestId.current === request) setGroup(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [importId, code])

  useEffect(() => { refresh() }, [refresh, version])

  return { group, isLoading, error, refresh }
}
