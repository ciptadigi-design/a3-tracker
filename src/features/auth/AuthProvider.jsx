import { useEffect, useMemo, useState } from 'react'
import { supabase, supabaseConfigurationError } from '../../services/supabase/client.js'
import { AuthContext } from './authContext.js'
import { clearDraftsForUser } from '../drafts/draftStorage.js'
import { clearUIStateForUser } from '../uiState/uiStateStorage.js'

export function AuthProvider({ children }) {
  const [session, setSession] = useState(null)
  const [isLoading, setIsLoading] = useState(Boolean(supabase))
  const [needsPasswordSetup, setNeedsPasswordSetup] = useState(() => /type=(invite|recovery)/.test(window.location.hash + window.location.search))

  useEffect(() => {
    if (!supabase) {
      return undefined
    }

    let isMounted = true
    supabase.auth.getSession().then(({ data, error }) => {
      if (!isMounted) return
      if (error) console.error('Unable to restore Supabase session', error)
      setSession(data.session ?? null)
      setIsLoading(false)
    })

    const { data: authListener } = supabase.auth.onAuthStateChange((_event, nextSession) => {
      if (!isMounted) return
      setSession(nextSession)
      setIsLoading(false)
    })

    return () => {
      isMounted = false
      authListener.subscription.unsubscribe()
    }
  }, [])

  const value = useMemo(
    () => ({
      session,
      user: session?.user ?? null,
      isLoading,
      configurationError: supabaseConfigurationError,
      needsPasswordSetup,
      async signIn(identifier, password) {
        if (!supabase) throw new Error(supabaseConfigurationError)
        const { data, error } = await supabase.functions.invoke('auth-login', { body: { identifier, password } })
        if (error || !data?.access_token || !data?.refresh_token) throw new Error(data?.error || 'Invalid username/email or password.')
        const result = await supabase.auth.setSession({ access_token: data.access_token, refresh_token: data.refresh_token })
        if (result.error) throw new Error('Invalid username/email or password.')
        await supabase.rpc('accept_current_memberships')
      },
      async completePasswordSetup(password) {
        if (!supabase) throw new Error(supabaseConfigurationError)
        const { error } = await supabase.auth.updateUser({ password })
        if (error) throw error
        await supabase.rpc('accept_current_memberships')
        setNeedsPasswordSetup(false)
        window.history.replaceState({}, '', window.location.pathname)
      },
      async signOut() {
        if (!supabase) return
        const { error } = await supabase.auth.signOut()
        if (error) throw error
        clearDraftsForUser(session?.user?.id)
        clearUIStateForUser(session?.user?.id)
      },
    }),
    [isLoading, needsPasswordSetup, session],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
