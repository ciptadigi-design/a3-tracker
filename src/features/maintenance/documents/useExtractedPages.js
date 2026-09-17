import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentPages } from '../../../services/maintenance.js'

const PER_PAGE = 5

// Extracted content can run to hundreds of pages - this hook always fetches one
// pagination page (PER_PAGE rows) at a time via the backend's own paginate(),
// never the full set, so the viewer never renders (or even downloads) everything
// at once.
export function useExtractedPages(documentId, { enabled = true } = {}) {
  const [pageNumber, setPageNumber] = useState(1)
  const [result, setResult] = useState(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!documentId || !enabled) { setResult(null); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadDocumentPages(documentId, { page: pageNumber, perPage: PER_PAGE })
      if (requestId.current === request) setResult(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [documentId, enabled, pageNumber])

  useEffect(() => { refresh() }, [refresh])

  return {
    pages: result?.data ?? [],
    currentPage: result?.current_page ?? pageNumber,
    lastPage: result?.last_page ?? 1,
    total: result?.total ?? 0,
    isLoading,
    error,
    goToPage: setPageNumber,
    refresh,
  }
}
