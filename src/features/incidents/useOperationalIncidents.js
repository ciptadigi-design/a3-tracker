import { useCallback, useEffect, useState } from 'react'
import { loadOperationalIncident, loadOperationalIncidents } from '../../services/supabase/operationalIncidents.js'

export function useOperationalIncidents(accountId, branchId) {
  const [incidents, setIncidents] = useState([])
  const [members, setMembers] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!accountId || !branchId) return
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadOperationalIncidents({ accountId, branchId })
      setIncidents(data.incidents)
      setMembers(data.members)
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [accountId, branchId])

  useEffect(() => { refresh() }, [refresh])
  return { incidents, members, isLoading, error, refresh }
}

export function useOperationalIncident(accountId, incidentId) {
  const [incident, setIncident] = useState(null)
  const [members, setMembers] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!accountId || !incidentId) return
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadOperationalIncident({ accountId, incidentId })
      setIncident(data.incident)
      setMembers(data.members)
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [accountId, incidentId])

  useEffect(() => { refresh() }, [refresh])
  return { incident, members, isLoading, error, refresh, setIncident }
}

