import { AuthProvider } from './features/auth/AuthProvider.jsx'
import { useAuth } from './features/auth/useAuth.js'
import { LoginPage } from './features/auth/LoginPage.jsx'
import { TenantProvider } from './features/account/TenantProvider.jsx'
import { AppShell } from './app/AppShell.jsx'
import { LoadingScreen } from './components/ui/LoadingScreen.jsx'
import { PasswordSetupPage } from './features/auth/PasswordSetupPage.jsx'
import { AppErrorBoundary } from './components/ui/AppErrorBoundary.jsx'
import { getLiveDisplayMachineIdFromPath, isLiveDisplayRoute } from './hooks/useAppRoute.js'
import { LiveDisplayPage } from './pages/LiveDisplayPage.jsx'
import './App.css'

function AuthenticatedRoot() {
  const { session, isLoading, needsPasswordSetup } = useAuth()

  if (isLoading) return <LoadingScreen label="Restoring your secure workspace" />
  if (!session) return <LoginPage />
  if (needsPasswordSetup) return <PasswordSetupPage />

  // The live production TV display is a fixed deep link, not a page reached
  // through in-app navigation. It still needs auth/tenant/branch context
  // (TenantProvider), but intentionally never mounts AppShell - no sidebar,
  // no topbar, no management navigation belongs on a production-floor TV.
  if (isLiveDisplayRoute(window.location.pathname)) {
    return (
      <TenantProvider>
        <LiveDisplayPage machineId={getLiveDisplayMachineIdFromPath(window.location.pathname)} />
      </TenantProvider>
    )
  }

  return (
    <TenantProvider>
      <AppShell />
    </TenantProvider>
  )
}

export default function App() {
  return (
    <AppErrorBoundary>
      <AuthProvider>
        <AuthenticatedRoot />
      </AuthProvider>
    </AppErrorBoundary>
  )
}
