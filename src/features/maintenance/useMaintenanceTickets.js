import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMaintenanceTickets } from '../../services/maintenance.js'

export function useMaintenanceTickets(accountId, { machineId, status } = {}) {
  const [tickets, setTickets] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!accountId) { setTickets([]); setIsLoading(false); return }
    setIsLoading(true)
    setError(null)
    try {
      const page = await loadMaintenanceTickets({ machineId, status })
      if (requestId.current === request) setTickets(Array.isArray(page?.data) ? page.data : [])
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [accountId, machineId, status])

  useEffect(() => { refresh() }, [refresh])
  return { tickets, isLoading, error, refresh }
}
