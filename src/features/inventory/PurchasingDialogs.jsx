import { useRef, useState } from 'react'
import { AlertCircle, Building2, CalendarCheck, LoaderCircle, PackageCheck, Plus, RotateCcw, Trash2, XCircle } from 'lucide-react'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { DialogFrame, InventoryItemDialog } from './InventoryDialogs.jsx'
import { discoverPurchaseItems, inventoryItemLabel } from './purchaseItemDiscovery.js'

const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const quantity = (value) => Number(value ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 4 })
const positiveQuantity = (value) => Number(value) > 0 && /^\d+(\.\d{1,4})?$/.test(String(value))
const moneyValue = (value) => Number(value) >= 0 && /^\d+(\.\d{1,2})?$/.test(String(value))
const localDate = () => localDateTime().slice(0, 10)
function localDateTime() {
  const date = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  return date.toISOString().slice(0, 16)
}

function FormError({ error }) {
  return error ? <div className="form-error" role="alert"><AlertCircle size={16} />{error}</div> : null
}

export function InventorySupplierDialog({ account, supplier, onClose, onSave }) {
  const { user } = useAuth()
  const initial = { supplierCode: supplier?.supplier_code ?? '', name: supplier?.name ?? '', contactPerson: supplier?.contact_person ?? '', phone: supplier?.phone ?? '', email: supplier?.email ?? '', address: supplier?.address ?? '', notes: supplier?.notes ?? '', isActive: supplier?.is_active ?? true }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: 'inventory-supplier', entityId: supplier?.id ?? 'new' })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.supplierCode === 'string' && typeof value.name === 'string' && typeof value.isActive === 'boolean' })
  const [busy, setBusy] = useState(false); const [error, setError] = useState(null)
  const change = (field, value) => { draft.updateDraft((current) => ({ ...current, [field]: value })); setError(null) }
  async function submit(event) {
    event.preventDefault()
    if (!draft.value.supplierCode.trim() || !draft.value.name.trim()) return setError('Supplier code and name are required.')
    setBusy(true)
    try { await onSave(draft.value); draft.clearDraft(); onClose() }
    catch (saveError) { setError(saveError.code === '23505' ? 'That supplier code already exists in this workspace.' : saveError.message) }
    finally { setBusy(false) }
  }
  return <DialogFrame icon={Building2} kicker="Supplier master" title={`${supplier ? 'Edit' : 'Add'} supplier`} description="Account-owned supplier identity used by immutable purchase and receipt evidence." titleId="inventory-supplier-title" busy={busy} onClose={onClose}>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body"><div className="form-grid">
      <label className="form-field"><span>Supplier code <b className="required-mark">*</b></span><input value={draft.value.supplierCode} onChange={(event) => change('supplierCode', event.target.value)} data-dialog-initial-focus /></label>
      <label className="form-field"><span>Name <b className="required-mark">*</b></span><input value={draft.value.name} onChange={(event) => change('name', event.target.value)} /></label>
      <label className="form-field"><span>Contact person <small>Optional</small></span><input value={draft.value.contactPerson} onChange={(event) => change('contactPerson', event.target.value)} /></label>
      <label className="form-field"><span>Phone <small>Optional</small></span><input value={draft.value.phone} onChange={(event) => change('phone', event.target.value)} /></label>
      <label className="form-field"><span>Email <small>Optional</small></span><input type="email" value={draft.value.email} onChange={(event) => change('email', event.target.value)} /></label>
      <label className="form-field form-field-wide"><span>Address <small>Optional</small></span><textarea rows="2" value={draft.value.address} onChange={(event) => change('address', event.target.value)} /></label>
      <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="2" value={draft.value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      {supplier && <label className="master-active-toggle"><input type="checkbox" checked={draft.value.isActive} onChange={(event) => change('isActive', event.target.checked)} /><span><strong>Active</strong><small>Archive referenced suppliers to preserve purchase history.</small></span></label>}
    </div><FormError error={error} /></div><footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => draft.resetDraft(initial)} disabled={!draft.hasDraft || busy}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Saving…' : 'Save supplier'}</button></footer></form>
  </DialogFrame>
}

