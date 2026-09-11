import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const laravelInventory = readFileSync(new URL('../../services/laravel/inventory.js', import.meta.url), 'utf8')
const purchasingPanel = readFileSync(new URL('./PurchasingPanel.jsx', import.meta.url), 'utf8')
const purchasingDialogs = readFileSync(new URL('./PurchasingDialogs.jsx', import.meta.url), 'utf8')

// M2.17.4.1: a Production acceptance test found the Supplier Master list ignoring the
// active branch entirely, while the Purchase picker already enforced branch
// eligibility. These are source-contract checks (this repo has no jsdom/React render
// test harness) proving the wiring that makes Supplier Master branch-reactive stays in
// place: the branch id travels to the endpoint, and the effect re-fires on branch
// switch without requiring a hard reload.
test('loadInventorySuppliers sends account_id and branch_id so the backend can apply the same eligibility rule as the Purchase picker', () => {
  assert.match(laravelInventory, /account_id:\s*accountId,\s*branch_id:\s*branchId/)
  assert.match(laravelInventory, /include_ineligible/)
})

test('Supplier Master refetches reactively when the active branch changes, not only on mount', () => {
  assert.match(page, /refreshSuppliers = useCallback\(async \(\) => \{[^}]*branchId:\s*branch\.id/)
  assert.match(page, /const refreshSuppliers = useCallback\([\s\S]*?\}, \[account\.id, branch\?\.id, canManage\]\)/)
})

test('allAccountSuppliers is reset when the branch changes, so a stale ineligible-supplier list is never shown for the wrong branch', () => {
  assert.match(page, /useEffect\(\(\) => \{ setAllAccountSuppliers\(null\) \}, \[branch\?\.id\]\)/)
})

// M2.17.5.2: the lazy all-suppliers loader moved from PurchasingPanel (which rendered
// "Attach existing supplier…" as a second standalone button on the main Supplier list)
// to the Add Supplier dialog itself, where cross-branch discovery is now offered
// automatically. AttachExistingSupplier remains the same exported, tested component -
// just embedded, not a top-level button - so the underlying M2.17.4.1 capability is
// relocated, not removed.
test('the Add Supplier dialog receives the lazy all-suppliers loader for cross-branch discovery, not PurchasingPanel', () => {
  assert.match(page, /<InventorySupplierDialog[^>]*allSuppliers=\{allAccountSuppliers\}/)
  assert.match(page, /<InventorySupplierDialog[^>]*onLoadAllSuppliers=\{loadAllAccountSuppliers\}/)
  assert.doesNotMatch(page, /<PurchasingPanel[^>]*allSuppliers=/)
  assert.match(purchasingPanel, /export function AttachExistingSupplier/)
  assert.match(purchasingDialogs, /import \{ AttachExistingSupplier \} from '\.\/PurchasingPanel\.jsx'/)
})

// The subtitle must no longer claim the list is unconditionally account-wide (Phase 11).
test('Supplier Master copy reflects branch-scoped availability, not just account ownership', () => {
  assert.match(purchasingPanel, /reflects which suppliers are currently available to/)
  assert.doesNotMatch(purchasingPanel, /Branch scope only controls where a supplier is offered when creating a purchase\./)
})
