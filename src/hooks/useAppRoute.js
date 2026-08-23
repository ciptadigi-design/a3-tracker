import { useEffect, useState } from 'react'

const supportedRoutes = new Set(['/', '/machines', '/daily', '/components', '/inventory', '/errors', '/maintenance', '/reports', '/settings'])
const readPath = () => supportedRoutes.has(window.location.pathname) ? window.location.pathname : '/'

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