export function InventoryPurchaseDialog({ account, suppliers, items, components, onClose, onCreate, onCreateItem }) {
  const { user } = useAuth()
  const initial = { supplierId: '', purchaseDate: localDate(), supplierReference: '', notes: '', lines: [{ rowId: crypto.randomUUID(), itemId: '', quantity: '1', unitPrice: '', notes: '' }] }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: 'inventory-purchase', entityId: 'new' })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.supplierId === 'string' && Array.isArray(value.lines) })
  const requestId = useRef(crypto.randomUUID()); const [busy, setBusy] = useState(false); const [error, setError] = useState(null)
  const [inlineCreate, setInlineCreate] = useState(null)
  const change = (field, value) => { draft.updateDraft((current) => ({ ...current, [field]: value })); setError(null) }
  const changeLine = (rowId, field, value) => { draft.updateDraft((current) => ({ ...current, lines: current.lines.map((line) => line.rowId === rowId ? { ...line, [field]: value } : line) })); setError(null) }
  const purchaseTotal = draft.value.lines.reduce((total, line) => total + (Number(line.quantity) || 0) * (Number(line.unitPrice) || 0), 0)
  async function createInlineItem(values) {
    const created = await onCreateItem(values)
    changeLine(inlineCreate.rowId, 'itemId', created.id)
    changeLine(inlineCreate.rowId, 'itemSearch', inventoryItemLabel(created))
    setInlineCreate(null)
    return created
  }
  async function submit(event) {
    event.preventDefault(); const value = draft.value
    if (!suppliers.some((supplier) => supplier.id === value.supplierId) || !value.purchaseDate) return setError('Choose an active supplier and purchase date.')
    if (!value.lines.length || value.lines.some((line) => !items.some((item) => item.id === line.itemId) || !positiveQuantity(line.quantity) || !moneyValue(line.unitPrice))) return setError('Every line needs a unique active item, positive quantity, and valid nonnegative unit price.')
    if (new Set(value.lines.map((line) => line.itemId)).size !== value.lines.length) return setError('An inventory item may appear only once per purchase.')
    setBusy(true)
    try { await onCreate(value, requestId.current); draft.clearDraft(); onClose() }
    catch (createError) { setError(createError.message || 'The purchase could not be created.') }
    finally { setBusy(false) }
  }
  return <DialogFrame icon={PackageCheck} kicker="Purchasing" title="Create purchase" description="Records acquisition and price evidence. Saving this purchase does not increase stock." titleId="inventory-purchase-title" busy={busy} onClose={onClose}>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      <div className="ledger-notice"><PackageCheck size={17} /><span>Stock changes only after Receive Goods is posted through the inventory ledger.</span></div>
      <div className="form-grid">
        <label className="form-field"><span>Supplier <b className="required-mark">*</b></span><select value={draft.value.supplierId} onChange={(event) => change('supplierId', event.target.value)} data-dialog-initial-focus><option value="">Choose supplier</option>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.supplier_code} · {supplier.name}</option>)}</select></label>
        <label className="form-field"><span>Internal purchase number</span><input value="Generated after saving" readOnly aria-readonly="true" /><small className="field-hint">Account-scoped format: PUR-YYYYMM-####</small></label>
        <label className="form-field"><span>Purchase date <b className="required-mark">*</b></span><input type="date" max={localDate()} value={draft.value.purchaseDate} onChange={(event) => change('purchaseDate', event.target.value)} /></label>
        <label className="form-field"><span>External reference <small>Optional</small></span><input value={draft.value.supplierReference} onChange={(event) => change('supplierReference', event.target.value)} placeholder="Supplier invoice, PO, or integration ID" /></label>
      </div>
      <section className="purchase-lines-editor"><header><div><strong>Purchase lines</strong><span>All values are IDR acquisition evidence.</span></div><button className="secondary-button" type="button" onClick={() => change('lines', [...draft.value.lines, { rowId: crypto.randomUUID(), itemId: '', quantity: '1', unitPrice: '', notes: '' }])}><Plus size={15} />Add line</button></header>
        {draft.value.lines.map((line, index) => { const item = items.find((candidate) => candidate.id === line.itemId); const discovery = discoverPurchaseItems(items, components, line.itemSearch ?? ''); const lineTotal = (Number(line.quantity) || 0) * (Number(line.unitPrice) || 0); return <div className="purchase-line-editor" key={line.rowId}>
          <div className="purchase-item-discovery"><label className="form-field"><span>Inventory item / SKU <b className="required-mark">*</b></span><input type="search" value={line.itemSearch ?? (item ? inventoryItemLabel(item) : '')} onChange={(event) => { changeLine(line.rowId, 'itemSearch', event.target.value); if (line.itemId) changeLine(line.rowId, 'itemId', '') }} placeholder="Search item, SKU, or Component" autoComplete="off" /></label>
            <div className="purchase-item-results" role="group" aria-label={`Inventory item options for line ${index + 1}`}>
              {discovery.itemResults.slice(0, 8).map((candidate) => <button type="button" aria-pressed={candidate.id === line.itemId} className={candidate.id === line.itemId ? 'selected' : ''} key={candidate.id} onClick={() => { changeLine(line.rowId, 'itemId', candidate.id); changeLine(line.rowId, 'itemSearch', inventoryItemLabel(candidate)) }}><strong>{inventoryItemLabel(candidate)}</strong>{candidate.components?.name && <span>Component: {candidate.components.name}</span>}</button>)}
              {discovery.missingComponents.map((component) => <div className="purchase-missing-item" key={component.id}><span><strong>{component.name}</strong><small>No inventory item configured</small></span><button type="button" onClick={() => setInlineCreate({ rowId: line.rowId, component })}><Plus size={14} />Create inventory item</button></div>)}
              {!discovery.itemResults.length && !discovery.missingComponents.length && <span className="purchase-item-no-results">No matching active Inventory Item{line.itemSearch ? ' or eligible Component' : ''}.</span>}
            </div>
          </div>
          <label className="form-field"><span>Quantity ({item?.unit ?? 'unit'}) <b className="required-mark">*</b></span><input type="number" min="0.0001" step="0.0001" inputMode="decimal" value={line.quantity} onChange={(event) => changeLine(line.rowId, 'quantity', event.target.value)} /></label>
          <label className="form-field"><span>Unit price (IDR) <b className="required-mark">*</b></span><input type="number" min="0" step="0.01" inputMode="decimal" value={line.unitPrice} onChange={(event) => changeLine(line.rowId, 'unitPrice', event.target.value)} /></label>
          <div className="purchase-line-total"><span>Line total</span><strong>{money.format(lineTotal)}</strong></div>
          <button className="icon-button" type="button" disabled={draft.value.lines.length === 1} onClick={() => change('lines', draft.value.lines.filter((candidate) => candidate.rowId !== line.rowId))} aria-label={`Remove purchase line ${index + 1}`}><Trash2 size={16} /></button>
        </div> })}
      </section>
      <label className="form-field"><span>Purchase notes <small>Optional</small></span><textarea rows="2" value={draft.value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      <div className="purchase-total-preview"><span>Purchase total</span><strong>{money.format(purchaseTotal)}</strong><small>Preview only; PostgreSQL derives authoritative totals.</small></div>
      <FormError error={error} />
    </div><footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => draft.resetDraft(initial)} disabled={!draft.hasDraft || busy}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Creating…' : 'Create purchase'}</button></footer></form>
    {inlineCreate && <InventoryItemDialog account={account} components={components} initialComponentId={inlineCreate.component.id} draftEntityId={`purchase:${inlineCreate.component.id}`} onClose={() => setInlineCreate(null)} onSave={createInlineItem} />}
  </DialogFrame>
}

