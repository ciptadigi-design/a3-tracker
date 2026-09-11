import { useState } from 'react'
import { Archive, Building2, CalendarCheck, Edit3, Eye, PackageCheck, Plus, ReceiptText, Trash2, X } from 'lucide-react'
import { createUIStateKey } from '../uiState/uiStateKeys.js'
import { usePersistentUIState } from '../uiState/usePersistentUIState.js'
import { Pagination } from '../../components/ui/Pagination.jsx'
import { usePagination } from '../pagination/usePagination.js'
import { describePurchaseStatus, formatPurchaseTotal, purchaseFullyReceivedLineCount, purchaseLineCount, purchaseReceivingProgressPercent, purchaseSupplierName, safeNumber } from './purchasePresentation.js'
import { userErrorMessage } from '../../lib/appErrors.js'

const sections = [{ id: 'purchases', label: 'Purchases', icon: PackageCheck }, { id: 'suppliers', label: 'Suppliers', icon: Building2 }, { id: 'receiving', label: 'Receiving', icon: ReceiptText }]
const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const quantity = (value) => safeNumber(value).toLocaleString('id-ID', { maximumFractionDigits: 4 })
const validSection = (value) => value && sections.some((section) => section.id === value.section) && typeof value.showArchivedSuppliers === 'boolean'

function PurchaseList({ purchases, canManage, onCreate, onOpen, resetKey }) {
  const pagination = usePagination(purchases.length, resetKey)
  const visiblePurchases = purchases.slice(pagination.start, pagination.end)
  return <><div className="inventory-section-toolbar"><div><span className="card-kicker">Commercial evidence</span><h2>{purchases.length} purchases</h2><p>Purchases record quantity and price. Stock changes only when goods are received.</p></div>{canManage && <button className="primary-button" type="button" onClick={onCreate}><Plus size={16} />Create purchase</button>}</div>
    {purchases.length === 0 ? <div className="inventory-empty"><PackageCheck size={25} /><strong>No purchases recorded.</strong><span>Create a purchase without changing stock, then receive physical goods later.</span></div> : <><div className="purchase-list"><div className="purchase-list-head"><span>Purchase</span><span>Supplier</span><span>Items</span><span>Total</span><span>Receiving</span><span>Status</span><span></span></div>{visiblePurchases.map((purchase) => { const lineCount = purchaseLineCount(purchase); const fullyReceived = purchaseFullyReceivedLineCount(purchase); const status = describePurchaseStatus(purchase); return <article key={purchase.purchase_id}><div><strong>{purchase.purchase_number}</strong><span>{new Date(`${purchase.purchase_date}T00:00:00`).toLocaleDateString('id-ID')}</span></div><div><strong>{purchaseSupplierName(purchase)}</strong><span>{purchase.supplier_reference || purchase.supplier_code_snapshot}</span></div><div><strong>{lineCount}</strong><span>line{lineCount === 1 ? '' : 's'}</span></div><div><strong>{formatPurchaseTotal(purchase)}</strong><span>{purchase.currency_code || 'IDR'} evidence</span></div><div><strong>{quantity(purchaseReceivingProgressPercent(purchase))}%</strong><span>{fullyReceived} / {lineCount} lines complete</span></div><span className={status.className}>{status.label}</span><button className="icon-button" type="button" onClick={() => onOpen(purchase)} aria-label={`Open ${purchase.purchase_number}`}><Eye size={16} /></button></article> })}</div><Pagination total={purchases.length} {...pagination} onPageChange={pagination.setPage} onPageSizeChange={pagination.setPageSize} label="purchases" /></>}
  </>
}

