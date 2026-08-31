import { useEffect, useMemo, useState } from 'react'
import { completePasswordSetup, restoreSession, signIn, signOut, subscribeToSession } from '../../services/auth.js'
import { AuthContext } from './authContext.js'
import { clearDraftsForUser } from '../drafts/draftStorage.js'
import { clearUIStateForUser } from '../uiState/uiStateStorage.js'
import { reportFailure } from '../../lib/appErrors.js'

// Supabase reference mode remains available in services/supabase/auth.js (functions.invoke('auth-login')).

export function AuthProvider({ children }) {
  const [session, setSession] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [configurationError, setConfigurationError] = useState(null)
  const [needsPasswordSetup, setNeedsPasswordSetup] = useState(() => /type=(invite|recovery)/.test(window.location.hash + window.location.search))

  useEffect(() => {
    let isMounted = true
    restoreSession().then((nextSession) => {
      if (!isMounted) return
      setSession(nextSession)
      setIsLoading(false)
    }).catch((error) => { if (isMounted) { reportFailure(error, { operation: 'auth.session.restore' }); setConfigurationError('Authentication service is unavailable. No fallback backend was used.'); setIsLoading(false) } })

    const unsubscribe = subscribeToSession((nextSession) => {
      if (!isMounted) return
      setSession(nextSession)
      setIsLoading(false)
    })

    return () => {
      isMounted = false
      unsubscribe()
    }
  }, [])

  const value = useMemo(
    () => ({
      session,
      user: session?.user ?? null,
      isLoading,
      configurationError,
      needsPasswordSetup,
      async signIn(identifier, password) {
        const nextSession = await signIn(identifier, password)
        setSession(nextSession)
      },
      async completePasswordSetup(password) {
        const nextSession = await completePasswordSetup(password)
        setSession(nextSession)
        setNeedsPasswordSetup(false)
        window.history.replaceState({}, '', window.location.pathname)
      },
      async signOut() {
        await signOut()
        setSession(null)
        clearDraftsForUser(session?.user?.id)
        clearUIStateForUser(session?.user?.id)
      },
    }),
    [configurationError, isLoading, needsPasswordSetup, session],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
