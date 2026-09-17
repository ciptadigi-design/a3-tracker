import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentImports } from '../../../services/maintenance.js'

export function useKnowledgeImports({ documentId, status } = {}) {
  const [imports, setImports] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadDocumentImports({ documentId, status })
      if (requestId.current === request) setImports(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [documentId, status])

  useEffect(() => { refresh() }, [refresh])
  return { imports, isLoading, error, refresh }
}
