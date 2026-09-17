import { useCallback, useEffect, useRef, useState } from 'react'
import { loadDocumentExtraction, startDocumentExtraction } from '../../../services/maintenance.js'
import { mapMaintenanceError } from '../maintenanceUtils.js'

const POLL_INTERVAL_MS = 3000

// No polling pattern exists anywhere else in this app - every other status-driven
// screen (knowledge imports, tickets) is refreshed by the user re-opening/acting
// on it. Extraction is different: it runs in the background (a queue job), so the
// UI needs to notice PENDING/PROCESSING moving to COMPLETED/FAILED on its own.
// Polling stops the moment a terminal status is reached - never runs indefinitely.
export function useDocumentExtraction(documentId) {
  const [extraction, setExtraction] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [startError, setStartError] = useState(null)
  const [isStarting, setIsStarting] = useState(false)
  const requestId = useRef(0)
  const timerRef = useRef(null)

  const refresh = useCallback(async () => {
    const request = ++requestId.current
    if (!documentId) { setExtraction(null); setIsLoading(false); return }
    try {
      const data = await loadDocumentExtraction(documentId)
      if (requestId.current === request) setExtraction(data)
    } catch (loadError) {
      if (requestId.current === request) setError(loadError)
    } finally {
      if (requestId.current === request) setIsLoading(false)
    }
  }, [documentId])

  useEffect(() => { refresh() }, [refresh])

  useEffect(() => {
    const isActive = extraction && (extraction.status === 'PENDING' || extraction.status === 'PROCESSING')
    if (!isActive) return undefined
    timerRef.current = setTimeout(refresh, POLL_INTERVAL_MS)
    return () => clearTimeout(timerRef.current)
  }, [extraction, refresh])

  async function start() {
    if (isStarting || !documentId) return
    setIsStarting(true)
    setStartError(null)
    try {
      const created = await startDocumentExtraction(documentId)
      setExtraction(created)
    } catch (startErrorValue) {
      setStartError(mapMaintenanceError(startErrorValue))
    } finally {
      setIsStarting(false)
    }
  }

  return { extraction, isLoading, error, refresh, start, isStarting, startError }
}
