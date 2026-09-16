import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMachineErrorCodes } from '../../services/maintenance.js'

export function useMachineErrorCodes(machineModelId) {
  const [errorCodes, setErrorCodes] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadMachineErrorCodes({ machineModelId })
      if (requestId.current === request) setErrorCodes(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [machineModelId])

  useEffect(() => { refresh() }, [refresh])
  return { errorCodes, isLoading, error, refresh }
}
