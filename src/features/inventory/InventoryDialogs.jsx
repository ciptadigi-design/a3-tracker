import { createElement, useRef, useState } from 'react'
import { AlertCircle, ArrowRightLeft, Boxes, LoaderCircle, MapPin, PackagePlus, RotateCcw, Scale, Trash2, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'

const units = [
  ['pcs', 'Pieces (pcs)'], ['bottle', 'Bottle'], ['set', 'Set'], ['roll', 'Roll'],
]

function localDateTime() {
  const date = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  return date.toISOString().slice(0, 16)
}

function optionalNumberValid(value) {
  return value === '' || (Number.isFinite(Number(value)) && Number(value) >= 0 && /^\d+(\.\d{1,4})?$/.test(String(value)))
}

function quantityValid(value) {
  return Number(value) > 0 && /^\d+(\.\d{1,4})?$/.test(String(value))
}

export function DialogFrame({ icon, kicker, title, description, titleId, busy, onClose, children }) {
  return <BlockingDialog className="machine-dialog inventory-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy={titleId} onClose={onClose} busy={busy}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon">{createElement(icon, { size: 22 })}</span><div><span className="card-kicker">{kicker}</span><h2 id={titleId}>{title}</h2><p>{description}</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={busy} aria-label="Close dialog"><X size={19} /></button></header>
    {children}
  </BlockingDialog>
}

export function InventoryItemDialog({ account, item, components, onClose, onSave }) {
  const { user } = useAuth()
  const initial = { sku: item?.sku ?? '', name: item?.name ?? '', componentId: item?.component_id ?? '', category: item?.category ?? '', unit: item?.unit ?? 'pcs', minimumStock: item?.minimum_stock ?? '', notes: item?.notes ?? '', isActive: item?.is_active ?? true }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: 'inventory-item', entityId: item?.id ?? 'new' })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.sku === 'string' && typeof value.name === 'string' && typeof value.isActive === 'boolean' })
  const [error, setError] = useState(null); const [busy, setBusy] = useState(false)
  const change = (field, value) => { draft.updateDraft((current) => ({ ...current, [field]: value })); setError(null) }
  async function submit(event) {
    event.preventDefault(); const value = draft.value
    if (!value.sku.trim() || !value.name.trim()) return setError('SKU / inventory code and name are required.')
    if (!optionalNumberValid(value.minimumStock)) return setError('Minimum stock must be zero or greater with at most four decimal places.')
    setBusy(true)
    try { await onSave(value); draft.clearDraft(); onClose() }
    catch (saveError) { setError(saveError.code === '23505' ? 'That SKU already exists in this workspace.' : saveError.message) }
    finally { setBusy(false) }
  }
  return <DialogFrame icon={Boxes} kicker="Inventory master" title={`${item ? 'Edit' : 'Add'} inventory item`} description="Defines what is stocked. Quantity remains exclusively ledger-derived." titleId="inventory-item-title" busy={busy} onClose={onClose}>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body"><div className="form-grid">
      <label className="form-field"><span>SKU / code <b className="required-mark">*</b></span><input value={draft.value.sku} onChange={(event) => change('sku', event.target.value)} autoComplete="off" data-dialog-initial-focus /></label>
      <label className="form-field"><span>Name <b className="required-mark">*</b></span><input value={draft.value.name} onChange={(event) => change('name', event.target.value)} autoComplete="off" /></label>
      <label className="form-field"><span>Linked component <small>Optional</small></span><select value={draft.value.componentId} onChange={(event) => change('componentId', event.target.value)}><option value="">No component link</option>{components.map((component) => <option key={component.id} value={component.id}>{component.code} · {component.name}</option>)}</select></label>
      <label className="form-field"><span>Category <small>Optional</small></span><input value={draft.value.category} onChange={(event) => change('category', event.target.value)} autoComplete="off" /></label>
      <label className="form-field"><span>Unit <b className="required-mark">*</b></span><select value={draft.value.unit} onChange={(event) => change('unit', event.target.value)}>{units.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <label className="form-field"><span>Minimum stock <small>Optional</small></span><input type="number" min="0" step="0.0001" value={draft.value.minimumStock} onChange={(event) => change('minimumStock', event.target.value)} inputMode="decimal" /></label>
      <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" value={draft.value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      {item && <label className="master-active-toggle"><input type="checkbox" checked={draft.value.isActive} onChange={(event) => change('isActive', event.target.checked)} /><span><strong>Active</strong><small>Archive to preserve all referenced movement history.</small></span></label>}
    </div>{error && <div className="form-error" role="alert"><AlertCircle size={16} />{error}</div>}</div>
    <footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => draft.resetDraft(initial)} disabled={!draft.hasDraft || busy}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Saving…' : 'Save item'}</button></footer></form>
  </DialogFrame>
}

