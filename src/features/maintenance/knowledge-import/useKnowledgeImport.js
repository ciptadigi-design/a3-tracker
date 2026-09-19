import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentImport } from '../../../services/maintenance.js'

const POLL_INTERVAL_MS = 3000

// V1.6: a PDF_EXTRACTION import runs in the background (chunked queue job -
// see ProcessMaintenanceKnowledgeJob), so the UI needs to notice PROCESSING
// moving to REVIEW/FAILED on its own, same polling pattern as
// useDocumentExtraction. `silent` skips the loading-screen flash on
// poll-triggered refreshes - only the very first load shows it.
export function useKnowledgeImport(importId) {
  const [documentImport, setDocumentImport] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)
  const timerRef = useRef(null)

  const refresh = useCallback(async (silent = false) => {
    const request = ++requestId.current
    if (!importId) { setDocumentImport(null); setIsLoading(false); return }
    if (!silent) setIsLoading(true)
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

  useEffect(() => {
    const isActive = documentImport?.import_type === 'PDF_EXTRACTION' && documentImport?.status === 'PROCESSING'
    if (!isActive) return undefined
    timerRef.current = setTimeout(() => refresh(true), POLL_INTERVAL_MS)
    return () => clearTimeout(timerRef.current)
  }, [documentImport, refresh])

  return { documentImport, isLoading, error, refresh, setDocumentImport }
}
