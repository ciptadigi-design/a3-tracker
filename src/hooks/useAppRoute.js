import { useEffect, useState } from 'react'

const supportedRoutes = new Set(['/', '/machines', '/daily', '/components', '/inventory', '/machine-cost', '/errors', '/maintenance', '/maintenance/troubleshooting', '/maintenance/tickets', '/maintenance/documents', '/reports', '/settings', '/settings/machine-models'])
const machineDetailPattern = /^\/machines\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const incidentDetailPattern = /^\/errors\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const maintenanceTicketDetailPattern = /^\/maintenance\/tickets\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const troubleshootingDetailPattern = /^\/maintenance\/troubleshooting\/([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const isSupportedPath = (path) => supportedRoutes.has(path) || machineDetailPattern.test(path) || incidentDetailPattern.test(path) || maintenanceTicketDetailPattern.test(path) || troubleshootingDetailPattern.test(path)
const readLocation = () => isSupportedPath(window.location.pathname) ? { path: window.location.pathname, search: window.location.search } : { path: '/', search: '' }

export function useAppRoute() {
  const [location, setLocation] = useState(readLocation)
  useEffect(() => {
    const handlePopState = () => setLocation(readLocation())
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  function navigate(nextPath) {
    const next = new URL(nextPath, window.location.origin)
    if (next.pathname === location.path && next.search === location.search) return false
    window.history.pushState({}, '', `${next.pathname}${next.search}`)
    setLocation({ path: next.pathname, search: next.search })
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return true
  }

  return { ...location, navigate }
}

export function getMachineIdFromPath(path) {
  return path.match(machineDetailPattern)?.[1] ?? null
}

export function getIncidentIdFromPath(path) {
  return path.match(incidentDetailPattern)?.[1] ?? null
}

export function getMaintenanceTicketIdFromPath(path) {
  return path.match(maintenanceTicketDetailPattern)?.[1] ?? null
}

export function getTroubleshootingEntryIdFromPath(path) {
  return path.match(troubleshootingDetailPattern)?.[1] ?? null
}
