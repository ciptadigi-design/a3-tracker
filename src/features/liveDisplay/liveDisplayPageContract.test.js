import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'

const pagePath = new URL('../../pages/LiveDisplayPage.jsx', import.meta.url)
const page = fs.readFileSync(pagePath, 'utf8')
const app = fs.readFileSync(new URL('../../App.jsx', import.meta.url), 'utf8')
const hook = fs.readFileSync(new URL('./useLiveDisplayData.js', import.meta.url), 'utf8')
const activityStrip = fs.readFileSync(new URL('./RecentActivityStrip.jsx', import.meta.url), 'utf8')

test('the live display page never renders normal app chrome', () => {
  assert.doesNotMatch(page, /Sidebar/)
  assert.doesNotMatch(page, /TopBar/)
})

test('App.jsx mounts the live display outside AppShell, inside auth/tenant context', () => {
  const liveBranchStart = app.indexOf('isLiveDisplayRoute(')
  const liveBranchEnd = app.indexOf('return (\n    <TenantProvider>\n      <AppShell', liveBranchStart)
  assert.ok(liveBranchStart >= 0, 'App.jsx must branch on isLiveDisplayRoute before mounting AppShell')
  const liveBranch = app.slice(liveBranchStart, liveBranchEnd)
  assert.match(liveBranch, /<TenantProvider>/)
  assert.match(liveBranch, /<LiveDisplayPage/)
  assert.doesNotMatch(liveBranch, /<AppShell/)
})

test('machineId is required and never silently falls back to another machine', () => {
  assert.match(page, /isValidMachineId/)
  assert.doesNotMatch(page, /activeMachines\[0\]/)
  assert.doesNotMatch(page, /machines\[0\]/)
  assert.doesNotMatch(page, /machines\.find/)
})

test('the display forces its own dark surface rather than mutating the global theme', () => {
  assert.match(page, /live-display-root/)
  assert.doesNotMatch(page, /useTheme/)
  assert.doesNotMatch(page, /toggleTheme/)
  assert.doesNotMatch(page, /localStorage/)
})

test('out-of-scope management features are not present on the display', () => {
  for (const excluded of [/Purchase Value/, /Component Consumption/, /Maintenance/, /Inventory/, /<form/i, /SettingsIcon/, /machine-selector/]) {
    assert.doesNotMatch(page, excluded)
  }
})

test('the data hook cleans up its visibility listener and stops the poller on unmount', () => {
  assert.match(hook, /addEventListener\('visibilitychange'/)
  assert.match(hook, /removeEventListener\('visibilitychange'/)
  assert.match(hook, /poller\.stop\(\)/)
  assert.match(hook, /createLivePoller/)
})

test('visibility changes pause and resume the poller rather than tearing it down', () => {
  assert.match(hook, /poller\.pause\(\)/)
  assert.match(hook, /poller\.resume\(\)/)
})

test('a failed refresh never clears previously loaded data in the hook', () => {
  // The failure branch must spread the previous state (keeping history/projection) rather than resetting it.
  assert.match(hook, /setState\(\(previous\) => \(\{\s*\n?\s*\.\.\.previous,/)
})

test("the hook's initial status is LOADING before the first fetch resolves", () => {
  assert.match(hook, /status:\s*'loading'/)
})

test('recent activity rows use the reading id as a stable React key, not the array index', () => {
  assert.match(activityStrip, /key=\{row\.id\}/)
})

test('the live display loads data through the existing authenticated/tenant-scoped service layer, not a new endpoint', () => {
  assert.match(hook, /from '\.\.\/\.\.\/services\/counters\.js'/)
  assert.match(hook, /from '\.\.\/\.\.\/services\/clickTargets\.js'/)
  assert.match(page, /useMachine\(account\?\.id, branch\?\.id,/)
})
