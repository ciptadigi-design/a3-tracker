import { useCallback, useEffect, useRef, useState } from 'react'
import { loadCodeGroups } from '../../../services/maintenance.js'
import { buildCodeGroupQuery, DEFAULT_PER_PAGE, filterValidationError } from './codeGroupUtils.js'

// V1.7.2 - the consolidated review list. Grouping, evidence/state derivation and pagination all
// happen on the server (one aggregated query per page); this hook only asks for one page of groups
// at a time and never groups or filters the whole candidate set in the browser.
export function useCodeGroups({ importId, filters, perPage = DEFAULT_PER_PAGE, sort, direction, version = 0 }) {
  const [pageNumber, setPageNumber] = useState(1)
  const [result, setResult] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  // Stable identity of the criteria, so a new-but-equal filters object never refetches.
  const filterKey = JSON.stringify(filters)
  const validationError = filterValidationError(filters)

  // `version` lets the parent force a refetch after a review action WITHOUT resetting the page.
  // Any criteria/page-size/sort change resets to page 1 - a stale page number from a wider result would show the wrong rows.
  useEffect(() => { setPageNumber(1) }, [filterKey, perPage, sort, direction])

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!importId || validationError) { setResult(null); setIsLoading(false); setError(null); return }
    setIsLoading(true)
    setError(null)
    try {
      const query = buildCodeGroupQuery(JSON.parse(filterKey), { page: pageNumber, perPage, sort, direction })
      const data = await loadCodeGroups(importId, query)
      if (requestId.current === request) setResult(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [importId, filterKey, pageNumber, perPage, sort, direction, validationError])

  useEffect(() => { refresh() }, [refresh, version])

  return {
    groups: result?.data ?? [],
    summary: result?.summary ?? null,
    currentPage: result?.current_page ?? pageNumber,
    lastPage: result?.last_page ?? 1,
    total: result?.total ?? 0,
    isLoading,
    error,
    validationError,
    goToPage: setPageNumber,
    refresh,
  }
}
