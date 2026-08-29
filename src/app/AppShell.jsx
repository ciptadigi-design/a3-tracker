import { useState } from 'react'
import { Sidebar } from '../components/layout/Sidebar.jsx'
import { TopBar } from '../components/layout/TopBar.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { getIncidentIdFromPath, getMachineIdFromPath, useAppRoute } from '../hooks/useAppRoute.js'
import { useTheme } from '../hooks/useTheme.js'
import { OverviewPage } from '../pages/OverviewPage.jsx'
import { MachinesPage } from '../pages/MachinesPage.jsx'
import { DailyPage } from '../pages/DailyPage.jsx'
import { ComingSoonPage } from '../pages/ComingSoonPage.jsx'
import { MachineDetailPage } from '../features/machines/MachineDetailPage.jsx'
import { ErrorsPage } from '../pages/ErrorsPage.jsx'
import { IncidentDetailPage } from '../features/incidents/IncidentDetailPage.jsx'
import { ComponentsPage } from '../pages/ComponentsPage.jsx'
import { SettingsPage } from '../pages/SettingsPage.jsx'
import { InventoryPage } from '../pages/InventoryPage.jsx'
import { MachineCostPage } from '../pages/MachineCostPage.jsx'
import { ReportsPage } from '../pages/ReportsPage.jsx'
import { MyAccountPage } from '../pages/MyAccountPage.jsx'
import { userErrorMessage } from '../lib/appErrors.js'

const comingSoonPages = {
  '/maintenance': ['Maintenance', 'Maintenance planning will arrive after machine onboarding.'],
}

export function AppShell() {
  const { signOut } = useAuth()
  const tenant = useTenant()
  const { path, navigate } = useAppRoute()
  const { theme, toggleTheme } = useTheme()
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [logoutError, setLogoutError] = useState(null)

  async function handleLogout() {
    setLogoutError(null)
    try { await signOut() } catch (error) { setLogoutError(userErrorMessage(error, 'Sign out could not be completed. Please try again.')) }
  }

  function handleNavigate(nextPath) { navigate(nextPath); setMobileNavOpen(false) }

  let page
  if (path === '/') page = <OverviewPage />
  else if (path === '/machines') page = <MachinesPage navigate={handleNavigate} />
  else if (getMachineIdFromPath(path)) page = <MachineDetailPage machineId={getMachineIdFromPath(path)} navigate={handleNavigate} />
  else if (path === '/daily') page = <DailyPage />
  else if (path === '/errors') page = <ErrorsPage navigate={handleNavigate} />
  else if (path === '/components') page = <ComponentsPage />
  else if (path === '/inventory') page = <InventoryPage />
  else if (path === '/machine-cost') page = <MachineCostPage />
  else if (path === '/reports') page = <ReportsPage />
  else if (path === '/my-account') page = <MyAccountPage />
  else if ((path === '/settings' || path === '/settings/machine-models') && tenant.isPlatformSuperuser) page = <SettingsPage navigate={handleNavigate} initialSection={path === '/settings/machine-models' ? 'models' : null} />
  else if (path === '/settings' || path === '/settings/machine-models') page = <ComingSoonPage title="Access denied" description="Settings is temporarily available only to Platform Superusers." />
  else if (getIncidentIdFromPath(path)) page = <IncidentDetailPage incidentId={getIncidentIdFromPath(path)} navigate={handleNavigate} />
  else {
    const [title, description] = comingSoonPages[path] ?? ['Page not found', 'This route is not available.']
    page = <ComingSoonPage title={title} description={description} />
  }

  return (
    <div className="app-frame">
      <div className="app-ambient app-ambient-one" /><div className="app-ambient app-ambient-two" />
      <div className={`mobile-nav-backdrop ${mobileNavOpen ? 'mobile-nav-backdrop-open' : ''}`} onClick={() => setMobileNavOpen(false)} />
      <div className={`sidebar-wrap ${mobileNavOpen ? 'sidebar-wrap-open' : ''}`}><Sidebar path={path} navigate={handleNavigate} account={tenant.account} branch={tenant.branch} membership={tenant.membership} isPlatformSuperuser={tenant.isPlatformSuperuser} /></div>
      <div className="app-main">
        <TopBar profile={tenant.profile} account={tenant.account} accounts={tenant.accounts} onAccountChange={tenant.setSelectedAccountId} branch={tenant.branch} branches={tenant.branches} onBranchChange={tenant.setSelectedBranchId} membership={tenant.membership} theme={theme} toggleTheme={toggleTheme} onLogout={handleLogout} onMyAccount={() => handleNavigate('/my-account')} onMenu={() => setMobileNavOpen(true)} />
        {logoutError && <div className="inline-error" role="alert">{logoutError}</div>}
        <main className="page-content" key={`${tenant.account?.id ?? 'account'}:${tenant.branch?.id ?? 'branch'}`}>{page}</main>
      </div>
    </div>
  )
}
