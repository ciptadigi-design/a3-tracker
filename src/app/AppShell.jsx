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

const comingSoonPages = {
  '/maintenance': ['Maintenance', 'Maintenance planning will arrive after machine onboarding.'],
  '/reports': ['Reports', 'Reports will be built from real operational data as modules come online.'],
  '/settings': ['Settings', 'Operational workspace settings.'],
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
    try { await signOut() } catch (error) { setLogoutError(error.message) }
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
  else if (path === '/settings') page = <SettingsPage />
  else if (getIncidentIdFromPath(path)) page = <IncidentDetailPage incidentId={getIncidentIdFromPath(path)} navigate={handleNavigate} />
  else {
    const [title, description] = comingSoonPages[path] ?? comingSoonPages['/settings']
    page = <ComingSoonPage title={title} description={description} />
  }

  return (
    <div className="app-frame">
      <div className="app-ambient app-ambient-one" /><div className="app-ambient app-ambient-two" />
      <div className={`mobile-nav-backdrop ${mobileNavOpen ? 'mobile-nav-backdrop-open' : ''}`} onClick={() => setMobileNavOpen(false)} />
      <div className={`sidebar-wrap ${mobileNavOpen ? 'sidebar-wrap-open' : ''}`}><Sidebar path={path} navigate={handleNavigate} account={tenant.account} branch={tenant.branch} /></div>
      <div className="app-main">
        <TopBar profile={tenant.profile} account={tenant.account} branch={tenant.branch} branches={tenant.branches} onBranchChange={tenant.setSelectedBranchId} theme={theme} toggleTheme={toggleTheme} onLogout={handleLogout} onMenu={() => setMobileNavOpen(true)} />
        {logoutError && <div className="inline-error" role="alert">{logoutError}</div>}
        <main className="page-content">{page}</main>
      </div>
    </div>
  )
}
