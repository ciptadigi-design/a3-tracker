import { useState } from 'react'
import { Gauge, LoaderCircle, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'

export function SetTargetDialog({ machine, monthLabel, currentTarget, onClose, onSave }) {
  const [monthlyClickTarget, setMonthlyClickTarget] = useState(currentTarget ? String(currentTarget) : '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  async function submit(event) {
    event.preventDefault()
    const value = Number(monthlyClickTarget)
    if (!Number.isInteger(value) || value < 1) return setError('Monthly click target must be a whole number of at least 1.')
    setSaving(true); setError(null)
    try { await onSave({ monthlyClickTarget: value, reason: reason.trim(), clientRequestId: crypto.randomUUID() }); onClose() }
    catch (saveError) { setError(saveError.message) } finally { setSaving(false) }
  }

  return <BlockingDialog className="machine-dialog set-target-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="set-target-title" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><Gauge size={22} /></span><div><span className="card-kicker">{machine.machine_code} · {machine.display_name}</span><h2 id="set-target-title">{currentTarget ? 'Revise' : 'Set'} {monthLabel} click target</h2><p>Daily and weekly targets are derived automatically across active dates.</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      <div className="form-grid">
        <label className="form-field"><span>Monthly click target *</span><input data-dialog-initial-focus inputMode="numeric" value={monthlyClickTarget} onChange={(event) => /^\d*$/.test(event.target.value) && setMonthlyClickTarget(event.target.value)} placeholder="500000" /></label>
        {currentTarget != null && <label className="form-field form-field-wide"><span>Reason for change <small>Optional</small></span><textarea rows="3" maxLength="1000" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="e.g. Seasonal demand" /></label>}
      </div>{error && <div className="form-error" role="alert">{error}</div>}
    </div><footer className="dialog-actions form-action-footer"><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Save target</button></footer></form>
  </BlockingDialog>
}
