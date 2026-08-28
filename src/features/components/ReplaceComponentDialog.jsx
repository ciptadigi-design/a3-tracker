import { useMemo, useState } from 'react'
import { AlertTriangle, Boxes, LoaderCircle, RefreshCcw, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { learningDefault, removalConditions, replacementReasons } from './componentReplacement.js'
import { resolveReplacementInventorySource } from './lifecycleActions.js'
import { formatCounterInput, normalizeCounterInput, resolveReplacementPic } from './replacementForm.js'
import { inventoryItemLabel } from '../inventory/inventoryItemPresentation.js'

function localDateTime() {
  const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  return now.toISOString().slice(0, 16)
}

function number(value) {
  return value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 4 })
}

function parseCounter(value) {
  const normalized = normalizeCounterInput(value)
  return normalized ? Number(normalized) : NaN
}

const validDraft = (value) => value
  && typeof value.physicalCounter === 'string'
  && typeof value.replacedAt === 'string'
  && typeof value.performedBy === 'string'
  && typeof value.manualPic === 'string'
  && replacementReasons.some((item) => item.value === value.reason)
  && removalConditions.some((item) => item.value === value.condition)
  && typeof value.includeLearning === 'boolean'
  && typeof value.notes === 'string'
  && typeof value.clientRequestId === 'string'
  && (value.inventorySource == null || ['', 'inventory', 'external_untracked'].includes(value.inventorySource))
  && (value.inventoryItemId == null || typeof value.inventoryItemId === 'string')
  && (value.inventoryLocationId == null || typeof value.inventoryLocationId === 'string')
  && (value.inventoryQuantity == null || typeof value.inventoryQuantity === 'string')
  && (value.externalInventoryReason == null || typeof value.externalInventoryReason === 'string')

