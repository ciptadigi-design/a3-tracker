import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const app = read('../../app/AppShell.jsx')
const sidebar = read('../../components/layout/Sidebar.jsx')
const tenant = read('../account/TenantProvider.jsx')
const settings = read('../../pages/SettingsPage.jsx')
const inventory = read('../../pages/InventoryPage.jsx')
const machines = read('../../pages/MachinesPage.jsx')
const overview = read('../../pages/OverviewPage.jsx')
const laravelSettings = read('../../services/laravel/settings.js')
const peopleService = read('../../services/laravel/operationalMasters.js')

test('E33–E38 Settings route, navigation, and sections use effective capabilities', () => {
  assert.match(sidebar, /can\('settings\.view'\) && <NavLink/)
  assert.doesNotMatch(sidebar, /isPlatformSuperuser.*settings\.view/)
  assert.match(app, /tenant\.can\('settings\.view'\)/)
  assert.match(settings, /availableSections = sections\.filter\(\(\[, , , capability\]\) => can\(capability\)\)/)
  for (const capability of ['account.manage', 'branches.manage', 'members.manage', 'settings.policy.manage', 'operational_people.manage', 'catalog.global.manage']) {
    assert.match(settings, new RegExp(capability.replace('.', '\\.')))
  }
})

test('E35 and E39 tenant member controls omit platform identity and privilege authority', () => {
  assert.match(settings, /canManageIdentity=\{isPlatformSuperuser\}/)
  assert.match(settings, /isPlatformSuperuser && <button[^>]+Change email/)
  assert.match(settings, /Existing identity credentials remain Platform-managed/)
  assert.match(settings, /Object\.entries\(roleLabels\)\.filter\(\(\[value\]\) => value !== 'owner'/)
  assert.doesNotMatch(settings, /platform_superuser.*option|Platform Superuser.*option/i)
  assert.match(settings, /'models'.*'catalog\.global\.manage'/)
})

test('E40–E45 mutations are account scoped and failed calls are not optimistic', () => {
  assert.match(laravelSettings, /accounts\/\$\{accountId\}\/profile/)
  assert.match(laravelSettings, /accounts\/\$\{accountId\}\/members\/\$\{member\.id\}/)
  assert.match(laravelSettings, /assignments: branchIds\.map/)
  assert.doesNotMatch(peopleService, /linked_user_id/)
  assert.match(settings, /catch \(caught\) \{ setError\(caught\) \}/)
  assert.match(app, /<SettingsPage key=\{tenant\.account\.id\}/)
  assert.match(tenant, /setTenantData\(null\)/)
})

test('E24–E32 zero-branch context and operational pages render setup-safe states', () => {
  assert.doesNotMatch(tenant, /if \(!availableBranches\.length\).*ErrorState/)
  assert.match(tenant, /if \(availableBranches\.length && !branch\)/)
  assert.match(overview, /Create your first branch/)
  assert.match(machines, /No branches yet\. Create the first branch in Settings/)
  assert.match(inventory, /if \(!branch\) return/)
  assert.match(inventory, /if \(!branch\?\.id\).*setData\(emptyInventoryData\(\)\)/s)
  assert.match(overview, /navigate\?\.\('\/settings'\)/)
  assert.match(machines, /navigate\('\/settings'\)/)
  assert.match(inventory, /navigate\?\.\('\/settings'\)/)
})

test('responsive Settings behavior remains covered by existing layout breakpoints', () => {
  const css = read('../../App.css')
  assert.match(css, /@media \(max-width: 767px\) \{[\s\S]*?\.settings-layout/)
  assert.match(css, /@media \(max-width: 767px\) \{[\s\S]*?\.settings-section-nav/)
})
