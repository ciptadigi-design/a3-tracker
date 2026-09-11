import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

// M2.17.5.2: the main Supplier Master surface used to show two large primary
// actions ("Attach existing supplier…" and "+ Add supplier"). This suite proves
// the simplification down to one primary action ("+ Add supplier"), while the
// underlying M2.17.4.1 anti-duplicate / cross-branch discovery capability from
// AttachExistingSupplier is preserved - relocated into the Add Supplier dialog
// itself, reached by exact-name discovery on submit and by a secondary link, not
// deleted. No jsdom/React render harness exists in this repo, so these are source
// string/regex contract assertions (see receivingSemanticsContract.test.js for the
// established pattern).
const purchasingPanel = readFileSync(new URL('./PurchasingPanel.jsx', import.meta.url), 'utf8')
const purchasingDialogs = readFileSync(new URL('./PurchasingDialogs.jsx', import.meta.url), 'utf8')

function supplierListSection(source) {
  const start = source.indexOf('function SupplierList(')
  const end = source.indexOf('\n}', start)
  return source.slice(start, end)
}

// 1. Standalone Attach existing supplier button no longer appears on the main page.
test('the main Supplier list toolbar no longer renders a standalone Attach existing supplier button', () => {
  const section = supplierListSection(purchasingPanel)
  assert.doesNotMatch(section, /<AttachExistingSupplier/)
})

// 2. Add Supplier remains available as the (now single) primary action.
test('Add supplier remains the Supplier list toolbar primary action', () => {
  const section = supplierListSection(purchasingPanel)
  assert.match(section, /primary-button[^>]*onClick=\{onCreate\}[^<]*<Plus[^/]*\/>Add supplier/)
})

// SUPPLIER_MAIN_PRIMARY_ACTION: exactly one primary-button in the Supplier list toolbar.
test('the Supplier list toolbar has exactly one primary-button', () => {
  const section = supplierListSection(purchasingPanel)
  const matches = section.match(/className="primary-button"/g) ?? []
  assert.equal(matches.length, 1)
})

// 3. An existing account supplier can still be discovered/attached by an authorized manager -
// both automatically (exact-name match on submit) and manually (secondary link -> browse picker).
test('Add Supplier checks for an exact active account-name match before creating a new identity', () => {
  assert.match(purchasingDialogs, /normalizedSupplierName\(candidate\.name\) === typed/)
  assert.match(purchasingDialogs, /candidate\.is_active/)
})

test('a discovered match offers Use existing supplier, which attaches to the active branch via the canonical assignment workflow', () => {
  assert.match(purchasingDialogs, /Use existing supplier/)
  assert.match(purchasingDialogs, /if \(!visibleSupplierIds\?\.has\(discoveryMatch\.id\)\) await onAssignBranch\(discoveryMatch\.id, branchId\)/)
})

test('the manual browse-and-attach picker remains reachable from inside the Add Supplier dialog', () => {
  assert.match(purchasingDialogs, /Attach existing supplier instead/)
  assert.match(purchasingDialogs, /<AttachExistingSupplier[^>]*startOpen/)
})

// 4. Normal new supplier creation still works: no match found -> straight to onSave; and an
// explicit "create anyway" override always reaches onSave even when a match was found.
test('no match found proceeds straight to creating the new supplier', () => {
  assert.match(purchasingDialogs, /if \(match\) \{ setDiscoveryMatch\(match\); return \}\s*\n\s*await performCreate\(\)/)
})

test('Create new supplier anyway always reaches performCreate, overriding a found match', () => {
  assert.match(purchasingDialogs, /function createAnyway\(\) \{ setDiscoveryMatch\(null\); performCreate\(\) \}/)
})

// 5. Edit mode (an existing identity) never runs discovery - only create mode can find "another" supplier.
test('discovery only runs in create mode, never when editing an existing supplier', () => {
  assert.match(purchasingDialogs, /if \(supplier\) return performCreate\(\)/)
  assert.match(purchasingDialogs, /if \(!supplier && discoveryMatch\)/)
})

// 6. No duplicate identity is created silently: a match always interrupts submission with an
// explicit choice, never auto-selecting or auto-merging.
test('a discovered match always requires an explicit user choice before any save happens', () => {
  assert.doesNotMatch(purchasingDialogs, /if \(match\) \{ await performCreate/)
  assert.match(purchasingDialogs, /Existing supplier found/)
  assert.match(purchasingDialogs, /Create new supplier anyway/)
})

// Cross-account isolation: discovery only ever searches this account's own supplier list -
// allSuppliers is fetched via the existing account-scoped loader, never a cross-account one.
test('discovery searches only the current account\'s supplier list, not a cross-account source', () => {
  assert.match(purchasingDialogs, /let all = allSuppliers/)
  assert.match(purchasingDialogs, /if \(!all\) all = await onLoadAllSuppliers\(\)/)
  assert.doesNotMatch(purchasingDialogs, /fetch\(`https?:/)
})