export function InventoryReceiveDialog({ account, purchase, lines, locations, people, onClose, onReceive }) {
  const { user } = useAuth()
  const receivableLines = lines.filter((line) => Number(line.remaining_quantity) > 0)
  const initial = { locationId: '', receivedAt: localDateTime(), personId: people[0]?.id ?? '', notes: '', lines: receivableLines.map((line) => ({ purchaseLineId: line.purchase_line_id, quantity: '' })) }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: 'inventory-receipt', entityId: purchase.purchase_id })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.locationId === 'string' && typeof value.personId === 'string' && Array.isArray(value.lines) })
  const requestId = useRef(crypto.randomUUID()); const [busy, setBusy] = useState(false); const [error, setError] = useState(null)
  const change = (field, value) => { draft.updateDraft((current) => ({ ...current, [field]: value })); setError(null) }
  const changeQuantity = (purchaseLineId, value) => { draft.updateDraft((current) => ({ ...current, lines: current.lines.map((line) => line.purchaseLineId === purchaseLineId ? { ...line, quantity: value } : line) })); setError(null) }
  const selectionUnavailable = (draft.value.locationId && !locations.some((location) => location.id === draft.value.locationId)) || (draft.value.personId && !people.some((person) => person.id === draft.value.personId)) || draft.value.lines.some((line) => !receivableLines.some((candidate) => candidate.purchase_line_id === line.purchaseLineId))
  async function submit(event) {
    event.preventDefault(); const selected = draft.value.lines.filter((line) => Number(line.quantity) > 0)
    if (!locations.some((location) => location.id === draft.value.locationId) || !people.some((person) => person.id === draft.value.personId) || !draft.value.receivedAt) return setError('Choose an active location, active PIC, and receiving time.')
    if (!selected.length || selected.some((line) => !positiveQuantity(line.quantity))) return setError('Enter a positive received quantity for at least one line.')
    if (selected.some((line) => Number(line.quantity) > Number(receivableLines.find((candidate) => candidate.purchase_line_id === line.purchaseLineId)?.remaining_quantity ?? 0))) return setError('Receiving quantity cannot exceed the remaining purchase quantity.')
    setBusy(true)
    try { await onReceive(draft.value, requestId.current); draft.clearDraft(); onClose() }
    catch (receiveError) { setError(receiveError.message || 'The receipt could not be posted.') }
    finally { setBusy(false) }
  }
  return <DialogFrame icon={CalendarCheck} kicker="Physical receiving" title={`Receive ${purchase.purchase_number}`} description="One atomic receipt posts immutable evidence and positive inventory ledger movements." titleId="inventory-receive-title" busy={busy} onClose={onClose}>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      {selectionUnavailable && <div className="draft-conflict-banner" role="alert"><AlertCircle size={17} /><div><strong>A saved receiving selection is unavailable.</strong><span>The draft was preserved without substituting another location, PIC, or purchase line.</span></div></div>}
      <div className="form-grid"><label className="form-field"><span>Receiving location <b className="required-mark">*</b></span><select value={draft.value.locationId} onChange={(event) => change('locationId', event.target.value)} data-dialog-initial-focus><option value="">Choose location</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.code} · {location.name}</option>)}</select></label><label className="form-field"><span>Received date & time <b className="required-mark">*</b></span><input type="datetime-local" max={localDateTime()} value={draft.value.receivedAt} onChange={(event) => change('receivedAt', event.target.value)} /></label><label className="form-field"><span>Physical receiver / PIC <b className="required-mark">*</b></span><select value={draft.value.personId} onChange={(event) => change('personId', event.target.value)}><option value="">Choose PIC</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}{person.code ? ` · ${person.code}` : ''}</option>)}</select></label></div>
      <section className="receipt-lines"><header><strong>Receive purchase lines</strong><span>Enter only quantities physically received now.</span></header>{receivableLines.map((line) => { const draftLine = draft.value.lines.find((candidate) => candidate.purchaseLineId === line.purchase_line_id); const now = Number(draftLine?.quantity) || 0; return <article key={line.purchase_line_id}><div><strong>{line.item_name_snapshot}</strong><span>{line.item_sku_snapshot} · {money.format(Number(line.unit_price))} / {line.unit_snapshot}</span></div><dl><div><dt>Ordered</dt><dd>{quantity(line.ordered_quantity)}</dd></div><div><dt>Previously received</dt><dd>{quantity(line.received_quantity)}</dd></div><div><dt>Remaining</dt><dd>{quantity(line.remaining_quantity)}</dd></div><label><span>Receiving now</span><input type="number" min="0" max={line.remaining_quantity} step="0.0001" inputMode="decimal" value={draftLine?.quantity ?? ''} onChange={(event) => changeQuantity(line.purchase_line_id, event.target.value)} /></label><div><dt>After receipt</dt><dd>{quantity(Number(line.received_quantity) + now)} {line.unit_snapshot}</dd></div></dl><small>Acquisition value now: {money.format(now * Number(line.unit_price))}</small></article> })}</section>
      <label className="form-field"><span>Receiving notes <small>Optional</small></span><textarea rows="2" value={draft.value.notes} onChange={(event) => change('notes', event.target.value)} /></label><FormError error={error} />
    </div><footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => draft.resetDraft(initial)} disabled={!draft.hasDraft || busy}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Receiving…' : 'Receive goods'}</button></footer></form>
  </DialogFrame>
}

