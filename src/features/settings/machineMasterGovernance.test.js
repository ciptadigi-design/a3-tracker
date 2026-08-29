import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const view = readFileSync(new URL('./MachineMasterGovernance.jsx', import.meta.url), 'utf8')
const page = readFileSync(new URL('../../pages/SettingsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/operationalMasters.js', import.meta.url), 'utf8')
const app = readFileSync(new URL('../../app/AppShell.jsx', import.meta.url), 'utf8')

test('Settings exposes compact Manufacturer and Model governance', () => {
  assert.match(view, /Manufacturers/)
  assert.match(view, /Models/)
  assert.match(view, /onCreate/)
  assert.match(view, /onEdit/)
  assert.match(view, /Archive/)
  assert.match(view, /Restore/)
  assert.match(view, /Platform shared master · read only/)
  assert.match(page, /machine-master-status/)
})

test('machine-master writes remain explicitly Platform Superuser governed', () => {
  assert.match(page, /const canManage = isPlatformSuperuser/)
  assert.match(app, /tenant\.isPlatformSuperuser/)
  assert.match(service, /MANUFACTURER_ALREADY_EXISTS/)
  assert.match(service, /MACHINE_MODEL_ALREADY_EXISTS/)
  assert.match(service, /PLATFORM_SUPERUSER_REQUIRED/)
  assert.doesNotMatch(app, /username\s*===\s*['"]admin|email\s*===\s*['"]|display_name\s*===\s*['"]admin/i)
})

test('archive guidance preserves historical machine evidence', () => {
  assert.match(page, /Archive its active Machine Models first/)
  assert.match(page, /Existing Machines, Model Profiles, components, and lifecycle history remain unchanged/)
  assert.match(page, /without deleting historical references/)
})
