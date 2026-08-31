import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const sidebar = readFileSync(new URL('../../components/layout/Sidebar.jsx', import.meta.url), 'utf8')
const shell = readFileSync(new URL('../../app/AppShell.jsx', import.meta.url), 'utf8')
const css = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')

test('mobile drawer exposes a compact identity disclosure with collapsed actions', () => {
  assert.match(sidebar, /aria-label="Account"/)
  assert.match(sidebar, /aria-expanded=\{accountExpanded\}/)
  assert.match(sidebar, /setAccountExpanded\(\(current\) => !current\)/)
  assert.match(sidebar, /aria-hidden=\{!accountExpanded\}/)
  assert.match(sidebar, />My Account<\/span>/)
  assert.match(sidebar, />Logout<\/span>/)
  assert.match(shell, /onLogout=\{async \(\) => \{ setMobileNavOpen\(false\); await handleLogout\(\) \}\}/)
})

test('drawer My Account action uses canonical route', () => {
  assert.match(sidebar, /navigate\('\/my-account'\)/)
  assert.match(shell, /path === '\/my-account'/)
})

test('responsive account ownership is singular at desktop and tablet breakpoints', () => {
  assert.match(css, /\.sidebar-account \{ display: none;/)
  assert.match(css, /@media \(max-width: 1000px\)[\s\S]*?\.sidebar-account \{ display: block;/)
  assert.match(css, /@media \(max-width: 1000px\)[\s\S]*?\.topbar-user-menu \{ display: none; \}/)
})

test('desktop profile dropdown keeps canonical account actions', () => {
  const topbar = readFileSync(new URL('../../components/layout/TopBar.jsx', import.meta.url), 'utf8')
  assert.match(topbar, /aria-haspopup="menu"/)
  assert.match(topbar, /onMyAccount\(\)/)
  assert.match(topbar, /onLogout\(\)/)
  assert.match(topbar, />My Account<\/button>/)
  assert.match(topbar, />Sign out<\/button>/)
})
