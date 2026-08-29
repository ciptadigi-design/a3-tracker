import { useCallback, useEffect, useRef, useState } from 'react'
import { loadOperationalIncident, loadOperationalIncidents } from '../../services/supabase/operationalIncidents.js'

export function useOperationalIncidents(accountId, branchId) {
  const [incidents, setIncidents] = useState([])
  const [members, setMembers] = useState([])
  const [people, setPeople] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!accountId || !branchId) { setIncidents([]); setMembers([]); setPeople([]); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadOperationalIncidents({ accountId, branchId })
      if (requestId.current === request) {
        setIncidents(data.incidents)
        setMembers(data.members)
        setPeople(data.people)
      }
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [accountId, branchId])

  useEffect(() => { refresh() }, [refresh])
  return { incidents, members, people, isLoading, error, refresh }
}

export function useOperationalIncident(accountId, branchId, incidentId) {
  const [incident, setIncident] = useState(null)
  const [members, setMembers] = useState([])
  const [revisions, setRevisions] = useState([])
  const [people, setPeople] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async ({ silent = false } = {}) => {
    const request = ++requestId.current
    if (!accountId || !branchId || !incidentId) { setIncident(null); setIsLoading(false); return }
    if (!silent) setIsLoading(true)
    setError(null)
    try {
      const data = await loadOperationalIncident({ accountId, branchId, incidentId })
      if (requestId.current !== request) return null
      setIncident(data.incident)
      setMembers(data.members)
      setRevisions(data.revisions)
      setPeople(data.people)
      return data.incident
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (!silent && requestId.current === request) setIsLoading(false)
    }
  }, [accountId, branchId, incidentId])

  useEffect(() => { refresh() }, [refresh])
  return { incident, members, people, revisions, isLoading, error, refresh, setIncident }
}
