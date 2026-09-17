import { useCallback, useEffect, useRef, useState } from 'react'
import { loadMaintenanceDocuments } from '../../../services/maintenance.js'

export function useMaintenanceDocuments({ search, machineModelId, manufacturerId, documentType, status } = {}) {
  const [documents, setDocuments] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const requestId = useRef(0)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    setIsLoading(true)
    setError(null)
    try {
      const data = await loadMaintenanceDocuments({ search, machineModelId, manufacturerId, documentType, status })
      if (requestId.current === request) setDocuments(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [search, machineModelId, manufacturerId, documentType, status])

  useEffect(() => { refresh() }, [refresh])
  return { documents, isLoading, error, refresh }
}
