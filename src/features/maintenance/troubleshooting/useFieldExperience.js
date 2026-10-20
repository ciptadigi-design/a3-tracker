import { useCallback, useEffect, useState } from 'react'
import { loadFieldExperience } from '../../../services/maintenance.js'

// State is keyed by account + experience, so a different account or id is always "loading" and a
// previous account's detail can never be rendered. Any failure clears the data (permission changes
// and ineligibility included) instead of keeping the last successful response.
export function useFieldExperience(accountId, ticketId) {
  const key = `${accountId}:${ticketId}`
  const [state, setState] = useState({ key: null, data: null, error: null })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let cancelled = false
    loadFieldExperience({ accountId, ticketId })
      .then((data) => { if (!cancelled) setState({ key, data, error: null }) })
      .catch((error) => { if (!cancelled) setState({ key, data: null, error }) })
    return () => { cancelled = true }
  }, [accountId, ticketId, key, attempt])

  const current = state.key === key
  const refresh = useCallback(() => { setState({ key: null, data: null, error: null }); setAttempt((value) => value + 1) }, [])
  return { experience: current ? state.data : null, error: current ? state.error : null, isLoading: !current, refresh }
}
