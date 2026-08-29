import { useMemo, useState } from 'react'
import { DEFAULT_PAGE_SIZE, pageRange } from './paginationModel.js'

export function usePagination(total, resetKey = '', initialPageSize = DEFAULT_PAGE_SIZE) {
  const [state, setState] = useState(() => ({ page: 1, pageSize: initialPageSize, resetKey }))
  const scopeChanged = state.resetKey !== resetKey
  const range = useMemo(
    () => pageRange(total, scopeChanged ? 1 : state.page, state.pageSize),
    [scopeChanged, state.page, state.pageSize, total],
  )

  function setPage(page) {
    setState((current) => ({ ...current, page, resetKey }))
  }

  function setPageSize(value) {
    setState({ page: 1, pageSize: Number(value), resetKey })
  }

  return { ...range, pageSize: state.pageSize, setPage, setPageSize }
}