export function InventoryLocationDialog({ account, branches, location, onClose, onSave }) {
  const { user } = useAuth()
  const initial = { code: location?.code ?? '', name: location?.name ?? '', branchId: location?.branch_id ?? '', notes: location?.notes ?? '', isActive: location?.is_active ?? true }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: 'inventory-location', entityId: location?.id ?? 'new' })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.code === 'string' && typeof value.name === 'string' && typeof value.isActive === 'boolean' })
  const [error, setError] = useState(null); const [busy, setBusy] = useState(false)
  const change = (field, value) => { draft.updateDraft((current) => ({ ...current, [field]: value })); setError(null) }
  async function submit(event) {
    event.preventDefault()
    if (!draft.value.code.trim() || !draft.value.name.trim()) return setError('Location code and name are required.')
    setBusy(true)
    try { await onSave(draft.value); draft.clearDraft(); onClose() }
    catch (saveError) { setError(saveError.code === '23505' ? 'That location code already exists in this workspace.' : saveError.message) }
    finally { setBusy(false) }
  }
  return <DialogFrame icon={MapPin} kicker="Stock location" title={`${location ? 'Edit' : 'Add'} location`} description="A physical stock point, optionally associated with a branch." titleId="inventory-location-title" busy={busy} onClose={onClose}>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body"><div className="form-grid">
      <label className="form-field"><span>Code <b className="required-mark">*</b></span><input value={draft.value.code} onChange={(event) => change('code', event.target.value)} autoComplete="off" data-dialog-initial-focus /></label>
      <label className="form-field"><span>Name <b className="required-mark">*</b></span><input value={draft.value.name} onChange={(event) => change('name', event.target.value)} autoComplete="off" /></label>
      <label className="form-field form-field-wide"><span>Branch <small>Optional</small></span><select value={draft.value.branchId} onChange={(event) => change('branchId', event.target.value)}><option value="">Account-wide / no branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} · {branch.name}</option>)}</select></label>
      <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" value={draft.value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      {location && <label className="master-active-toggle"><input type="checkbox" checked={draft.value.isActive} onChange={(event) => change('isActive', event.target.checked)} /><span><strong>Active</strong><small>Archived locations stay visible in historical movements.</small></span></label>}
    </div>{error && <div className="form-error" role="alert"><AlertCircle size={16} />{error}</div>}</div>
    <footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => draft.resetDraft(initial)} disabled={!draft.hasDraft || busy}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Saving…' : 'Save location'}</button></footer></form>
  </DialogFrame>
}

const workflowConfig = {
  opening: { icon: PackagePlus, kicker: 'Opening balance', title: 'Initialize physical stock', description: 'Posts one auditable opening movement. It never overwrites a balance.', submit: 'Post opening balance' },
  adjustment: { icon: Scale, kicker: 'Stock correction', title: 'Post manual adjustment', description: 'Records the difference found during a physical reconciliation.', submit: 'Post adjustment' },
  transfer: { icon: ArrowRightLeft, kicker: 'Location transfer', title: 'Transfer stock', description: 'Posts paired source and destination movements in one database transaction.', submit: 'Transfer stock' },
}