export function InventoryPurchaseDetailDialog({ purchase, lines, canManage, onClose, onReceive, onCancel }) {
  const hasRemaining = lines.some((line) => Number(line.remaining_quantity) > 0)
  return <DialogFrame icon={PackageCheck} kicker="Purchase detail" title={purchase.purchase_number} description={`${purchase.supplier_name_snapshot} · ${new Date(`${purchase.purchase_date}T00:00:00`).toLocaleDateString('id-ID')}`} titleId="inventory-purchase-detail-title" onClose={onClose}>
    <div className="machine-form"><div className="machine-form-body"><div className="purchase-detail-summary"><div><span>Status</span><strong>{purchase.status.replaceAll('_', ' ')}</strong></div><div><span>Total</span><strong>{money.format(Number(purchase.purchase_total))}</strong></div><div><span>Receiving progress</span><strong>{quantity(purchase.receiving_progress_percent)}%</strong></div><div><span>Supplier reference</span><strong>{purchase.supplier_reference || '—'}</strong></div></div><section className="purchase-detail-lines">{lines.map((line) => <article key={line.purchase_line_id}><div><strong>{line.item_name_snapshot}</strong><span>{line.item_sku_snapshot}</span></div><dl><div><dt>Ordered</dt><dd>{quantity(line.ordered_quantity)} {line.unit_snapshot}</dd></div><div><dt>Received</dt><dd>{quantity(line.received_quantity)} {line.unit_snapshot}</dd></div><div><dt>Remaining</dt><dd>{quantity(line.remaining_quantity)} {line.unit_snapshot}</dd></div><div><dt>Unit price</dt><dd>{money.format(Number(line.unit_price))}</dd></div></dl></article>)}</section>{purchase.notes && <p className="purchase-detail-notes">{purchase.notes}</p>}</div><footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose}>Close</button>{canManage && purchase.status !== 'cancelled' && purchase.status !== 'received' && <button className="danger-button" type="button" onClick={onCancel}><XCircle size={16} />Cancel purchase</button>}{canManage && hasRemaining && purchase.status !== 'cancelled' && <button className="primary-button" type="button" onClick={onReceive}><CalendarCheck size={16} />Receive goods</button>}</footer></div>
  </DialogFrame>
}

