import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMachineErrorCodes } from '../../services/maintenance.js'

// Accepts either a bare machineModelId (CreateTicketDialog's narrow "codes for this
// machine" lookup) or an options object ({ machineModelId, search }) for the broader
// Error Code knowledge/search section - one hook, two callers, no duplicated fetch logic.
export function useMachineErrorCodes(machineModelIdOrOptions) {
  const options = typeof machineModelIdOrOptions === 'string' || machineModelIdOrOptions == null
    ? { machineModelId: machineModelIdOrOptions }
    : machineModelIdOrOptions
  const { machineModelId, search } = options
  const [errorCodes, setErrorCodes] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadMachineErrorCodes({ machineModelId, search })
      if (requestId.current === request) setErrorCodes(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [machineModelId, search])

  useEffect(() => { refresh() }, [refresh])
  return { errorCodes, isLoading, error, refresh }
}
