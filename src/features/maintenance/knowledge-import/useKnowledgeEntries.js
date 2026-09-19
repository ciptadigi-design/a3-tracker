import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentImportEntries } from '../../../services/maintenance.js'

const PER_PAGE = 20

// V1.6.1: a real Production import produced 1285 candidates in a single run
// (dense code-index/glossary sections in the real manual - see
// docs/maintenance/V1.6_KNOWLEDGE_PROCESSING.md), so candidates are always
// fetched one server-paginated page at a time, filtered server-side
// (status/collision/evidence/code), never the full set loaded client-side.
export function useKnowledgeEntries({ importId, status, collisionStatus, evidence, code }) {
  const [pageNumber, setPageNumber] = useState(1)
  const [result, setResult] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  // Any filter change resets to page 1 - a stale page number from a wider
  // filter would otherwise silently show "no results" or the wrong rows.
  useEffect(() => { setPageNumber(1) }, [status, collisionStatus, evidence, code])

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!importId) { setResult(null); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadDocumentImportEntries(importId, { status, collisionStatus, evidence, code, page: pageNumber, perPage: PER_PAGE })
      if (requestId.current === request) setResult(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [importId, status, collisionStatus, evidence, code, pageNumber])

  useEffect(() => { refresh() }, [refresh])

  return {
    entries: result?.data ?? [],
    currentPage: result?.current_page ?? pageNumber,
    lastPage: result?.last_page ?? 1,
    total: result?.total ?? 0,
    isLoading,
    error,
    goToPage: setPageNumber,
    refresh,
  }
}
