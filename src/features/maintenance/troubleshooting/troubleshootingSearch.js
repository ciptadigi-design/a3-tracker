import { classifyTroubleshootingQuery } from './troubleshootingModel.js'

// Framework-free controller for the combined search. Official Knowledge and Pengalaman Lapangan are
// two independent groups: each has its own status, items, paging and error, so one source failing,
// loading or being empty never changes what the other shows. A newer search supersedes an older one
// (late responses are ignored), and "load more" appends the next backend page.

export const OFFICIAL_PAGE_SIZE = 20 // backend cap is 25
export const FIELD_PAGE_SIZE = 10 // backend cap is 25

const emptyGroup = () => ({ status: 'idle', items: [], page: 0, total: 0, hasMore: false, error: null, loadingMore: false, moreError: null })

export const initialSearchState = () => ({ query: '', kind: 'empty', codes: [], official: emptyGroup(), field: emptyGroup(), fieldSkipReason: null })

function pageInfo(result, previousPage) {
  const meta = result?.meta
  const page = meta?.current_page ?? previousPage + 1
  return { page, total: meta?.total ?? null, hasMore: meta ? meta.current_page < meta.last_page : false }
}

function appendUnique(existing, incoming) {
  const seen = new Set(existing.map((item) => item.id))
  return [...existing, ...incoming.filter((item) => !seen.has(item.id))]
}

export function createTroubleshootingSearch({ searchOfficial, searchField, accountId, onChange }) {
  let state = initialSearchState()
  let runId = 0
  let context = { machineId: '' }
  let disposed = false

  const emit = () => { if (!disposed) onChange?.(state) }
  const patch = (group, values) => { state = { ...state, [group]: { ...state[group], ...values } }; emit() }

  const fetchers = {
    official: (page) => searchOfficial(state.classified.officialQuery, { page, perPage: OFFICIAL_PAGE_SIZE }),
    field: (page) => searchField({ accountId, query: state.classified.fieldQuery, machineId: context.machineId || undefined, page, perPage: FIELD_PAGE_SIZE }),
  }

  async function load(group, page, id) {
    const first = page === 1
    patch(group, first ? { status: 'loading', error: null, moreError: null, items: [], page: 0, total: 0, hasMore: false } : { loadingMore: true, moreError: null })
    try {
      const result = await fetchers[group](page)
      if (id !== runId) return
      const items = result?.data ?? []
      const info = pageInfo(result, state[group].page)
      const merged = first ? items : appendUnique(state[group].items, items)
      patch(group, {
        status: merged.length > 0 ? 'success' : 'empty',
        items: merged,
        page: info.page,
        total: info.total ?? merged.length,
        hasMore: info.hasMore,
        loadingMore: false,
      })
    } catch (error) {
      if (id !== runId) return
      // A failed first page is an error, never a zero-result message; a failed later page keeps what was loaded.
      if (first) patch(group, { status: 'error', error, items: [], loadingMore: false })
      else patch(group, { loadingMore: false, moreError: error })
    }
  }

  return {
    getState: () => state,
    // attach/dispose are reversible so React StrictMode's mount-cleanup-mount cycle keeps working.
    attach() { disposed = false },
    dispose() { disposed = true; runId += 1 },
    run(rawQuery, { machineId = '' } = {}) {
      const classified = classifyTroubleshootingQuery(rawQuery)
      const id = ++runId
      context = { machineId }
      state = { ...initialSearchState(), query: classified.query, kind: classified.kind, codes: classified.codes, fieldSkipReason: classified.fieldSkipReason, classified }
      if (classified.kind === 'empty') { emit(); return Promise.resolve() }
      state = {
        ...state,
        official: { ...emptyGroup(), status: classified.officialQuery ? 'loading' : 'skipped' },
        field: { ...emptyGroup(), status: classified.fieldQuery ? 'loading' : 'skipped' },
      }
      emit()
      return Promise.all([
        classified.officialQuery ? load('official', 1, id) : null,
        classified.fieldQuery ? load('field', 1, id) : null,
      ])
    },
    loadMore(group) {
      const current = state[group]
      if (!current || current.status !== 'success' || !current.hasMore || current.loadingMore) return Promise.resolve()
      return load(group, current.page + 1, runId)
    },
    // Retry re-runs only the failed group, leaving the other untouched.
    retry(group) {
      if (!state.classified || state[group].status !== 'error') return Promise.resolve()
      return load(group, 1, runId)
    },
  }
}
