import { useMemo, useState } from 'react'
import { AlertCircle, ArrowRight, CheckCircle2, Clock3, Gauge, LoaderCircle, RotateCcw } from 'lucide-react'
import { recordCounterReading } from '../../services/supabase/counters.js'
import { formatCounter, mapCounterError, toLocalDateTimeInput } from './counterUtils.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { readLegacyDailyDraft } from '../drafts/draftStorage.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'

function createInitialCounterDraft(shiftCode = '') {
  return { readingValue: '', operatorPersonId: '', shiftCode, observedAt: toLocalDateTimeInput(), notes: '', clientRequestId: crypto.randomUUID() }
}

function isCounterDraft(value) {
  return value && [value.readingValue, value.shiftCode, value.observedAt, value.notes, value.clientRequestId].every((field) => typeof field === 'string')
    && (value.operatorPersonId === undefined || typeof value.operatorPersonId === 'string')
}

export function CounterEntryCard({ accountId, branchId, userId, machine, people, peopleLoading, peopleError, lastReading, onRecorded }) {
  const draftKey = createDraftKey({ userId, accountId, branchId, feature: 'daily-counter', entityId: machine.id })
  const {
    value: draft,
    updateDraft,
    hasDraft,
    wasRestored,
    clearDraft,
    resetDraft,
  } = usePersistentDraft({
    draftKey,
    initialValue: createInitialCounterDraft(),
    validate: isCounterDraft,
    legacyDraft: readLegacyDailyDraft(userId, machine.id),
  })
  const { readingValue, shiftCode, observedAt, notes, clientRequestId } = draft
  const operatorPersonId = draft.operatorPersonId ?? ''
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

  function markDraftChanged(field, value) {
    updateDraft((current) => ({ ...current, [field]: value }))
    setError(null)
    setSuccess(null)
  }

  function changeReading(value) {
    if (!/^\d*$/.test(value)) return
    markDraftChanged('readingValue', value)
  }

  function handleResetDraft() {
    resetDraft(createInitialCounterDraft())
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
    if (!operatorPersonId || !people.some((person) => person.id === operatorPersonId && person.is_active)) {
      setError('Choose an active PIC / Operator for this reading.')
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
        operatorPersonId,
        shiftCode,
        notes,
        clientRequestId,
      })
      clearDraft(createInitialCounterDraft(shiftCode))
      setSuccess(`Counter ${formatCounter(parsedValue)} recorded successfully.`)
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
        {wasRestored && <div className="draft-restored-status" role="status"><CheckCircle2 size={14} /><span>Unsaved draft restored</span></div>}
        <div className="counter-context-grid">
          <div><span>Counter type</span><strong>Total Impressions</strong><small>Cumulative machine counter</small></div>
          <div><span>Current / last counter</span><strong>{formatCounter(lastReading?.reading_value)}</strong><small>{lastReading ? 'Latest effective reading' : 'First entry becomes the baseline'}</small></div>
        </div>
        <div className="counter-primary-grid">
          <label className="counter-value-field"><span>New Counter <b>*</b></span><input value={readingValue} onChange={(event) => changeReading(event.target.value)} inputMode="numeric" pattern="[0-9]*" placeholder="207960" autoComplete="off" aria-invalid={Boolean(validationError || error)} /><small>Enter the cumulative number displayed by the machine—not daily usage.</small>{validationError && <span className="field-error"><AlertCircle size={13} />{validationError}</span>}</label>
          <label className="form-field counter-operator-field"><span>PIC / Operator <b className="required-mark">*</b></span><select value={operatorPersonId} onChange={(event) => markDraftChanged('operatorPersonId', event.target.value)} disabled={peopleLoading || Boolean(peopleError)} aria-invalid={Boolean(error && !operatorPersonId)}><option value="">{peopleLoading ? 'Loading operators…' : 'Choose PIC / Operator'}</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select><small>{peopleError ? 'Operator directory is unavailable. Refresh Daily and try again.' : 'The selected name is preserved as a historical snapshot.'}</small></label>
        </div>
        <div className="counter-form-grid">
          <label className="form-field"><span>Shift <small>Optional</small></span><select value={shiftCode} onChange={(event) => markDraftChanged('shiftCode', event.target.value)}><option value="">No shift specified</option><option value="S1">S1</option><option value="S2">S2</option></select></label>
          <label className="form-field"><span>Observed date/time <b className="required-mark">*</b></span><input type="datetime-local" value={observedAt} max={toLocalDateTimeInput(new Date(Date.now() + 5 * 60_000))} onChange={(event) => markDraftChanged('observedAt', event.target.value)} /></label>
          <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea value={notes} onChange={(event) => markDraftChanged('notes', event.target.value)} rows="3" placeholder="Optional context for this reading" /></label>
        </div>
        <div className="counter-preview"><div><span>Previous</span><strong>{formatCounter(previousValue)}</strong></div><ArrowRight size={18} /><div><span>New</span><strong>{formatCounter(parsedValue)}</strong></div><div className={previewUsage != null && previewUsage < 0 ? 'preview-usage invalid' : 'preview-usage'}><span>Usage preview</span><strong>{previewUsage == null ? 'Baseline' : `+${formatCounter(previewUsage)}`}</strong><small>Database-derived after save</small></div></div>
        {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
        {success && <div className="counter-success" role="status"><CheckCircle2 size={16} /><span>{success}</span></div>}
        <footer className="counter-submit-row"><div><Clock3 size={14} /><span>Multiple chronological entries per day are supported.</span></div><div className="counter-form-actions"><button className="draft-reset-button" type="button" onClick={handleResetDraft} disabled={!hasDraft || isSubmitting}><RotateCcw size={15} />Reset draft</button><button className="primary-button" type="submit" disabled={isSubmitting || Boolean(validationError)}>{isSubmitting ? <LoaderCircle className="spin" size={17} /> : <Gauge size={17} />}{isSubmitting ? 'Recording…' : 'Record counter'}</button></div></footer>
      </form>
    </section>
  )
}
