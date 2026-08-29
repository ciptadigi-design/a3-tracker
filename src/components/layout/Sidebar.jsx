import { AlertTriangle, BarChart3, Boxes, CalendarDays, ChevronRight, CircleDollarSign, ClipboardCheck, Gauge, Package, Printer, Settings, Wrench } from 'lucide-react'

const navigation = [
  { path: '/', label: 'Overview', icon: Gauge, active: true },
  { path: '/machines', label: 'Machines', icon: Printer, active: true },
  { path: '/daily', label: 'Daily', icon: CalendarDays, active: true },
  { path: '/components', label: 'Components', icon: Boxes, active: true },
  { path: '/inventory', label: 'Inventory', icon: Package, active: true },
  { path: '/machine-cost', label: 'Machine Cost', icon: CircleDollarSign, active: true },
  { path: '/errors', label: 'Errors', icon: AlertTriangle, active: true },
  { path: '/maintenance', label: 'Maintenance', icon: Wrench },
  { path: '/reports', label: 'Reports', icon: BarChart3, active: true },
]

function NavLink({ item, path, navigate }) {
  const Icon = item.icon
  const isCurrent = path === item.path || (item.path !== '/' && path.startsWith(`${item.path}/`))
  return (
    <a href={item.path} className={`nav-item ${isCurrent ? 'nav-item-active' : ''}`} aria-current={isCurrent ? 'page' : undefined} onClick={(event) => { event.preventDefault(); navigate(item.path) }}>
      <Icon size={19} strokeWidth={1.8} /><span>{item.label}</span>{!item.active && <small>Soon</small>}
    </a>
  )
}

export function Sidebar({ path, navigate, account, branch, membership }) {
  return (
    <aside className="sidebar glass-surface">
      <div className="brand-lockup sidebar-brand"><span className="brand-mark"><Printer size={22} strokeWidth={1.8} /></span><span>A3 Tracker</span></div>
      <div className="workspace-card">
        <span className="workspace-avatar">{account?.name?.slice(0, 2).toUpperCase()}</span>
        <div><strong>{account?.name}</strong><span>{branch?.name ?? 'No active branch'}</span></div><ChevronRight size={16} />
      </div>
      <nav className="primary-nav" aria-label="Primary navigation">
        <span className="nav-label">Workspace</span>
        {navigation.map((item) => <NavLink key={item.path} item={item} path={path} navigate={navigate} />)}
      </nav>
      <nav className="secondary-nav" aria-label="Settings navigation">
        {['owner', 'admin'].includes(membership?.role) && <NavLink item={{ path: '/settings', label: 'Settings', icon: Settings, active: true }} path={path} navigate={navigate} />}
        <div className="sidebar-footnote"><ClipboardCheck size={15} /> Secure tenant access</div>
      </nav>
    </aside>
  )
}
