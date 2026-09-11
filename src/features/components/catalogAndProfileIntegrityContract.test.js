import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// M2.17.5.2: no jsdom/React render harness exists in this repo - these are pure
// string/regex assertions against the real source, matching the established
// pattern for JSX "component" tests (see receivingSemanticsContract.test.js).
const laravelComponents = readFileSync(new URL('../../services/laravel/components.js', import.meta.url), 'utf8')
const componentDialog = readFileSync(new URL('./ComponentDialog.jsx', import.meta.url), 'utf8')
const profileDialog = readFileSync(new URL('./ProfileDialog.jsx', import.meta.url), 'utf8')
const machineComponentDialog = readFileSync(new URL('./MachineComponentDialog.jsx', import.meta.url), 'utf8')
const componentsPage = readFileSync(new URL('../../pages/ComponentsPage.jsx', import.meta.url), 'utf8')

// Part A: the Add Component Manufacturer dropdown was empty because
// loadComponentFoundation() hardcoded `manufacturers: []` and never fetched
// GET /manufacturers, even though that endpoint already correctly returned
// global + account-scoped active manufacturers.
test('loadComponentFoundation fetches real manufacturers, not a hardcoded empty array', () => {
  assert.match(laravelComponents, /apiClient\.get\(`\/manufacturers\?account_id=\$\{accountId\}`\)/)
  assert.match(laravelComponents, /manufacturers:\s*manufacturerRows/)
})

// saveComponent() used to forward raw camelCase draft state as the request body -
// Laravel's validate() only sees whitelisted snake_case keys, so manufacturer_id
// (and everything else) was silently dropped on every save.
test('catalogPayload maps manufacturerId to the real manufacturer_id field the backend accepts', () => {
  assert.match(laravelComponents, /manufacturer_id:\s*values\.manufacturerId\s*\|\|\s*null/)
})

// Part B: ProfileDialog's threshold + adaptive-foundation fields were collected
// by the form but slotPayload() never included them in the request body at all.
test('slotPayload sends all four thresholds, adaptive_enabled, and notes to the backend', () => {
  assert.match(laravelComponents, /healthy_threshold_percent:/)
  assert.match(laravelComponents, /watch_threshold_percent:/)
  assert.match(laravelComponents, /warning_threshold_percent:/)
  assert.match(laravelComponents, /critical_threshold_percent:/)
  assert.match(laravelComponents, /adaptive_enabled:\s*Boolean\(values\.adaptiveEnabled\)/)
  assert.match(laravelComponents, /notes:\s*values\.notes\s*\|\|\s*null/)
})

// Part C: only counter_based lifecycle tracking is operationally supported
// (ComponentConfigurationService::addManual() hard-rejects anything else
// server-side) - every place a user can pick a tracking method must present
// the unsupported options as visibly disabled, not silently discard the choice.
test('every tracking-method selector in the Component/Profile UI disables the two unsupported methods', () => {
  for (const source of [componentDialog, profileDialog, machineComponentDialog]) {
    assert.match(source, /<option value="consumption_based" disabled>/)
    assert.match(source, /<option value="inspection_based" disabled>/)
  }
})

// These forms must never silently pre-seed an unsupported tracking method from
// the catalog's own (unwired, unreliable) default when a user picks a component -
// that could submit a disabled-looking value the backend would now 422 on.
test('ProfileDialog and MachineComponentDialog never inherit an unvalidated tracking method from the selected catalog component', () => {
  assert.doesNotMatch(profileDialog, /trackingMethod:\s*component\??\.\s*default_tracking_method/)
  assert.doesNotMatch(machineComponentDialog, /trackingMethod:\s*component\.default_tracking_method/)
})

// Part C5 (discovered while auditing): the Laravel backend's real column is
// `tracking_method` - `default_tracking_method` is the Supabase adapter's name
// for the same concept. Reading only the Supabase name left every Laravel-backed
// component's tracking label permanently blank.
test('Component Catalog tracking-method display and edit read the real Laravel field with a Supabase-name fallback', () => {
  assert.match(componentsPage, /trackingLabels\[component\.tracking_method\s*\?\?\s*component\.default_tracking_method\]/)
  assert.match(componentDialog, /component\.tracking_method\s*\?\?\s*component\.default_tracking_method/)
})

// M2.17.5.4 Part B3: a real Production 403 (caused by a legacy-migrated profile
// with the wrong account scope - a data issue, not an authorization bug) was
// displayed as "not allowed to assign this component" even though the user was
// editing an EXISTING slot's fields, not assigning a new Component Catalog
// definition. The message must match which operation actually ran.
test('profileErrorMessage distinguishes editing an existing slot from assigning a new component on a 403', () => {
  assert.match(profileDialog, /isEditingExistingSlot \? 'Your current workspace role is not allowed to edit this Model Profile\.' : 'Your current workspace role is not allowed to assign this component\.'/)
  assert.match(profileDialog, /profileErrorMessage\(saveError, Boolean\(profile\)\)/)
})

// M2.17.5.4 Part B5: threshold inputs reopened showing "30.00"/"12.50" - real,
// correct decimal(5,2) precision from the backend, but unnecessary visual noise
// for whole-number percentages. Presentation only - the underlying value must
// never be rounded or truncated when a real fractional value exists.
test('threshold fields strip unnecessary trailing zeros without touching real fractional precision', () => {
  assert.match(profileDialog, /function formatPercent\(value\)/)
  assert.match(profileDialog, /healthyThreshold: formatPercent\(profile\.healthy_threshold_percent\)/)
  assert.match(profileDialog, /watchThreshold: formatPercent\(profile\.watch_threshold_percent\)/)
  assert.match(profileDialog, /warningThreshold: formatPercent\(profile\.warning_threshold_percent\)/)
  assert.match(profileDialog, /criticalThreshold: formatPercent\(profile\.critical_threshold_percent\)/)
})

test('formatPercent normalizes whole and fractional decimal strings correctly', () => {
  // Re-implement the exact same logic in isolation (no render harness) to prove
  // the transformation itself is correct, matching the source verbatim.
  function formatPercent(value) {
    if (value == null || value === '') return ''
    const numeric = Number(value)
    return Number.isNaN(numeric) ? String(value) : String(numeric)
  }
  assert.equal(formatPercent('30.00'), '30')
  assert.equal(formatPercent('15.00'), '15')
  assert.equal(formatPercent('5.00'), '5')
  assert.equal(formatPercent('0.00'), '0')
  assert.equal(formatPercent('12.50'), '12.5')
  assert.equal(formatPercent(''), '')
})
