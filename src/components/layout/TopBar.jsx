import { ChevronDown, LogOut, Menu, Moon, Sun } from 'lucide-react'

const roles = { owner: 'Workspace owner', admin: 'Operational admin', technician: 'Technician', operator: 'Operator', platform_superuser: 'Platform superuser' }

export function TopBar({ profile, account, accounts, onAccountChange, branch, branches, onBranchChange, membership, theme, toggleTheme, onLogout, onMenu }) {
  return (
    <header className="topbar glass-surface">
      <button className="icon-button mobile-menu-button" type="button" onClick={onMenu} aria-label="Open navigation"><Menu size={20} /></button>
      <div className="topbar-context">
        {accounts.length > 1 ? <label className="branch-select"><span className="sr-only">Active workspace</span><select value={account?.id ?? ''} onChange={(event) => onAccountChange(event.target.value)}>{accounts.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><ChevronDown size={14} /></label> : <span>{account?.name}</span>}<span className="context-divider">/</span>
        {branches.length > 1 ? <label className="branch-select"><span className="sr-only">Active branch</span><select value={branch?.id ?? ''} onChange={(event) => onBranchChange(event.target.value)}>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><ChevronDown size={14} /></label> : <span>{branch?.name ?? 'No branch access'}</span>}
      </div>
      <div className="topbar-actions">
        <button className="icon-button" type="button" onClick={toggleTheme} aria-label={`Use ${theme === 'dark' ? 'light' : 'dark'} theme`}>{theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}</button>
        <div className="user-summary"><span className="user-avatar">{profile?.display_name?.slice(0, 1).toUpperCase() || 'U'}</span><div><strong>{profile?.display_name || 'User'}</strong><span>{roles[membership?.role] ?? 'Workspace member'}</span></div></div>
        <button className="icon-button logout-button" type="button" onClick={onLogout} aria-label="Sign out"><LogOut size={18} /></button>
      </div>
    </header>
  )
}
