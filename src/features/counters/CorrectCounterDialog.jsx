import { useState } from 'react'
import { AlertCircle, FilePenLine, LoaderCircle, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { correctCounterReading } from '../../services/supabase/counters.js'
import { formatCounter, mapCounterError } from './counterUtils.js'

export function CorrectCounterDialog({ reading, onClose, onCorrected }) {
  const [action, setAction] = useState('replace')
  const [replacementValue, setReplacementValue] = useState(() => String(Number(reading.reading_value)))
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState(reading.notes ?? '')
  const [clientRequestId] = useState(() => crypto.randomUUID())
  const [error, setError] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const replacement = replacementValue === '' ? null : Number(replacementValue)
  const previous = reading.previous_value == null ? null : Number(reading.previous_value)
  const invalidReplacement = action === 'replace' && (
    !/^\d+$/.test(replacementValue)
    || !Number.isSafeInteger(replacement)
    || (previous != null && replacement < previous)
  )

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSubmitting) return
    if (!reason.trim()) {
      setError('Explain why this reading needs correction.')
      return
    }
    if (invalidReplacement) {
      setError(previous == null ? 'Enter a valid whole-number replacement.' : `Replacement must be at least ${formatCounter(previous)}.`)
      return
    }

    setIsSubmitting(true)
    setError(null)
    try {
      await correctCounterReading({
        readingId: reading.reading_id,
        correctionReason: reason,
        replacementValue: action === 'replace' ? replacement : null,
        replacementNotes: notes,
        clientRequestId,
      })
      await onCorrected(action)
      onClose()
    } catch (correctionError) {
      setError(mapCounterError(correctionError))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog correction-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="correction-dialog-title" describedBy="correction-dialog-description" onClose={onClose} busy={isSubmitting}>
        <header className="dialog-header">
          <div className="dialog-heading">
            <span className="dialog-icon"><FilePenLine size={22} /></span>
            <div>
              <h2 id="correction-dialog-title">Correct latest reading</h2>
              <p id="correction-dialog-description">History is append-only. Replace the latest value with an audited correction, or void it without deleting the record.</p>
            </div>
          </div>
          <button className="icon-button" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close correction form"><X size={19} /></button>
        </header>
        <form className="machine-form correction-form" onSubmit={handleSubmit}>
          <div className="machine-form-body correction-form-body">
            <div className="correction-action-tabs" role="group" aria-label="Correction action"><button type="button" className={action === 'replace' ? 'selected' : ''} aria-pressed={action === 'replace'} onClick={() => { setAction('replace'); setError(null) }}>Replace value</button><button type="button" className={action === 'void' ? 'selected danger-tab' : ''} aria-pressed={action === 'void'} onClick={() => { setAction('void'); setError(null) }}>Void reading</button></div>
            <div className="correction-reading-context"><span>Current value</span><strong>{formatCounter(reading.reading_value)}</strong><small>{reading.shift_code || 'No shift'} · {new Date(reading.observed_at).toLocaleString()}</small></div>
            {action === 'replace' && <label className="form-field"><span>Corrected counter <b className="required-mark">*</b></span><input value={replacementValue} onChange={(event) => { if (/^\d*$/.test(event.target.value)) setReplacementValue(event.target.value); setError(null) }} inputMode="numeric" aria-invalid={invalidReplacement} /><small>{previous == null ? 'This remains the first baseline.' : `Previous effective baseline: ${formatCounter(previous)}`}</small></label>}
            {action === 'void' && <div className="correction-void-warning"><AlertCircle size={17} /><span>The reading remains in audit history and is excluded from the effective counter sequence.</span></div>}
            <label className="form-field"><span>{action === 'void' ? 'Void reason' : 'Correction reason'} <b className="required-mark">*</b></span><textarea value={reason} onChange={(event) => { setReason(event.target.value); setError(null) }} rows="3" placeholder={action === 'void' ? 'Why should this reading be voided?' : 'Why is the original reading incorrect?'} /></label>
            {action === 'replace' && <label className="form-field"><span>Replacement notes <small>Optional</small></span><textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows="2" /></label>}
            {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
          </div>
          <footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={isSubmitting}>Cancel</button><button className={action === 'void' ? 'danger-button' : 'primary-button'} type="submit" disabled={isSubmitting || invalidReplacement}>{isSubmitting && <LoaderCircle className="spin" size={17} />}{isSubmitting ? 'Saving correction…' : action === 'void' ? 'Void reading' : 'Save replacement'}</button></footer>
        </form>
    </BlockingDialog>
  )
}
