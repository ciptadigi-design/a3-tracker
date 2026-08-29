import { AuthProvider } from './features/auth/AuthProvider.jsx'
import { useAuth } from './features/auth/useAuth.js'
import { LoginPage } from './features/auth/LoginPage.jsx'
import { TenantProvider } from './features/account/TenantProvider.jsx'
import { AppShell } from './app/AppShell.jsx'
import { LoadingScreen } from './components/ui/LoadingScreen.jsx'
import { PasswordSetupPage } from './features/auth/PasswordSetupPage.jsx'
import './App.css'

function AuthenticatedRoot() {
  const { session, isLoading, needsPasswordSetup } = useAuth()

  if (isLoading) return <LoadingScreen label="Restoring your secure workspace" />
  if (!session) return <LoginPage />
  if (needsPasswordSetup) return <PasswordSetupPage />

  return (
    <TenantProvider>
      <AppShell />
    </TenantProvider>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <AuthenticatedRoot />
    </AuthProvider>
  )
}
