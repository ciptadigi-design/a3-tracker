import { useState } from 'react'
import { CalendarOff, LoaderCircle, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { exceptionTypes } from './clickTargetModel.js'

export function CalendarExceptionDialog({ defaultDate, onClose, onSave }) {
  const [calendarDate, setCalendarDate] = useState(defaultDate)
  const [exceptionType, setExceptionType] = useState('store_closed')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  async function submit(event) {
    event.preventDefault()
    if (!calendarDate) return setError('Choose a date.')
    setSaving(true); setError(null)
    try { await onSave({ calendarDate, exceptionType, notes: notes.trim(), clientRequestId: crypto.randomUUID() }); onClose() }
    catch (saveError) { setError(saveError.message) } finally { setSaving(false) }
  }

  return <BlockingDialog className="machine-dialog calendar-exception-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="calendar-exception-title" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><CalendarOff size={22} /></span><div><span className="card-kicker">Operational Calendar</span><h2 id="calendar-exception-title">Exclude a date</h2><p>Excluded dates receive zero target and are not counted as missed.</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      <div className="form-grid">
        <label className="form-field"><span>Date *</span><input data-dialog-initial-focus type="date" value={calendarDate} onChange={(event) => setCalendarDate(event.target.value)} /></label>
        <label className="form-field"><span>Reason *</span><select value={exceptionType} onChange={(event) => setExceptionType(event.target.value)}>{exceptionTypes.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" maxLength="1000" value={notes} onChange={(event) => setNotes(event.target.value)} /></label>
      </div>{error && <div className="form-error" role="alert">{error}</div>}
    </div><footer className="dialog-actions form-action-footer"><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Exclude date</button></footer></form>
  </BlockingDialog>
}