export function CancelInventoryPurchaseDialog({ account, purchase, onClose, onCancel }) {
  const { user } = useAuth(); const initial = { reason: '' }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: 'inventory-purchase-cancel', entityId: purchase.purchase_id })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.reason === 'string' })
  const requestId = useRef(crypto.randomUUID()); const [busy, setBusy] = useState(false); const [error, setError] = useState(null)
  async function submit(event) { event.preventDefault(); if (!draft.value.reason.trim()) return setError('Cancellation reason is required. Existing receipts will remain unchanged.'); setBusy(true); try { await onCancel(draft.value.reason, requestId.current); draft.clearDraft(); onClose() } catch (cancelError) { setError(cancelError.message) } finally { setBusy(false) } }
  return <DialogFrame icon={XCircle} kicker="Purchase cancellation" title={`Cancel ${purchase.purchase_number}?`} description="Posted receipts and stock remain immutable. Cancellation never reverses inventory." titleId="inventory-purchase-cancel-title" busy={busy} onClose={onClose}><form className="machine-form" onSubmit={submit}><div className="machine-form-body"><label className="form-field"><span>Cancellation reason <b className="required-mark">*</b></span><textarea rows="3" value={draft.value.reason} onChange={(event) => { draft.updateDraft({ reason: event.target.value }); setError(null) }} data-dialog-initial-focus /></label><FormError error={error} /></div><footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Keep purchase</button><button className="danger-button" type="submit" disabled={busy}>{busy ? 'Cancelling…' : 'Cancel purchase'}</button></footer></form></DialogFrame>
}
