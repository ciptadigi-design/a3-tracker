import assert from 'node:assert/strict'
import test from 'node:test'
import { describePurchaseStatus, formatPurchaseTotal, isLegacyImportPurchase, purchaseFullyReceivedLineCount, purchaseLineCount, purchaseReceivingProgressPercent, purchaseSupplierName, safeNumber } from './purchasePresentation.js'

const legacyPurchase = {
  purchase_id: 'p1',
  purchase_number: 'LEGACY-164',
  status: 'draft',
  notes: 'LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id=164',
  purchase_total: 1800000,
  line_count: 1,
  fully_received_line_count: 0,
  receiving_progress_percent: 0,
  supplier_name_snapshot: null,
}

test('a fully-populated purchase renders its real numeric total, never NaN', () => {
  assert.match(formatPurchaseTotal(legacyPurchase), /^Rp\s?1\.800\.000,00$/)
  assert.equal(purchaseLineCount(legacyPurchase), 1)
  assert.equal(purchaseFullyReceivedLineCount(legacyPurchase), 0)
  assert.equal(purchaseReceivingProgressPercent(legacyPurchase), 0)
})

test('purchase_total missing/null/undefined never formats as RpNaN', () => {
  for (const total of [null, undefined, NaN, 'not-a-number', {}]) {
    const formatted = formatPurchaseTotal({ ...legacyPurchase, purchase_total: total })
    assert.ok(!formatted.includes('NaN'), `expected no NaN in "${formatted}" for purchase_total=${String(total)}`)
  }
})

test('line_count and fully_received_line_count missing never render blank/NaN, default to 0', () => {
  const stripped = { purchase_id: 'p2', purchase_number: 'LEGACY-1', status: 'draft', purchase_total: 0 }
  assert.equal(purchaseLineCount(stripped), 0)
  assert.equal(purchaseFullyReceivedLineCount(stripped), 0)
  assert.equal(purchaseReceivingProgressPercent(stripped), 0)
})

test('a raw backend row with none of the derived fields (the historical Laravel bug) is fully safe', () => {
  // This is exactly the shape InventoryController@workspace used to return
  // before the fix: DB::table('purchases')->get() rows have none of
  // purchase_total / line_count / fully_received_line_count /
  // receiving_progress_percent / supplier_name_snapshot.
  const rawRow = { id: 'p3', account_id: 'a1', purchase_number: 'LEGACY-9', purchase_date: '2025-01-01', currency_code: 'IDR', status: 'draft', notes: null }
  assert.match(formatPurchaseTotal(rawRow), /^Rp\s?0,00$/)
  assert.equal(purchaseLineCount(rawRow), 0)
  assert.equal(purchaseFullyReceivedLineCount(rawRow), 0)
  assert.equal(purchaseReceivingProgressPercent(rawRow), 0)
  assert.equal(purchaseSupplierName(rawRow), 'Unknown supplier')
})

test('safeNumber coerces non-finite input to the fallback', () => {
  assert.equal(safeNumber(undefined), 0)
  assert.equal(safeNumber(null), 0)
  assert.equal(safeNumber('abc'), 0)
  assert.equal(safeNumber(NaN, 5), 5)
  assert.equal(safeNumber('42.5'), 42.5)
  assert.equal(safeNumber(42.5), 42.5)
})

test('legacy import detection is based on the notes prefix written by LegacyImportService', () => {
  assert.equal(isLegacyImportPurchase(legacyPurchase), true)
  assert.equal(isLegacyImportPurchase({ notes: 'not legacy' }), false)
  assert.equal(isLegacyImportPurchase({ notes: null }), false)
  assert.equal(isLegacyImportPurchase({}), false)
})

test('legacy draft purchases get a clarifying status label without changing the underlying status value', () => {
  const status = describePurchaseStatus(legacyPurchase)
  assert.equal(status.label, 'Historical (unreceived)')
  assert.ok(status.className.includes('status-draft'))
})

test('a normal (non-legacy) draft purchase keeps the plain "draft" label', () => {
  const status = describePurchaseStatus({ status: 'draft', notes: 'Manually created purchase' })
  assert.equal(status.label, 'draft')
})

test('status label falls back to "draft" for a missing/undefined status field', () => {
  const status = describePurchaseStatus({})
  assert.equal(status.label, 'draft')
})

test('received/partially_received/cancelled statuses render their normal spaced label regardless of notes', () => {
  assert.equal(describePurchaseStatus({ status: 'partially_received', notes: 'LEGACY_IMPORT; x' }).label, 'partially received')
  assert.equal(describePurchaseStatus({ status: 'received' }).label, 'received')
  assert.equal(describePurchaseStatus({ status: 'cancelled' }).label, 'cancelled')
})

test('supplier snapshot falls back to a legacy-aware message when supplier_id is absent', () => {
  assert.equal(purchaseSupplierName(legacyPurchase), 'Historical import')
  assert.equal(purchaseSupplierName({ notes: null, supplier_name_snapshot: null }), 'Unknown supplier')
  assert.equal(purchaseSupplierName({ supplier_name_snapshot: 'Acme Supplies' }), 'Acme Supplies')
})
