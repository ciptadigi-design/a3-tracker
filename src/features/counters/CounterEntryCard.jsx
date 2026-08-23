import { useMemo, useState } from 'react'
import { AlertCircle, ArrowRight, CheckCircle2, Clock3, Gauge, LoaderCircle } from 'lucide-react'
import { recordCounterReading } from '../../services/supabase/counters.js'
import { formatCounter, mapCounterError, toLocalDateTimeInput } from './counterUtils.js'

export function CounterEntryCard({ accountId, machine, lastReading, onRecorded }) {
  const [readingValue, setReadingValue] = useState('')
  const [shiftCode, setShiftCode] = useState('')
  const [observedAt, setObservedAt] = useState(() => toLocalDateTimeInput())
  const [notes, setNotes] = useState('')
  const [clientRequestId, setClientRequestId] = useState(() => crypto.randomUUID())
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const parsedValue = readingValue === '' ? null : Number(readingValue)
  const previousValue = lastReading ? Number(lastReading.reading_value) : null
  const previewUsage = parsedValue == null || previousValue == null ? null : parsedValue - previousValue
  const validationError = useMemo(() => {
    if (!readingValue) return null
    if (!/^\d+$/.test(readingValue) || !Number.isSafeInteger(parsedValue)) return 'Enter Total Impressions as a whole number.'
    if (previousValue != null && parsedValue < previousValue) return `New counter must be at least ${formatCounter(previousValue)}.`
    return null
  }, [parsedValue, previousValue, readingValue])

  function changeReading(value) {
    if (/^\d*$/.test(value)) setReadingValue(value)
    setError(null)
    setSuccess(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSubmitting) return
    if (!readingValue || validationError) {
      setError(validationError || 'Enter the cumulative counter shown on the machine.')
      return
    }
    if (!observedAt) {
      setError('Choose the observed date and time.')
      return
    }

    setIsSubmitting(true)
    setError(null)
    setSuccess(null)
    try {
      await recordCounterReading({
        accountId,
        machineId: machine.id,
        readingValue: parsedValue,
        observedAt: new Date(observedAt).toISOString(),
        shiftCode,
        notes,
        clientRequestId,
      })
      setSuccess(`Counter ${formatCounter(parsedValue)} recorded successfully.`)
      setReadingValue('')
      setNotes('')
      setClientRequestId(crypto.randomUUID())
      setObservedAt(toLocalDateTimeInput())
      await onRecorded()
    } catch (submitError) {
      setError(mapCounterError(submitError))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <section className="counter-entry-card glass-surface">
      <header className="counter-card-header"><span className="counter-card-icon"><Gauge size={23} /></span><div><span className="card-kicker">Counter input</span><h2>Record cumulative counter</h2><p>{machine.display_name} · {machine.machine_code}</p></div></header>
      <form className="counter-form" onSubmit={handleSubmit} noValidate>
        <div className="counter-context-grid">
          <div><span>Counter type</span><strong>Total Impressions</strong><small>Fixed for M2.1</small></div>
          <div><span>Current / last counter</span><strong>{formatCounter(lastReading?.reading_value)}</strong><small>{lastReading ? 'Latest effective reading' : 'First entry becomes the baseline'}</small></div>
        </div>
        <label className="counter-value-field"><span>New Counter <b>*</b></span><input value={readingValue} onChange={(event) => changeReading(event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="207960" autoComplete="off" aria-invalid={Boolean(validationError || error)} /><small>Enter the cumulative number displayed by the machine—not daily usage.</small>{validationError && <span className="field-error"><AlertCircle size={13} />{validationError}</span>}</label>
        <div className="counter-form-grid">
          <label className="form-field"><span>Shift <small>Optional</small></span><select value={shiftCode} onChange={(event) => setShiftCode(event.target.value)}><option value="">No shift specified</option><option value="S1">S1</option><option value="S2">S2</option></select></label>
          <label className="form-field"><span>Observed date/time <b className="required-mark">*</b></span><input type="datetime-local" value={observedAt} max={toLocalDateTimeInput(new Date(Date.now() + 5 * 60_000))} onChange={(event) => setObservedAt(event.target.value)} /></label>
          <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows="3" placeholder="Optional context for this reading" /></label>
        </div>
        <div className="counter-preview"><div><span>Previous</span><strong>{formatCounter(previousValue)}</strong></div><ArrowRight size={18} /><div><span>New</span><strong>{formatCounter(parsedValue)}</strong></div><div className={previewUsage != null && previewUsage < 0 ? 'preview-usage invalid' : 'preview-usage'}><span>Usage preview</span><strong>{previewUsage == null ? 'Baseline' : `+${formatCounter(previewUsage)}`}</strong><small>Database-derived after save</small></div></div>
        {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
        {success && <div className="counter-success" role="status"><CheckCircle2 size={16} /><span>{success}</span></div>}
        <footer className="counter-submit-row"><div><Clock3 size={14} /><span>Multiple chronological entries per day are supported.</span></div><button className="primary-button" type="submit" disabled={isSubmitting || Boolean(validationError)}>{isSubmitting ? <LoaderCircle className="spin" size={17} /> : <Gauge size={17} />}{isSubmitting ? 'Recording…' : 'Record counter'}</button></footer>
      </form>
    </section>
  )
}
