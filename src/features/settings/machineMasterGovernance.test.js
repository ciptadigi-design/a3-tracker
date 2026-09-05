import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const view = readFileSync(new URL('./MachineMasterGovernance.jsx', import.meta.url), 'utf8')
const page = readFileSync(new URL('../../pages/SettingsPage.jsx', import.meta.url), 'utf8')
const service = readFileSync(new URL('../../services/supabase/operationalMasters.js', import.meta.url), 'utf8')
const app = readFileSync(new URL('../../app/AppShell.jsx', import.meta.url), 'utf8')
const styles = readFileSync(new URL('../../App.css', import.meta.url), 'utf8')
const settingsService = readFileSync(new URL('../../services/supabase/settings.js', import.meta.url), 'utf8')

test('Settings exposes compact Manufacturer and Model governance', () => {
  assert.match(view, /Manufacturers/)
  assert.match(view, /Models/)
  assert.match(view, /onCreate/)
  assert.match(view, /onEdit/)
  assert.match(view, /Archive/)
  assert.match(view, /Restore/)
  assert.match(view, /Platform shared master/)
  assert.match(page, /machine-master-status/)
})

test('active tab controls the compact primary CTA and both empty states are explicit', () => {
  assert.match(view, /view === 'manufacturers' \? 'Manufacturer' : 'Model'/)
  assert.match(view, /onCreate\(kind\)/)
  assert.match(view, /No manufacturers have been added yet\./)
  assert.match(view, /No machine models have been added yet\./)
  assert.match(view, /Create a Manufacturer first/)
})

test('Manufacturer and Model rows share one aligned identity, fact, status, and action structure', () => {
  assert.match(view, /machine-master-identity/)
  assert.match(view, /machine-master-fact/)
  assert.match(view, /manufacturer\?\.name/)
  assert.match(view, /scope-pill/)
  assert.match(view, /machine-master-actions/)
  assert.match(view, /const manufacturer = view === 'models' \? record\.manufacturer : null/)
  assert.match(view, /aria-label={`Edit \$\{record\.name\}`}/)
  assert.match(view, /aria-label={`\$\{record\.is_active \? 'Archive' : 'Restore'\} \$\{record\.name\}`}/)
})

test('every row — including migrated Platform shared masters — exposes the same edit action to a Superuser', () => {
  assert.doesNotMatch(view, /shared \? <>.*<\/> : <>/)
  assert.doesNotMatch(view, /span aria-hidden="true"/)
})

test('Machine Models layout becomes a stacked mobile list without horizontal grid dependence', () => {
  assert.match(view, /machine-master-content/)
  assert.match(styles, /\.machine-master-content \{ padding: 0 24px 18px;/)
  assert.match(styles, /\.machine-master-content \{ padding: 0 20px 16px;/)
  assert.match(styles, /\.machine-master-content \{ padding: 0 14px 14px;/)
  assert.match(styles, /\.machine-master-list article \{ grid-template-columns: auto minmax\(0,1fr\) auto;/)
  assert.match(styles, /\.machine-master-list \.machine-master-fact \{ grid-column: 2;/)
  assert.match(styles, /\.machine-master-list \.machine-master-actions \{ width: 71px; grid-column: 2 \/ -1;/)
})

test('Component Model Profiles is a subordinate compact card with truthful model-level metric', () => {
  assert.match(page, /model-profile-settings-card/)
  assert.match(page, /Manage Profiles/)
  assert.match(page, /active.*slots.*across.*models/)
  assert.match(settingsService, /machine_model_id/)
  assert.doesNotMatch(page, />Manage Model Profiles</)
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