export function ReplaceComponentDialog({ account, machine, lifecycle, operationalPeople, inventoryItems, inventoryLocations, inventoryBalances, onClose, onReplace }) {
  const { user } = useAuth()
  const initialValue = useMemo(() => ({
    physicalCounter: String(Math.trunc(Number(lifecycle.latest_effective_counter))),
    replacedAt: localDateTime(), performedBy: '', manualPic: '',
    reason: lifecycle.tracking_method === 'consumption_based' ? 'depleted' : 'normal_eol',
    condition: 'worn', includeLearning: true, notes: '', clientRequestId: crypto.randomUUID(),
    inventorySource: 'inventory', inventoryItemId: '', inventoryLocationId: '', inventoryQuantity: '1', externalInventoryReason: '',
  }), [lifecycle.latest_effective_counter, lifecycle.tracking_method])
  const { value, updateDraft, clearDraft, resetDraft, hasDraft, wasRestored } = usePersistentDraft({
    draftKey: createDraftKey({ userId: user.id, accountId: account.id, branchId: machine.branch_id, feature: 'component-replacement', entityId: lifecycle.lifecycle_id }),
    initialValue,
    metadata: { lifecycleUpdatedAt: lifecycle.updated_at, machineId: machine.id, slotCode: lifecycle.slot_code },
    validate: validDraft,
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const replacementCounter = parseCounter(value.physicalCounter)
  const actualUsage = Number.isFinite(replacementCounter) ? replacementCounter - Number(lifecycle.installed_counter) : null
  const performance = actualUsage != null && Number(lifecycle.expected_at_install) > 0 ? actualUsage / Number(lifecycle.expected_at_install) * 100 : null
  const pic = resolveReplacementPic(operationalPeople, value.performedBy)
  const activePeople = operationalPeople.filter((person) => person.is_active)
  const performerName = pic.mode === 'manual' ? value.manualPic.trim() : pic.person?.name ?? ''
  const inventorySource = resolveReplacementInventorySource(value.inventorySource)
  const eligibleItems = inventoryItems.filter((item) => item.component_id === lifecycle.component_id)
  const selectedInventoryItem = eligibleItems.find((item) => item.id === (value.inventoryItemId ?? '')) ?? null
  const selectedInventoryLocation = inventoryLocations.find((location) => location.id === (value.inventoryLocationId ?? '')) ?? null
  const availableQuantity = selectedInventoryItem && selectedInventoryLocation
    ? Number(inventoryBalances.find((balance) => balance.inventory_item_id === selectedInventoryItem.id && balance.location_id === selectedInventoryLocation.id)?.quantity ?? 0)
    : null
  const inventoryQuantity = Number(value.inventoryQuantity ?? '1')
  const afterQuantity = availableQuantity == null || !Number.isFinite(inventoryQuantity) ? null : availableQuantity - inventoryQuantity

  function change(field, next) { updateDraft((current) => ({ ...current, [field]: next })); setError(null) }
  function changeCounter(next) { const normalized = normalizeCounterInput(next); if (normalized != null) change('physicalCounter', normalized) }
  function changeReason(reason) { updateDraft((current) => ({ ...current, reason, includeLearning: learningDefault(reason) })); setError(null) }

  async function submit(event) {
    event.preventDefault()
    if (!Number.isFinite(replacementCounter)) return setError('Enter a valid physical counter.')
    if (replacementCounter < Number(lifecycle.latest_effective_counter)) return setError('Replacement counter cannot be lower than the latest effective counter. Correct Daily Counter first if needed.')
    if (!value.replacedAt) return setError('Replacement date and time are required.')
    if (pic.stale) return setError('The previously selected PIC is no longer active or available. Choose another PIC.')
    if (pic.mode === 'unselected') return setError('Choose an active PIC or select Manual PIC.')
    if (!performerName) return setError('Manual PIC name is required.')
    if (value.reason === 'other' && !value.notes.trim()) return setError('Notes are required when the replacement reason is Other.')
    if (inventorySource === 'inventory') {
      if (!selectedInventoryItem) return setError('Choose an Inventory Item linked to this component.')
      if (!selectedInventoryLocation) return setError('Choose the physical stock location.')
      if (!Number.isFinite(inventoryQuantity) || inventoryQuantity <= 0 || Math.round(inventoryQuantity * 10_000) !== inventoryQuantity * 10_000) return setError('Quantity used must be positive with at most four decimal places.')
      if (availableQuantity < inventoryQuantity) return setError(`Stock for ${selectedInventoryItem.name} at ${selectedInventoryLocation.name} is insufficient. Available: ${number(availableQuantity)} ${selectedInventoryItem.unit}.`)
    }
    if (inventorySource === 'external_untracked' && !(value.externalInventoryReason ?? '').trim()) return setError('External / Untracked reason is required.')
    setSaving(true); setError(null)
    try {
      await onReplace({
        replacementCounter, replacedAt: new Date(value.replacedAt).toISOString(), reason: value.reason, condition: value.condition,
        includeLearning: value.includeLearning, performedByPersonId: pic.mode === 'operational' ? pic.person.id : null,
        performedByName: performerName, notes: value.notes, clientRequestId: value.clientRequestId, inventorySource,
        inventoryItemId: selectedInventoryItem?.id ?? null, inventoryLocationId: selectedInventoryLocation?.id ?? null,
        inventoryQuantity, externalInventoryReason: value.externalInventoryReason ?? '',
      })
      clearDraft(); onClose()
    } catch (saveError) { setError(saveError.message) } finally { setSaving(false) }
  }

  const reset = () => resetDraft({ ...initialValue, clientRequestId: crypto.randomUUID(), replacedAt: localDateTime() })

  return <BlockingDialog className="machine-dialog component-dialog replacement-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="replacement-dialog-title" describedBy="replacement-dialog-description" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><RefreshCcw size={22} /></span><div><span className="card-kicker">Physical component change</span><h2 id="replacement-dialog-title">{lifecycle.tracking_method === 'consumption_based' ? 'Replace / Refill Toner' : 'Replace Component'}</h2><p id="replacement-dialog-description">{lifecycle.component_name} · {machine.machine_code}</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close replacement form"><X size={19} /></button></header>
    <form className="machine-form replacement-form" onSubmit={submit} noValidate>
      <div className="machine-form-body">
        {wasRestored && <div className="draft-restored-status">Unsaved replacement draft restored</div>}
        <section className="replacement-form-section" aria-labelledby="replacement-current-title">
          <header><span id="replacement-current-title">Current state</span><small>Review the physical counter and lifecycle evidence.</small></header>
          <div className="replacement-state-grid">
            <label className="replacement-counter-field"><span>Current physical counter *</span><input data-dialog-initial-focus inputMode="numeric" pattern="[0-9,]*" value={formatCounterInput(value.physicalCounter)} onChange={(event) => changeCounter(event.target.value)} /><small>Latest recorded: {number(lifecycle.latest_effective_counter)}</small></label>
            <div><span>Installed counter</span><strong>{number(lifecycle.installed_counter)}</strong></div>
            <div><span>{lifecycle.tracking_method === 'consumption_based' ? 'Actual yield' : 'Actual usage'}</span><strong>{number(actualUsage)}</strong></div>
            <div><span>{lifecycle.tracking_method === 'consumption_based' ? 'Expected yield' : 'Expected life'}</span><strong>{number(lifecycle.expected_at_install)}</strong></div>
            <div><span>Performance</span><strong>{performance == null ? '—' : `${performance.toFixed(1)}%`}</strong></div>
          </div>
        </section>
        <section className="replacement-form-section" aria-labelledby="replacement-info-title">
          <header><span id="replacement-info-title">Replacement information</span><small>Who performed the physical replacement and why.</small></header>
          <div className="form-grid replacement-info-grid">
            <label className="form-field"><span>Replacement date & time *</span><input type="datetime-local" value={value.replacedAt} onChange={(event) => change('replacedAt', event.target.value)} /></label>
            <label className="form-field"><span>PIC / Performed By *</span><select value={value.performedBy} onChange={(event) => change('performedBy', event.target.value)} aria-invalid={pic.stale}><option value="">Choose PIC / Performed By</option>{pic.stale && <option value={value.performedBy}>Previously selected PIC unavailable — reselect</option>}{activePeople.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}<option disabled>──────────</option><option value="manual">Manual PIC…</option></select><small>{pic.stale ? 'This saved selection is no longer active. Choose another PIC.' : 'The selected name is preserved as an immutable snapshot.'}</small></label>
            {pic.mode === 'manual' && <label className="form-field form-field-wide"><span>Manual PIC Name *</span><input value={value.manualPic} onChange={(event) => change('manualPic', event.target.value)} placeholder="Enter the person’s name" required /></label>}
            <label className="form-field"><span>Reason *</span><select value={value.reason} onChange={(event) => changeReason(event.target.value)}>{replacementReasons.map((reason) => <option key={reason.value} value={reason.value}>{reason.label}</option>)}</select></label>
            <label className="form-field"><span>Condition at Removal *</span><select value={value.condition} onChange={(event) => change('condition', event.target.value)}>{removalConditions.map((condition) => <option key={condition.value} value={condition.value}>{condition.label}</option>)}</select></label>
          </div>
        </section>
        <section className="replacement-form-section replacement-inventory-section" aria-labelledby="replacement-inventory-title">
          <header><span className="replacement-section-icon"><Boxes size={17} /></span><div><span id="replacement-inventory-title">Inventory</span><small>Choose where the installed replacement came from.</small></div></header>
          <div className="replacement-source-options" role="radiogroup" aria-label="Inventory source">
            <label className={inventorySource === 'inventory' ? 'selected' : ''}><input type="radio" name="inventory-source" checked={inventorySource === 'inventory'} onChange={() => change('inventorySource', 'inventory')} /><span><strong>Inventory</strong><small>Consume tracked stock and record the issue.</small></span></label>
            <label className={inventorySource === 'external_untracked' ? 'selected' : ''}><input type="radio" name="inventory-source" checked={inventorySource === 'external_untracked'} onChange={() => change('inventorySource', 'external_untracked')} /><span><strong>External / Untracked</strong><small>Exception for stock outside tracked Inventory.</small></span></label>
          </div>
          {inventorySource === 'inventory' && <>
            <div className="form-grid replacement-inventory-fields">
              <label className="form-field"><span>Inventory Item *</span><select value={value.inventoryItemId ?? ''} onChange={(event) => updateDraft((current) => ({ ...current, inventoryItemId: event.target.value, inventoryLocationId: '' }))}><option value="">Select matching item</option>{eligibleItems.map((item) => <option key={item.id} value={item.id}>{inventoryItemLabel(item)}</option>)}</select><small>{eligibleItems.length ? `Only items explicitly linked to ${lifecycle.component_name}.` : `No active Inventory Item is linked to ${lifecycle.component_name}.`}</small></label>
              <label className="form-field"><span>Stock Location *</span><select value={value.inventoryLocationId ?? ''} onChange={(event) => change('inventoryLocationId', event.target.value)}><option value="">Select physical location</option>{inventoryLocations.map((location) => { const stock = selectedInventoryItem ? Number(inventoryBalances.find((balance) => balance.inventory_item_id === selectedInventoryItem.id && balance.location_id === location.id)?.quantity ?? 0) : 0; return <option key={location.id} value={location.id}>{location.name} · {number(stock)} {selectedInventoryItem?.unit ?? ''}</option> })}</select></label>
              <label className="form-field replacement-quantity-field"><span>Quantity Used *</span><input type="number" min="0.0001" step="0.0001" value={value.inventoryQuantity ?? '1'} onChange={(event) => change('inventoryQuantity', event.target.value)} /><small>{selectedInventoryItem?.unit ?? 'Select an item first'}</small></label>
            </div>
            {selectedInventoryItem && selectedInventoryLocation && <div className="replacement-stock-preview"><div><span>Available</span><strong>{number(availableQuantity)} {selectedInventoryItem.unit}</strong></div><div><span>Quantity used</span><strong>{Number.isFinite(inventoryQuantity) ? number(inventoryQuantity) : '—'} {selectedInventoryItem.unit}</strong></div><div className={afterQuantity < 0 ? 'stock-preview-negative' : ''}><span>After stock</span><strong>{afterQuantity == null ? '—' : number(afterQuantity)} {selectedInventoryItem.unit}</strong></div></div>}
          </>}
          {inventorySource === 'external_untracked' && <><div className="replacement-external-warning"><AlertTriangle size={17} /><span>This replacement is not using tracked Inventory. Stock will not decrease and acquisition cost may remain unknown.</span></div><label className="form-field"><span>External reason *</span><textarea rows="2" value={value.externalInventoryReason ?? ''} onChange={(event) => change('externalInventoryReason', event.target.value)} placeholder="Explain where this component came from" required /></label></>}
        </section>
        <section className="replacement-form-section" aria-labelledby="replacement-learning-title"><header><span id="replacement-learning-title">Learning</span></header><label className="replacement-learning-toggle"><input type="checkbox" checked={value.includeLearning} onChange={(event) => change('includeLearning', event.target.checked)} /><span><strong>Include in adaptive learning</strong><small>Use this completed lifecycle as eligible evidence for component intelligence.</small></span></label></section>
        <section className="replacement-form-section" aria-labelledby="replacement-notes-title"><header><span id="replacement-notes-title">Notes</span></header><label className="form-field"><span>Operational notes {value.reason === 'other' ? '*' : ''}</span><textarea rows="3" value={value.notes} onChange={(event) => change('notes', event.target.value)} placeholder="Replacement context or observed condition" /></label></section>
        {replacementCounter > Number(lifecycle.latest_effective_counter) && <div className="replacement-counter-notice">A Total Impressions reading of <strong>{number(replacementCounter)}</strong> will be recorded atomically with this replacement.</div>}
        {error && <div className="form-error" role="alert">{error}</div>}
      </div>
      <footer className="dialog-actions replacement-dialog-actions"><button className="draft-reset-button" type="button" onClick={reset} disabled={!hasDraft || saving}><RotateCcw size={15} />Reset draft</button><div><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Confirm Replacement</button></div></footer>
    </form>
  </BlockingDialog>
}
