import { ChevronDown, LogOut, Menu, Moon, Sun } from 'lucide-react'

export function TopBar({ profile, account, branch, branches, onBranchChange, theme, toggleTheme, onLogout, onMenu }) {
  return (
    <header className="topbar glass-surface">
      <button className="icon-button mobile-menu-button" type="button" onClick={onMenu} aria-label="Open navigation"><Menu size={20} /></button>
      <div className="topbar-context">
        <span>{account?.name}</span><span className="context-divider">/</span>
        <label className="branch-select"><span className="sr-only">Active branch</span>
          <select value={branch?.id ?? ''} onChange={(event) => onBranchChange(event.target.value)}>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><ChevronDown size={14} />
        </label>
      </div>
      <div className="topbar-actions">
        <button className="icon-button" type="button" onClick={toggleTheme} aria-label={`Use ${theme === 'dark' ? 'light' : 'dark'} theme`}>{theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}</button>
        <div className="user-summary"><span className="user-avatar">{profile?.display_name?.slice(0, 1).toUpperCase() || 'U'}</span><div><strong>{profile?.display_name || 'Account owner'}</strong><span>Workspace owner</span></div></div>
        <button className="icon-button logout-button" type="button" onClick={onLogout} aria-label="Sign out"><LogOut size={18} /></button>
      </div>
    </header>
  )
}
