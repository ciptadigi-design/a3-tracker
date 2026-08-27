import { useCallback, useEffect, useState } from 'react'
import { loadOperationalMasters } from '../../services/supabase/operationalMasters.js'

export function useOperationalPeople(accountId) {
  const [state, setState] = useState({ people: [], isLoading: Boolean(accountId), error: null })
  const refresh = useCallback(async () => {
    if (!accountId) return
    setState((current) => ({ ...current, isLoading: true, error: null }))
    try {
      const result = await loadOperationalMasters({ accountId })
      setState({ people: result.people, isLoading: false, error: null })
    } catch (error) {
      setState((current) => ({ ...current, isLoading: false, error }))
    }
  }, [accountId])
  useEffect(() => { refresh() }, [refresh])
  return { ...state, refresh }
}
