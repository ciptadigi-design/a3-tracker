import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const page = readFileSync(new URL('../../pages/InventoryPage.jsx', import.meta.url), 'utf8')
const purchasingPanel = readFileSync(new URL('./PurchasingPanel.jsx', import.meta.url), 'utf8')

// M2.17.4.2: a Production spot-check found Inventory sometimes showing a fatal
// "Inventory could not be loaded" error on first navigation, and after "Try again"
// cleared it, the Suppliers panel stayed stuck at "0 active suppliers" even though
// Production had 9. Root cause: refresh() (core stock/movements data) and
// refreshSuppliers() (the Suppliers panel) are independent requests that used to
// share ONE `error` state, and "Try again" only retried refresh() - so a transient
// failure in refreshSuppliers() alone (a) fatally blocked the WHOLE workspace, and
// (b) was never actually retried, leaving the supplier list silently empty forever.
// These are source-contract checks (this repo has no jsdom/React render harness)
// proving the two requests now own independent error state and any retry re-attempts
// both.

test('refreshSuppliers owns its own error state, separate from the fatal page-level error', () => {
  assert.match(page, /const \[supplierError, setSupplierError\] = useState\(null\)/)
  assert.match(page, /const refreshSuppliers = useCallback\(async \(\) => \{[\s\S]*?setSupplierError\(null\)[\s\S]*?catch \(loadError\) \{ setSupplierError\(loadError\) \}/)
  // refreshSuppliers must never call the page-fatal setError - that coupling was the bug.
  const refreshSuppliersBody = page.match(/const refreshSuppliers = useCallback\(async \(\) => \{[\s\S]*?\}, \[account\.id, branch\?\.id, canManage\]\)/)[0]
  assert.doesNotMatch(refreshSuppliersBody, /\bsetError\(/)
})

test('a manual retry (Try again / header refresh) re-attempts both requests, not just the core one', () => {
  assert.match(page, /const reloadAll = useCallback\(\(\) => \{ refresh\(\); refreshSuppliers\(\) \}, \[refresh, refreshSuppliers\]\)/)
  assert.match(page, /Try again<\/button>[\s\S]{0,10}<\/div>\}\s*<section/) // fatal-error retry button precedes the workspace shell
  assert.match(page, /<button className="secondary-button" type="button" onClick=\{reloadAll\}>Try again<\/button>/)
  assert.match(page, /<button className="inventory-refresh" type="button" onClick=\{reloadAll\}/)
})

test('the mount effect for suppliers no longer swallows-and-conflates errors via .catch(setError)', () => {
  assert.doesNotMatch(page, /refreshSuppliers\(\)\.catch\(\(loadError\) => setError\(loadError\)\)/)
  assert.match(page, /useEffect\(\(\) => \{ refreshSuppliers\(\) \}, \[refreshSuppliers\]\)/)
})

test('a failed supplier fetch renders as a distinct, retriable error - never indistinguishable from a true empty list', () => {
  assert.match(purchasingPanel, /suppliersError \? <div className="embedded-error"/)
  assert.match(purchasingPanel, /onClick=\{onRetrySuppliers\}/)
})
