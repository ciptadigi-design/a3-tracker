import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentImport } from '../../../services/maintenance.js'

export function useKnowledgeImport(importId) {
  const [documentImport, setDocumentImport] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!importId) { setDocumentImport(null); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadDocumentImport(importId)
      if (requestId.current === request) setDocumentImport(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [importId])

  useEffect(() => { refresh() }, [refresh])
  return { documentImport, isLoading, error, refresh, setDocumentImport }
}
