// Defensive presentation helpers for purchase records.
//
// The backend purchase view model is a derived/aggregated shape
// (purchase_total, line_count, fully_received_line_count,
// receiving_progress_percent, supplier_name_snapshot, status, ...). If a
// backend response is ever missing one of those fields — historically true
// for the Laravel adapter, which returned raw `purchases` table rows with
// none of them — these helpers guarantee the UI renders a safe fallback
// ("Rp0" / "0" / "0%") instead of "RpNaN" or a blank cell, regardless of
// which backend produced the data.

const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })

export function safeNumber(value, fallback = 0) {
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

export function formatPurchaseTotal(purchase) {
  return money.format(safeNumber(purchase?.purchase_total))
}

export function purchaseLineCount(purchase) {
  return safeNumber(purchase?.line_count)
}

export function purchaseFullyReceivedLineCount(purchase) {
  return safeNumber(purchase?.fully_received_line_count)
}

export function purchaseReceivingProgressPercent(purchase) {
  return safeNumber(purchase?.receiving_progress_percent)
}

const LEGACY_IMPORT_NOTE_PREFIX = 'LEGACY_IMPORT'

export function isLegacyImportPurchase(purchase) {
  return typeof purchase?.notes === 'string' && purchase.notes.startsWith(LEGACY_IMPORT_NOTE_PREFIX)
}

// Status is a genuine state field (draft -> partially_received/received on
// receive(), or cancelled) — never rewritten for display. Legacy-imported
// purchases are correctly "draft" (never received through this system;
// historical receipt status was not represented during import), but the
// bare word "Draft" reads to an operator as "not yet finalized" rather
// than "historical purchase, receipt unknown". This only changes the
// rendered label, never the underlying `status` value.
export function describePurchaseStatus(purchase) {
  const status = typeof purchase?.status === 'string' ? purchase.status : 'draft'
  if (status === 'draft' && isLegacyImportPurchase(purchase)) {
    return { label: 'Historical (unreceived)', className: `purchase-status status-${status} status-legacy` }
  }
  return { label: status.replaceAll('_', ' '), className: `purchase-status status-${status}` }
}

export function purchaseSupplierName(purchase) {
  return purchase?.supplier_name_snapshot || (isLegacyImportPurchase(purchase) ? 'Historical import' : 'Unknown supplier')
}
