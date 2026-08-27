import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { AlertCircle, FilePenLine, LoaderCircle, X } from 'lucide-react'
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
  const dialogRef = useRef(null)
  const closeButtonRef = useRef(null)
  const isSubmittingRef = useRef(false)
  const onCloseRef = useRef(onClose)

  useEffect(() => { isSubmittingRef.current = isSubmitting }, [isSubmitting])
  useEffect(() => { onCloseRef.current = onClose }, [onClose])

  useEffect(() => {
    const previouslyFocused = document.activeElement
    const previousRootOverflow = document.documentElement.style.overflow
    const previousOverflow = document.body.style.overflow
    const previousPaddingRight = document.body.style.paddingRight
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth
    const bodyPaddingRight = Number.parseFloat(window.getComputedStyle(document.body).paddingRight) || 0

    document.documentElement.style.overflow = 'hidden'
    document.body.style.overflow = 'hidden'
    if (scrollbarWidth > 0) document.body.style.paddingRight = `${bodyPaddingRight + scrollbarWidth}px`
    closeButtonRef.current?.focus()

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !isSubmittingRef.current) {
        event.preventDefault()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab') return

      const focusableElements = [...(dialogRef.current?.querySelectorAll('button:not(:disabled), input:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])') ?? [])]
      if (!focusableElements.length) return
      const firstElement = focusableElements[0]
      const lastElement = focusableElements[focusableElements.length - 1]
      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault()
        firstElement.focus()
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => {
      window.removeEventListener('keydown', handleKeyDown)
      document.documentElement.style.overflow = previousRootOverflow
      document.body.style.overflow = previousOverflow
      document.body.style.paddingRight = previousPaddingRight
      previouslyFocused?.focus()
    }
  }, [])

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

  return createPortal(
    <div className="dialog-backdrop machine-dialog-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !isSubmitting) onClose() }}>
      <section ref={dialogRef} className="machine-dialog correction-dialog glass-surface" role="dialog" aria-modal="true" aria-labelledby="correction-dialog-title" aria-describedby="correction-dialog-description">
        <header className="dialog-header">
          <div className="dialog-heading">
            <span className="dialog-icon"><FilePenLine size={22} /></span>
            <div>
              <h2 id="correction-dialog-title">Correct latest reading</h2>
              <p id="correction-dialog-description">History is append-only. Replace the latest value with an audited correction, or void it without deleting the record.</p>
            </div>
          </div>
          <button ref={closeButtonRef} className="icon-button" type="button" onClick={onClose} disabled={isSubmitting} aria-label="Close correction form"><X size={19} /></button>
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
      </section>
    </div>,
    document.body,
  )
}
