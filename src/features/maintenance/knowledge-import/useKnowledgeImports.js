import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentImports } from '../../../services/maintenance.js'

const POLL_INTERVAL_MS = 4000

export function useKnowledgeImports({ documentId, status } = {}) {
  const [imports, setImports] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)
  const timerRef = useRef(null)

  const refresh = useCallback(async (silent = false) => {
    const request = ++requestId.current
    if (!silent) setIsLoading(true)
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

  // V1.6: reflect a PDF_EXTRACTION import's live pages-processed/candidate
  // progress in the list itself, not only once its detail dialog is opened.
  useEffect(() => {
    const isActive = imports.some((item) => item.import_type === 'PDF_EXTRACTION' && item.status === 'PROCESSING')
    if (!isActive) return undefined
    timerRef.current = setTimeout(() => refresh(true), POLL_INTERVAL_MS)
    return () => clearTimeout(timerRef.current)
  }, [imports, refresh])

  return { imports, isLoading, error, refresh }
}
