import { useCallback, useEffect, useState } from 'react'
import { loadOperationalPeopleForBranch } from '../../services/supabase/operationalMasters.js'

export function useOperationalPeople(accountId, branchId) {
  const [state, setState] = useState({ people: [], isLoading: Boolean(accountId && branchId), error: null })
  const refresh = useCallback(async () => {
    if (!accountId || !branchId) return setState({ people: [], isLoading: false, error: null })
    setState((current) => ({ ...current, isLoading: true, error: null }))
    try {
      const people = await loadOperationalPeopleForBranch({ accountId, branchId })
      setState({ people, isLoading: false, error: null })
    } catch (error) {
      setState((current) => ({ ...current, isLoading: false, error }))
    }
  }, [accountId, branchId])
  useEffect(() => { refresh() }, [refresh])
  return { ...state, refresh }
}