export function InventoryMovementDialog({ kind, account, item, items, locations, people, balances, onClose, onSubmit }) {
  const { user } = useAuth(); const config = workflowConfig[kind]
  const initial = { itemId: item?.id ?? items[0]?.id ?? '', locationId: '', sourceLocationId: '', destinationLocationId: '', quantity: '', direction: 'out', occurredAt: localDateTime(), personId: people[0]?.id ?? '', reason: '', notes: '', costState: 'unknown', unitCost: '' }
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: `inventory-${kind}`, entityId: item?.id ?? 'general' })
  const draft = usePersistentDraft({ draftKey, initialValue: initial, validate: (value) => value && typeof value.itemId === 'string' && typeof value.quantity === 'string' })
  const requestId = useRef(crypto.randomUUID()); const [error, setError] = useState(null); const [busy, setBusy] = useState(false)
  const change = (field, value) => { draft.updateDraft((current) => ({ ...current, [field]: value })); setError(null) }
  const activeItem = items.find((candidate) => candidate.id === draft.value.itemId)
  const selectedLocationAvailable = !draft.value.locationId || locations.some((location) => location.id === draft.value.locationId)
  const sourceLocationAvailable = !draft.value.sourceLocationId || locations.some((location) => location.id === draft.value.sourceLocationId)
  const destinationLocationAvailable = !draft.value.destinationLocationId || locations.some((location) => location.id === draft.value.destinationLocationId)
  const selectedPersonAvailable = !draft.value.personId || people.some((person) => person.id === draft.value.personId)
  const hasUnavailableSelection = (draft.value.itemId && !activeItem) || !selectedLocationAvailable || !sourceLocationAvailable || !destinationLocationAvailable || !selectedPersonAvailable
  const sourceBalance = Number(balances.find((row) => row.inventory_item_id === draft.value.itemId && row.location_id === draft.value.sourceLocationId)?.quantity ?? 0)
  async function submit(event) {
    event.preventDefault(); const value = draft.value
    if (!activeItem || !selectedPersonAvailable || !value.personId || !quantityValid(value.quantity) || !value.occurredAt) return setError('Choose an available item, active PIC, positive quantity, and effective time.')
    if (!selectedLocationAvailable || !sourceLocationAvailable || !destinationLocationAvailable) return setError('One of the saved locations is no longer active. Choose an available location before posting.')
    if (kind === 'opening' && !value.locationId) return setError('Choose an inventory location.')
    if (kind === 'adjustment' && (!value.locationId || !value.reason.trim())) return setError('Location and adjustment reason are required.')
    if (kind === 'transfer' && (!value.sourceLocationId || !value.destinationLocationId || value.sourceLocationId === value.destinationLocationId)) return setError('Choose different source and destination locations.')
    if ((kind === 'opening' || (kind === 'adjustment' && value.direction === 'in')) && value.costState === 'known' && (value.unitCost === '' || Number(value.unitCost) < 0)) return setError('Enter a valid nonnegative unit cost, or mark the cost basis unknown.')
    setBusy(true)
    try { await onSubmit(value, requestId.current); draft.clearDraft(); onClose() }
    catch (submitError) { setError(submitError.message || 'The inventory movement could not be posted.') }
    finally { setBusy(false) }
  }
  return <DialogFrame icon={config.icon} kicker={config.kicker} title={config.title} description={config.description} titleId="inventory-movement-title" busy={busy} onClose={onClose}>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      <div className="ledger-notice"><Scale size={17} /><span>Balance is database-derived. Posted movements cannot be edited or deleted.</span></div>
      {hasUnavailableSelection && <div className="draft-conflict-banner" role="alert"><AlertCircle size={17} /><div><strong>A saved Inventory selection is no longer available.</strong><span>The workflow and draft were restored without attaching them to another item, location, or PIC. Choose an active replacement before posting.</span></div></div>}
      <div className="form-grid">
        <label className="form-field form-field-wide"><span>Inventory item <b className="required-mark">*</b></span><select value={draft.value.itemId} onChange={(event) => change('itemId', event.target.value)} data-dialog-initial-focus><option value="">Choose item</option>{items.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.sku} · {candidate.name}</option>)}</select></label>
        {kind === 'transfer' ? <><label className="form-field"><span>Source location <b className="required-mark">*</b></span><select value={draft.value.sourceLocationId} onChange={(event) => change('sourceLocationId', event.target.value)}><option value="">Choose source</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select><small className="field-hint">Available: {sourceBalance.toLocaleString()} {activeItem?.unit}</small></label><label className="form-field"><span>Destination location <b className="required-mark">*</b></span><select value={draft.value.destinationLocationId} onChange={(event) => change('destinationLocationId', event.target.value)}><option value="">Choose destination</option>{locations.filter((location) => location.id !== draft.value.sourceLocationId).map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label></> : <label className="form-field"><span>Location <b className="required-mark">*</b></span><select value={draft.value.locationId} onChange={(event) => change('locationId', event.target.value)}><option value="">Choose location</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>}
        {kind === 'adjustment' && <label className="form-field"><span>Direction <b className="required-mark">*</b></span><select value={draft.value.direction} onChange={(event) => change('direction', event.target.value)}><option value="out">Decrease stock (−)</option><option value="in">Increase stock (+)</option></select></label>}
        {(kind === 'opening' || (kind === 'adjustment' && draft.value.direction === 'in')) && <><label className="form-field"><span>Cost basis <b className="required-mark">*</b></span><select value={draft.value.costState} onChange={(event) => change('costState', event.target.value)}><option value="unknown">Unknown cost</option><option value="known">Known unit cost</option></select><small className="field-hint">Unknown is preserved explicitly and never treated as zero.</small></label>{draft.value.costState === 'known' && <label className="form-field"><span>Unit cost (IDR) <b className="required-mark">*</b></span><input type="number" min="0" step="0.01" inputMode="decimal" value={draft.value.unitCost} onChange={(event) => change('unitCost', event.target.value)} /></label>}</>}
        <label className="form-field"><span>Quantity ({activeItem?.unit ?? 'unit'}) <b className="required-mark">*</b></span><input type="number" min="0.0001" step="0.0001" inputMode="decimal" value={draft.value.quantity} onChange={(event) => change('quantity', event.target.value)} /></label>
        <label className="form-field"><span>Effective date & time <b className="required-mark">*</b></span><input type="datetime-local" value={draft.value.occurredAt} max={localDateTime()} onChange={(event) => change('occurredAt', event.target.value)} /></label>
        <label className="form-field"><span>PIC / Operator <b className="required-mark">*</b></span><select value={draft.value.personId} onChange={(event) => change('personId', event.target.value)}><option value="">Choose PIC</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}{person.code ? ` · ${person.code}` : ''}</option>)}</select></label>
        {kind === 'adjustment' && <label className="form-field form-field-wide"><span>Reason <b className="required-mark">*</b></span><input value={draft.value.reason} onChange={(event) => change('reason', event.target.value)} placeholder="e.g. Physical stock correction" /></label>}
        <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" value={draft.value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      </div>{error && <div className="form-error" role="alert"><AlertCircle size={16} />{error}</div>}
    </div><footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => draft.resetDraft(initial)} disabled={!draft.hasDraft || busy}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className={kind === 'adjustment' && draft.value.direction === 'out' ? 'danger-button' : 'primary-button'} type="submit" disabled={busy}>{busy && <LoaderCircle className="spin" size={17} />}{busy ? 'Posting…' : config.submit}</button></footer></form>
  </DialogFrame>
}

export function DeleteInventoryMasterDialog({ kind, label, onClose, onDelete }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState(null)
  async function remove() { setBusy(true); try { await onDelete(); onClose() } catch (deleteError) { setError(deleteError.code === '23503' ? 'This record has ledger history or another reference. Archive it instead.' : deleteError.message) } finally { setBusy(false) } }
  return <BlockingDialog className="confirm-dialog glass-surface" labelledBy="inventory-delete-title" onClose={onClose} busy={busy}><span className="danger-dialog-icon"><Trash2 size={22} /></span><h2 id="inventory-delete-title">Delete {kind}?</h2><p><strong>{label}</strong> can only be deleted if it has never been referenced. Posted history is always preserved.</p>{error && <div className="form-error" role="alert">{error}</div>}<div className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button><button className="danger-button" type="button" onClick={remove} disabled={busy}>{busy ? 'Deleting…' : 'Delete permanently'}</button></div></BlockingDialog>
}
