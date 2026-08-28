import { useMemo, useState } from 'react'
import { CircleDollarSign, LoaderCircle, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { localDateTimeInZone, sellingPriceValidation, validSellingPriceDraft, zonedLocalDateTimeToISOString } from './sellingPriceModel.js'

export function SellingPriceDialog({ account, branch, machine, timezone, hasPrice, onClose, onSave }) {
  const { user } = useAuth()
  const initialValue = useMemo(() => ({ pricePerClick: '', effectiveFrom: localDateTimeInZone(timezone), notes: '', clientRequestId: crypto.randomUUID() }), [timezone])
  const { value, updateDraft, clearDraft, resetDraft, hasDraft, wasRestored } = usePersistentDraft({
    draftKey: createDraftKey({ userId: user.id, accountId: account.id, branchId: branch.id, feature: 'machine-selling-price', entityId: machine.id }),
    initialValue, validate: validSellingPriceDraft, metadata: { machineId: machine.id, timezone },
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const change = (field, next) => { updateDraft((current) => ({ ...current, [field]: next })); setError(null) }
  async function submit(event) {
    event.preventDefault()
    const validation = sellingPriceValidation(value)
    if (validation) return setError(validation)
    setSaving(true); setError(null)
    try {
      await onSave({ ...value, effectiveFrom: zonedLocalDateTimeToISOString(value.effectiveFrom, timezone), notes: value.notes.trim() })
      clearDraft(); onClose()
    } catch (saveError) { setError(saveError.message) } finally { setSaving(false) }
  }
  return <BlockingDialog className="machine-dialog selling-price-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="selling-price-title" describedBy="selling-price-description" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><CircleDollarSign size={22} /></span><div><span className="card-kicker">Machine Economics</span><h2 id="selling-price-title">{hasPrice ? 'Change Selling Price' : 'Set Selling Price'}</h2><p id="selling-price-description">{machine.display_name} · {machine.machine_code}. New evidence starts at the effective time; prior history is preserved.</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close selling price form"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      {wasRestored && <div className="draft-restored-status" role="status">Unsaved selling-price draft restored</div>}
      <div className="form-grid selling-price-form-grid">
        <label className="form-field"><span>Machine</span><input value={`${machine.machine_code} · ${machine.display_name}`} readOnly /></label>
        <label className="form-field"><span>Selling Price / Click (IDR) *</span><input data-dialog-initial-focus inputMode="decimal" value={value.pricePerClick} onChange={(event) => /^\d*(\.\d{0,4})?$/.test(event.target.value) && change('pricePerClick', event.target.value)} placeholder="800" /></label>
        <label className="form-field"><span>Effective Date &amp; Time *</span><input type="datetime-local" value={value.effectiveFrom} onChange={(event) => change('effectiveFrom', event.target.value)} /><small>{timezone} machine time</small></label>
        <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" maxLength="1000" value={value.notes} onChange={(event) => change('notes', event.target.value)} placeholder="Reason or commercial context" /></label>
      </div>{error && <div className="form-error" role="alert">{error}</div>}
    </div><footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => resetDraft({ ...initialValue, clientRequestId: crypto.randomUUID() })} disabled={!hasDraft || saving}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Save Selling Price</button></footer></form>
  </BlockingDialog>
}
