import { useEffect, useMemo, useState } from 'react'
import { completePasswordSetup, restoreSession, signIn, signOut, signOutLocal, subscribeToSession } from '../../services/auth.js'
import { AuthContext } from './authContext.js'
import { clearDraftsForUser } from '../drafts/draftStorage.js'
import { clearUIStateForUser } from '../uiState/uiStateStorage.js'
import { reportFailure } from '../../lib/appErrors.js'
import { setPostLoginNotice } from './postLoginNotice.js'

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
      // Ends the current session immediately after the signed-in user changed their
      // OWN password or email. That credential change already invalidated the
      // server-side session, so no further authenticated request should be attempted
      // (e.g. a Settings/tenant refetch) before the client transitions to Login —
      // doing so surfaces a misleading "could not be loaded" error instead of a
      // clean sign-out. This intentionally does NOT run for an admin resetting
      // ANOTHER member's credential; that flow keeps the admin's own session.
      async endSessionForOwnCredentialChange(noticeMessage = 'Your password was changed. Please sign in again.') {
        const userId = session?.user?.id
        await signOutLocal()
        clearDraftsForUser(userId)
        clearUIStateForUser(userId)
        setPostLoginNotice(noticeMessage)
        setSession(null)
      },
    }),
    [configurationError, isLoading, needsPasswordSetup, session],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