function SupplierBranches({ supplier, branches, canManage, onAssignBranch, onUnassignBranch }) {
  const [busy, setBusy] = useState(false)
  const assignedIds = new Set((supplier.branch_assignments ?? []).map((assignment) => assignment.branch_id))
  const unassignedBranches = branches.filter((branch) => !assignedIds.has(branch.id))
  async function toggle(action, branchId) {
    setBusy(true)
    try { await action(supplier.id, branchId) } finally { setBusy(false) }
  }
  return <div className="supplier-branch-scope">
    <span className="supplier-branch-scope-label">{assignedIds.size === 0 ? 'Available to every branch' : 'Available to:'}</span>
    <div className="supplier-branch-chips">
      {[...assignedIds].map((branchId) => {
        const branchName = branches.find((branch) => branch.id === branchId)?.name ?? branchId
        return <span className="branch-chip" key={branchId}>{branchName}{canManage && <button type="button" disabled={busy} onClick={() => toggle(onUnassignBranch, branchId)} aria-label={`Remove ${branchName} from ${supplier.name}`}><X size={12} /></button>}</span>
      })}
      {canManage && unassignedBranches.length > 0 && <select value="" disabled={busy} onChange={(event) => event.target.value && toggle(onAssignBranch, event.target.value)} aria-label={`Assign ${supplier.name} to a branch`}>
        <option value="">{assignedIds.size === 0 ? 'Restrict to branch…' : 'Add branch…'}</option>
        {unassignedBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
      </select>}
    </div>
  </div>
}

// M2.17.4.1: Supplier Master is now branch-filtered, so a supplier restricted to
// another branch is no longer rendered in the normal list - this is the only way an
// admin can still find and attach one to the currently active branch without
// duplicating its identity. Lazily loads the unfiltered account list on first open.
function AttachExistingSupplier({ branchName, visibleSupplierIds, allSuppliers, onLoadAllSuppliers, onAssignBranch, branchId }) {
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [selected, setSelected] = useState('')
  const [busy, setBusy] = useState(false)
  async function reveal() {
    setOpen(true)
    if (!allSuppliers) { setLoading(true); try { await onLoadAllSuppliers() } finally { setLoading(false) } }
  }
  const candidates = (allSuppliers ?? []).filter((supplier) => supplier.is_active && !visibleSupplierIds.has(supplier.id))
  async function attach() {
    if (!selected) return
    setBusy(true)
    try { await onAssignBranch(selected, branchId); setSelected('') } finally { setBusy(false) }
  }
  if (!open) return <button className="secondary-button" type="button" onClick={reveal}>Attach existing supplier…</button>
  return <div className="attach-existing-supplier">
    {loading ? <span>Loading account suppliers…</span> : candidates.length === 0 ? <span>Every active account supplier is already available to {branchName}.</span> : <>
      <select value={selected} onChange={(event) => setSelected(event.target.value)} aria-label="Select an existing supplier to attach">
        <option value="">Select a supplier…</option>
        {candidates.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
      </select>
      <button className="secondary-button" type="button" disabled={!selected || busy} onClick={attach}>Add to {branchName}</button>
    </>}
    <button className="secondary-button" type="button" onClick={() => setOpen(false)}>Close</button>
  </div>
}

function SupplierList({ suppliers, suppliersError, onRetrySuppliers, branches, branchId, showArchived, canManage, allSuppliers, onLoadAllSuppliers, onCreate, onEdit, onDelete, onAssignBranch, onUnassignBranch }) {
  const visible = suppliers.filter((supplier) => showArchived ? !supplier.is_active : supplier.is_active)
  const branchName = branches.find((branch) => branch.id === branchId)?.name ?? 'this branch'
  return <><div className="inventory-section-toolbar"><div><span className="card-kicker">Supplier master</span><h2>{visible.length} {showArchived ? 'archived' : 'active'} suppliers available to {branchName}</h2><p>Supplier identity stays account-owned and is snapshotted into purchase history; this list reflects which suppliers are currently available to {branchName}.</p></div><div className="inventory-toolbar-actions">{canManage && !showArchived && branches.length > 1 && <AttachExistingSupplier branchName={branchName} branchId={branchId} visibleSupplierIds={new Set(suppliers.map((supplier) => supplier.id))} allSuppliers={allSuppliers} onLoadAllSuppliers={onLoadAllSuppliers} onAssignBranch={onAssignBranch} />}{canManage && <button className="primary-button" type="button" onClick={onCreate}><Plus size={16} />Add supplier</button>}</div></div>
    {/* M2.17.4.2: a failed supplier fetch must read as a distinct, retriable error - not
    silently render as "0 active suppliers", which was indistinguishable from the true
    empty state and left an admin unable to tell whether there really were no suppliers
    or the request had simply failed. */}
    {suppliersError ? <div className="embedded-error" role="alert"><strong>Suppliers could not be loaded.</strong><span>{userErrorMessage(suppliersError, 'Supplier data is temporarily unavailable.')}</span><button className="secondary-button" type="button" onClick={onRetrySuppliers}>Try again</button></div> : visible.length === 0 ? <div className="inventory-empty"><Building2 size={25} /><strong>No {showArchived ? 'archived' : 'active'} suppliers available to {branchName}.</strong><span>Owner/admin can maintain account-scoped supplier records here.</span></div> : <div className="supplier-list">{visible.map((supplier) => <article className={supplier.is_active ? '' : 'archived'} key={supplier.id}><span className="inventory-item-icon"><Building2 size={18} /></span><div><strong>{supplier.name}</strong><span>{supplier.supplier_code}{supplier.contact_person ? ` · ${supplier.contact_person}` : ''}</span><small>{supplier.email || supplier.phone || supplier.address || 'No contact details'}</small>{supplier.is_active && branches.length > 1 && <SupplierBranches supplier={supplier} branches={branches} canManage={canManage} onAssignBranch={onAssignBranch} onUnassignBranch={onUnassignBranch} />}</div>{!supplier.is_active && <span className="scope-pill archived"><Archive size={12} />Archived</span>}{canManage && <div className="inventory-row-actions"><button type="button" onClick={() => onEdit(supplier)} aria-label={`Edit ${supplier.name}`}><Edit3 size={15} /></button><button type="button" onClick={() => onDelete(supplier)} aria-label={`Delete ${supplier.name}`}><Trash2 size={15} /></button></div>}</article>)}</div>}</>
}

// M2.17.5: a real receipt's unit price/acquisition value rendered "RpNaN" whenever
// either was unknown, because `money.format(Number(null))` = money.format(NaN). Unknown
// cost is a legitimate state (see purchasePresentation.js's own precedent) and must
// read as "Unknown", never a fabricated Rp0 or a raw NaN.
function receiptUnitPrice(line) {
  return line.unit_price_snapshot == null ? 'Unknown' : money.format(Number(line.unit_price_snapshot))
}
function receiptAcquisitionValue(line) {
  return line.acquisition_value == null ? null : `${money.format(Number(line.acquisition_value))} received value`
}

function ReceiptList({ receipts, timeZone, resetKey }) {
  const formatter = new Intl.DateTimeFormat('id-ID', { timeZone: timeZone || 'Asia/Jakarta', dateStyle: 'medium', timeStyle: 'short' })
  const pagination = usePagination(receipts.length, resetKey)
  const visibleReceipts = receipts.slice(pagination.start, pagination.end)
  return receipts.length === 0 ? <div className="inventory-empty"><CalendarCheck size={25} /><strong>No receiving history.</strong><span>Posted physical receipts will appear here with immutable purchase-cost evidence.</span></div> : <><div className="receipt-history-list">{visibleReceipts.map((line) => <article key={line.receipt_line_id}><span className="movement-direction movement-in"><CalendarCheck size={17} /></span><div><strong>{line.item_name_snapshot}</strong><span>{[line.item_sku_snapshot, line.receipt_number].filter(Boolean).join(' · ')}</span></div><div><span>Received</span><strong>+{quantity(line.quantity)} {line.unit_snapshot}</strong></div><div><span>Purchase</span><strong>{line.purchase_number_snapshot || 'Unknown'}</strong><small>{line.supplier_name_snapshot || 'Unknown supplier'}</small></div><div><span>Acquisition price</span><strong>{receiptUnitPrice(line)}</strong>{receiptAcquisitionValue(line) && <small>{receiptAcquisitionValue(line)}</small>}</div><div><span>Location / PIC</span><strong>{line.location_name || 'Unknown location'}</strong><small>{line.operational_person_name_snapshot || 'Not recorded'}</small></div><time>{formatter.format(new Date(line.received_at))}</time></article>)}</div><Pagination total={receipts.length} {...pagination} onPageChange={pagination.setPage} onPageSizeChange={pagination.setPageSize} label="receipts" /></>
}

export function PurchasingPanel({ userId, account, branchId, branches = [], data, suppliers = data.suppliers, suppliersError, onRetrySuppliers, allSuppliers, onLoadAllSuppliers, canManage, canCreatePurchase = canManage, onCreateSupplier, onEditSupplier, onDeleteSupplier, onAssignBranch, onUnassignBranch, onCreatePurchase, onOpenPurchase }) {
  const key = createUIStateKey({ userId, accountId: account.id, branchId, feature: 'inventory-purchasing-view', entityId: 'workspace' })
  const state = usePersistentUIState({ uiStateKey: key, initialValue: { section: 'purchases', showArchivedSuppliers: false }, validate: validSection })
  return <div className="purchasing-workspace"><div className="purchasing-subtabs" role="tablist" aria-label="Purchasing sections">{sections.map((section) => <button key={section.id} type="button" role="tab" aria-selected={state.value.section === section.id} className={state.value.section === section.id ? 'selected' : ''} onClick={() => state.setUIState((current) => ({ ...current, section: section.id }))}><section.icon size={15} />{section.label}{section.id === 'receiving' && <span>{data.receipts.length}</span>}</button>)}</div>{state.value.section === 'suppliers' && canManage && <div className="inventory-record-toggle"><button className={!state.value.showArchivedSuppliers ? 'selected' : ''} onClick={() => state.setUIState((current) => ({ ...current, showArchivedSuppliers: false }))}>Active</button><button className={state.value.showArchivedSuppliers ? 'selected' : ''} onClick={() => state.setUIState((current) => ({ ...current, showArchivedSuppliers: true }))}>Archived</button></div>}<div className="purchasing-content">
    {state.value.section === 'purchases' && <PurchaseList purchases={data.purchases} canManage={canCreatePurchase} onCreate={onCreatePurchase} onOpen={onOpenPurchase} resetKey={branchId} />}
    {state.value.section === 'suppliers' && <SupplierList suppliers={suppliers} suppliersError={suppliersError} onRetrySuppliers={onRetrySuppliers} branches={branches} branchId={branchId} showArchived={state.value.showArchivedSuppliers} canManage={canManage} allSuppliers={allSuppliers} onLoadAllSuppliers={onLoadAllSuppliers} onCreate={onCreateSupplier} onEdit={onEditSupplier} onDelete={onDeleteSupplier} onAssignBranch={onAssignBranch} onUnassignBranch={onUnassignBranch} />}
    {state.value.section === 'receiving' && <ReceiptList receipts={data.receipts} timeZone={account.default_timezone} resetKey={branchId} />}
  </div></div>
}
