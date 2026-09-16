import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMaintenanceTicket } from '../../services/maintenance.js'

export function useMaintenanceTicket(ticketId) {
  const [ticket, setTicket] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async ({ silent = false } = {}) => {
    const request = ++requestId.current
    if (!ticketId) { setTicket(null); setIsLoading(false); return }
    if (!silent) setIsLoading(true)
    setError(null)
    try {
      const data = await loadMaintenanceTicket(ticketId)
      if (requestId.current === request) setTicket(data)
      return data
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (!silent && requestId.current === request) setIsLoading(false)
    }
  }, [ticketId])

  useEffect(() => { refresh() }, [refresh])
  return { ticket, isLoading, error, refresh, setTicket }
}
