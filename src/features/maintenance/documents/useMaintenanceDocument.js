import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMaintenanceDocument } from '../../../services/maintenance.js'

export function useMaintenanceDocument(documentId) {
  const [document, setDocument] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!documentId) { setDocument(null); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadMaintenanceDocument(documentId)
      if (requestId.current === request) setDocument(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [documentId])

  useEffect(() => { refresh() }, [refresh])
  return { document, isLoading, error, refresh, setDocument }
}
