import { useCallback, useEffect, useRef, useState } from 'react'
import { loadOperationalPeopleForBranch } from '../../services/supabase/operationalMasters.js'

export function useOperationalPeople(accountId, branchId) {
  const [state, setState] = useState({ people: [], isLoading: Boolean(accountId && branchId), error: null })
  const requestId = useRef(0)
  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!accountId || !branchId) return setState({ people: [], isLoading: false, error: null })
    setState((current) => ({ ...current, isLoading: true, error: null }))
    try {
      const people = await loadOperationalPeopleForBranch({ accountId, branchId })
      if (requestId.current === request) setState({ people, isLoading: false, error: null })
    } catch (error) {
      if (requestId.current === request) setState((current) => ({ ...current, isLoading: false, error }))
    }
  }, [accountId, branchId])
  useEffect(() => { refresh() }, [refresh])
  return { ...state, refresh }
}
