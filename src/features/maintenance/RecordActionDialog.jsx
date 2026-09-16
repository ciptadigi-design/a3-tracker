import { useState } from 'react'
import { AlertCircle, LoaderCircle, PencilLine, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapMaintenanceError } from './maintenanceUtils.js'

const resultOptions = [
  { value: '', label: 'Not specified' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'escalated', label: 'Escalated' },
  { value: 'no_fault_found', label: 'No fault found' },
]

export function RecordActionDialog({ onClose, onSave }) {
  const [actionDescription, setActionDescription] = useState('')
  const [result, setResult] = useState('')
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    if (!actionDescription.trim()) {
      setError('Describe what was done.')
      return
    }
    setIsSaving(true)
    setError(null)
    try {
      await onSave({ action_description: actionDescription.trim(), result: result || null })
      onClose()
    } catch (saveError) {
      setError(mapMaintenanceError(saveError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="maintenance-action-dialog-title" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><PencilLine size={22} /></span><div><span className="card-kicker">Maintenance</span><h2 id="maintenance-action-dialog-title">Record an action</h2><p>Add to this ticket's resolution history.</p></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close"><X size={19} /></button>
      </header>
      <form className="machine-form" onSubmit={handleSubmit} noValidate>
        <div className="machine-form-body">
          <div className="form-grid">
            <label className="form-field form-field-wide"><span>What was done <b className="required-mark">*</b></span>
              <textarea value={actionDescription} onChange={(event) => setActionDescription(event.target.value)} rows="4" placeholder="e.g. Replaced fuser unit, recalibrated feed rollers" />
            </label>
            <label className="form-field"><span>Result</span>
              <select value={result} onChange={(event) => setResult(event.target.value)}>{resultOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
            </label>
          </div>
          {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
        </div>
        <footer className="dialog-actions form-action-footer">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Cancel</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : <PencilLine size={17} />}{isSaving ? 'Saving…' : 'Record action'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
