import { useEffect, useState } from 'react'

const supportedRoutes = new Set(['/', '/machines', '/daily', '/components', '/inventory', '/errors', '/maintenance', '/reports', '/settings'])
const machineDetailPattern = /^\/machines\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const incidentDetailPattern = /^\/errors\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const isSupportedPath = (path) => supportedRoutes.has(path) || machineDetailPattern.test(path) || incidentDetailPattern.test(path)
const readPath = () => isSupportedPath(window.location.pathname) ? window.location.pathname : '/'

export function useAppRoute() {
  const [path, setPath] = useState(readPath)
  useEffect(() => {
    const handlePopState = () => setPath(readPath())
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  function navigate(nextPath) {
    if (nextPath === path) return
    window.history.pushState({}, '', nextPath)
    setPath(nextPath)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  return { path, navigate }
}

export function getMachineIdFromPath(path) {
  return path.match(machineDetailPattern)?.[1] ?? null
}

export function getIncidentIdFromPath(path) {
  return path.match(incidentDetailPattern)?.[1] ?? null
}
