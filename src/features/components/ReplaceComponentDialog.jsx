import { useMemo, useState } from 'react'
import { Boxes, Gauge, LoaderCircle, MapPin, RefreshCcw, RotateCcw, UserRound, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { learningDefault, removalConditions, replacementReasons } from './componentReplacement.js'

function localDateTime() {
  const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  return now.toISOString().slice(0, 16)
}

function number(value) {
  return value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 4 })
}

function parseCounter(value) {
  const normalized = String(value).replace(/[\s,]/g, '')
  return normalized === '' ? NaN : Number(normalized)
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

export function ReplaceComponentDialog({ account, machine, lifecycle, members, currentProfile, inventoryItems, inventoryLocations, inventoryBalances, onClose, onReplace }) {
  const { user } = useAuth()
  const currentMember = members.find((member) => member.user_id === user.id)
  const initialValue = useMemo(() => ({
    physicalCounter: String(Math.trunc(Number(lifecycle.latest_effective_counter))),
    replacedAt: localDateTime(),
    performedBy: currentMember?.user_id ?? user.id,
    manualPic: '',
    reason: lifecycle.tracking_method === 'consumption_based' ? 'depleted' : 'normal_eol',
    condition: 'worn',
    includeLearning: true,
    notes: '',
    clientRequestId: crypto.randomUUID(),
    inventorySource: '',
    inventoryItemId: '',
    inventoryLocationId: '',
    inventoryQuantity: '1',
    externalInventoryReason: '',
  }), [currentMember?.user_id, lifecycle.latest_effective_counter, lifecycle.tracking_method, user.id])
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
  const performance = actualUsage != null && Number(lifecycle.expected_at_install) > 0
    ? actualUsage / Number(lifecycle.expected_at_install) * 100
    : null
  const manualPic = value.performedBy === 'manual'
  const selectedMember = members.find((member) => member.user_id === value.performedBy)
  const performerName = manualPic ? value.manualPic.trim() : selectedMember?.display_name ?? currentProfile?.display_name ?? user.email ?? 'User'
  const inventorySource = value.inventorySource ?? ''
  const eligibleItems = inventoryItems.filter((item) => item.component_id === lifecycle.component_id)
  const selectedInventoryItem = eligibleItems.find((item) => item.id === (value.inventoryItemId ?? '')) ?? null
  const selectedInventoryLocation = inventoryLocations.find((location) => location.id === (value.inventoryLocationId ?? '')) ?? null
  const availableQuantity = selectedInventoryItem && selectedInventoryLocation
    ? Number(inventoryBalances.find((balance) => balance.inventory_item_id === selectedInventoryItem.id && balance.location_id === selectedInventoryLocation.id)?.quantity ?? 0)
    : null
  const inventoryQuantity = Number(value.inventoryQuantity ?? '1')
  const afterQuantity = availableQuantity == null || !Number.isFinite(inventoryQuantity) ? null : availableQuantity - inventoryQuantity

  function change(field, next) {
    updateDraft((current) => ({ ...current, [field]: next }))
    setError(null)
  }

  function changeReason(reason) {
    updateDraft((current) => ({ ...current, reason, includeLearning: learningDefault(reason) }))
    setError(null)
  }

  async function submit(event) {
    event.preventDefault()
    if (!Number.isFinite(replacementCounter)) return setError('Masukkan counter fisik yang valid.')
    if (replacementCounter < Number(lifecycle.latest_effective_counter)) return setError('Counter penggantian tidak boleh lebih rendah dari counter efektif terbaru. Gunakan koreksi Daily Counter bila pembacaan sebelumnya salah.')
    if (!value.replacedAt) return setError('Tanggal dan waktu penggantian wajib diisi.')
    if (!performerName) return setError('PIC penggantian wajib diisi.')
    if (value.reason === 'other' && !value.notes.trim()) return setError('Catatan alasan wajib diisi untuk pilihan Lainnya.')
    if (!inventorySource) return setError('Pilih sumber komponen pengganti.')
    if (inventorySource === 'inventory') {
      if (!selectedInventoryItem) return setError('Pilih Inventory Item yang terhubung dengan komponen ini.')
      if (!selectedInventoryLocation) return setError('Pilih lokasi stok fisik.')
      if (!Number.isFinite(inventoryQuantity) || inventoryQuantity <= 0 || Math.round(inventoryQuantity * 10_000) !== inventoryQuantity * 10_000) return setError('Quantity yang digunakan harus positif dengan maksimal empat angka desimal.')
      if (availableQuantity < inventoryQuantity) return setError(`Stock ${selectedInventoryItem.name} di ${selectedInventoryLocation.name} tidak mencukupi. Tersedia ${number(availableQuantity)} ${selectedInventoryItem.unit}.`)
    }
    if (inventorySource === 'external_untracked' && !(value.externalInventoryReason ?? '').trim()) return setError('Alasan stok eksternal / belum tercatat wajib diisi.')

    setSaving(true)
    setError(null)
    try {
      await onReplace({
        replacementCounter,
        replacedAt: new Date(value.replacedAt).toISOString(),
        reason: value.reason,
        condition: value.condition,
        includeLearning: value.includeLearning,
        performedByUserId: manualPic ? null : selectedMember?.user_id ?? user.id,
        performedByName: performerName,
        notes: value.notes,
        clientRequestId: value.clientRequestId,
        inventorySource,
        inventoryItemId: selectedInventoryItem?.id ?? null,
        inventoryLocationId: selectedInventoryLocation?.id ?? null,
        inventoryQuantity,
        externalInventoryReason: value.externalInventoryReason ?? '',
      })
      clearDraft()
      onClose()
    } catch (saveError) {
      setError(saveError.message)
    } finally {
      setSaving(false)
    }
  }

  const reset = () => resetDraft({ ...initialValue, clientRequestId: crypto.randomUUID(), replacedAt: localDateTime() })

  return <BlockingDialog className="machine-dialog component-dialog replacement-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="replacement-dialog-title" describedBy="replacement-dialog-description" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><RefreshCcw size={22} /></span><div><span className="card-kicker">Auditable lifecycle transition</span><h2 id="replacement-dialog-title">{lifecycle.tracking_method === 'consumption_based' ? 'Replace / Refill Toner' : 'Replace Component'}</h2><p id="replacement-dialog-description">{lifecycle.component_name} · {machine.machine_code} · {lifecycle.slot_code}</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close replacement form"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit}>
      <div className="machine-form-body">
        {wasRestored && <div className="draft-restored-status">Unsaved replacement draft restored</div>}
        <div className="replacement-counter-context"><div><span>Latest recorded counter</span><strong>{number(lifecycle.latest_effective_counter)}</strong><small>Read from effective Daily Counter</small></div><label className="form-field"><span>Current physical counter *</span><input data-dialog-initial-focus inputMode="numeric" value={value.physicalCounter} onChange={(event) => change('physicalCounter', event.target.value)} /></label></div>
        <div className="replacement-preview"><span className="replacement-preview-icon"><Gauge size={19} /></span><div><span>Installed counter</span><strong>{number(lifecycle.installed_counter)}</strong></div><div><span>{lifecycle.tracking_method === 'consumption_based' ? 'Actual yield preview' : 'Actual usage preview'}</span><strong>{number(actualUsage)}</strong></div><div><span>Expected at install</span><strong>{number(lifecycle.expected_at_install)}</strong></div><div><span>Performance</span><strong>{performance == null ? '—' : `${performance.toFixed(1)}%`}</strong></div></div>
        <div className="form-grid"><label className="form-field"><span>Replacement date & time *</span><input type="datetime-local" value={value.replacedAt} onChange={(event) => change('replacedAt', event.target.value)} /></label><label className="form-field"><span>PIC / performed by *</span><select value={value.performedBy} onChange={(event) => change('performedBy', event.target.value)}>{members.map((member) => <option key={member.user_id} value={member.user_id}>{member.display_name}</option>)}<option value="manual">Manual PIC…</option></select></label>{manualPic && <label className="form-field form-field-wide"><span>Manual PIC name *</span><div className="input-with-icon"><UserRound size={16} /><input value={value.manualPic} onChange={(event) => change('manualPic', event.target.value)} placeholder="Nama pelaksana" /></div></label>}<label className="form-field"><span>Replacement reason *</span><select value={value.reason} onChange={(event) => changeReason(event.target.value)}>{replacementReasons.map((reason) => <option key={reason.value} value={reason.value}>{reason.label}</option>)}</select></label><label className="form-field"><span>Condition at removal *</span><select value={value.condition} onChange={(event) => change('condition', event.target.value)}>{removalConditions.map((condition) => <option key={condition.value} value={condition.value}>{condition.label}</option>)}</select></label></div>
        <section className="replacement-inventory-section" aria-labelledby="replacement-inventory-title">
          <div className="replacement-section-heading"><span className="replacement-preview-icon"><Boxes size={18} /></span><div><strong id="replacement-inventory-title">Inventory Source</strong><small>Record where the physical replacement came from.</small></div></div>
          <div className="replacement-source-options" role="radiogroup" aria-label="Inventory source">
            <label className={inventorySource === 'inventory' ? 'selected' : ''}><input type="radio" name="inventory-source" checked={inventorySource === 'inventory'} onChange={() => change('inventorySource', 'inventory')} /><span><strong>Ambil dari Inventory</strong><small>Post an atomic stock issue with this replacement.</small></span></label>
            <label className={inventorySource === 'external_untracked' ? 'selected' : ''}><input type="radio" name="inventory-source" checked={inventorySource === 'external_untracked'} onChange={() => change('inventorySource', 'external_untracked')} /><span><strong>Stok eksternal / belum tercatat</strong><small>Record the source explicitly without creating stock movement.</small></span></label>
          </div>
          {inventorySource === 'inventory' && <>
            <div className="form-grid replacement-inventory-fields">
              <label className="form-field"><span>Inventory Item *</span><select value={value.inventoryItemId ?? ''} onChange={(event) => updateDraft((current) => ({ ...current, inventoryItemId: event.target.value, inventoryLocationId: '' }))}><option value="">Select matching item</option>{eligibleItems.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.sku}</option>)}</select><small>{eligibleItems.length ? `Only items explicitly linked to ${lifecycle.component_name}.` : `No active Inventory Item is linked to ${lifecycle.component_name}.`}</small></label>
              <label className="form-field"><span>Stock Location *</span><div className="input-with-icon"><MapPin size={16} /><select value={value.inventoryLocationId ?? ''} onChange={(event) => change('inventoryLocationId', event.target.value)}><option value="">Select physical location</option>{inventoryLocations.map((location) => { const stock = selectedInventoryItem ? Number(inventoryBalances.find((balance) => balance.inventory_item_id === selectedInventoryItem.id && balance.location_id === location.id)?.quantity ?? 0) : 0; return <option key={location.id} value={location.id}>{location.name} · {number(stock)} {selectedInventoryItem?.unit ?? ''}</option> })}</select></div></label>
              <label className="form-field"><span>Quantity Used *</span><input type="number" min="0.0001" step="0.0001" value={value.inventoryQuantity ?? '1'} onChange={(event) => change('inventoryQuantity', event.target.value)} /><small>{selectedInventoryItem?.unit ?? 'Select an item to see its unit'}</small></label>
            </div>
            {selectedInventoryItem && selectedInventoryLocation && <div className="replacement-stock-preview"><div><span>Current stock</span><strong>{number(availableQuantity)} {selectedInventoryItem.unit}</strong></div><div><span>Used</span><strong>{Number.isFinite(inventoryQuantity) ? `-${number(inventoryQuantity)}` : '—'} {selectedInventoryItem.unit}</strong></div><div className={afterQuantity < 0 ? 'stock-preview-negative' : ''}><span>After replacement</span><strong>{afterQuantity == null ? '—' : number(afterQuantity)} {selectedInventoryItem.unit}</strong></div></div>}
          </>}
          {inventorySource === 'external_untracked' && <label className="form-field"><span>External stock reason *</span><textarea rows="2" value={value.externalInventoryReason ?? ''} onChange={(event) => change('externalInventoryReason', event.target.value)} placeholder="Contoh: Teknisi membawa sparepart" /></label>}
        </section>
        <label className="replacement-learning-toggle"><input type="checkbox" checked={value.includeLearning} onChange={(event) => change('includeLearning', event.target.checked)} /><span><strong>Include in adaptive learning</strong><small>Preserve this completed lifecycle as an eligible sample for database-derived intelligence.</small></span></label>
        <label className="form-field"><span>Notes {value.reason === 'other' ? '*' : ''}</span><textarea rows="3" value={value.notes} onChange={(event) => change('notes', event.target.value)} placeholder="Replacement context, observed condition, or reason detail" /></label>
        {replacementCounter > Number(lifecycle.latest_effective_counter) && <div className="replacement-counter-notice">A new Total Impressions reading of <strong>{number(replacementCounter)}</strong> will be recorded atomically with this replacement.</div>}
        {error && <div className="form-error">{error}</div>}
      </div>
      <footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={reset} disabled={!hasDraft || saving}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Confirm Replacement</button></footer>
    </form>
  </BlockingDialog>
}
