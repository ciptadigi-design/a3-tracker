import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const sidebar = readFileSync(new URL('../../components/layout/Sidebar.jsx', import.meta.url), 'utf8')
const shell = readFileSync(new URL('../../app/AppShell.jsx', import.meta.url), 'utf8')

test('mobile drawer exposes identity, My Account, and backend-neutral Logout', () => {
  assert.match(sidebar, /aria-label="Account"/)
  assert.match(sidebar, />My Account<\/span>/)
  assert.match(sidebar, />Logout<\/span>/)
  assert.match(shell, /onLogout=\{async \(\) => \{ setMobileNavOpen\(false\); await handleLogout\(\) \}\}/)
})

test('drawer My Account action uses canonical route', () => {
  assert.match(sidebar, /navigate\('\/my-account'\)/)
  assert.match(shell, /path === '\/my-account'/)
})
