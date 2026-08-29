import { useState } from 'react'
import { Gauge, LoaderCircle, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { useAuth } from '../auth/useAuth.js'

const blank = { mode: 'tracking_start', installedCounter: '', installedDate: '', notes: '' }
const validDraft = (value) => value && ['tracking_start', 'manual_historical'].includes(value.mode)
  && typeof value.installedCounter === 'string' && typeof value.installedDate === 'string' && typeof value.notes === 'string'

function formatCounter(value) {
  return value == null ? 'Unavailable' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

export function InitializeLifecycleDialog({ account, machine, lifecycle, onClose, onInitialize }) {
  const { user } = useAuth()
  const { value, updateDraft, clearDraft, resetDraft, hasDraft, wasRestored } = usePersistentDraft({
    draftKey: createDraftKey({ userId: user.id, accountId: account.id, branchId: machine.branch_id, feature: 'component-lifecycle-initialize', entityId: lifecycle.assignment_id }),
    initialValue: blank,
    metadata: { lifecycleUpdatedAt: lifecycle.updated_at },
    validate: validDraft,
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  function change(field, next) {
    updateDraft((current) => ({ ...current, [field]: next }))
    setError(null)
  }

  async function submit(event) {
    event.preventDefault()
    const historical = value.mode === 'manual_historical'
    const installedCounter = historical ? Number(value.installedCounter) : null
    if (historical && (value.installedCounter.trim() === '' || !Number.isFinite(installedCounter) || installedCounter < 0)) {
      return setError('Enter a valid known replacement counter.')
    }
    if (historical && installedCounter > Number(lifecycle.latest_effective_counter)) {
      return setError('The replacement counter cannot exceed the current recorded counter.')
    }

    setSaving(true)
    setError(null)
    try {
      await onInitialize({
        installedCounter,
        installedAt: historical && value.installedDate ? `${value.installedDate}T00:00:00+07:00` : null,
        notes: value.notes,
        clientRequestId: crypto.randomUUID(),
      })
      clearDraft()
      onClose()
    } catch (saveError) {
      setError(saveError.message)
    } finally {
      setSaving(false)
    }
  }

  return <BlockingDialog className="machine-dialog component-dialog lifecycle-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="lifecycle-dialog-title" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><Gauge size={22} /></span><div><span className="card-kicker">Start lifecycle tracking</span><h2 id="lifecycle-dialog-title">Initialize Lifecycle</h2><p>{lifecycle.component_name} · {machine.machine_code}</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close initialization form"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit}>
      <div className="machine-form-body">
        {wasRestored && <div className="draft-restored-status">Unsaved initialization draft restored</div>}
        <div className="initialization-purpose"><Gauge size={17} /><span><strong>Start tracking a component that is already installed. Inventory will not change.</strong> Initialization does not mean a new component is being installed.</span></div>
        <div className="lifecycle-context"><div><span>Component</span><strong>{lifecycle.component_name}</strong></div><div><span>Machine</span><strong>{machine.machine_code}</strong></div><div><span>Current recorded counter</span><strong>{formatCounter(lifecycle.latest_effective_counter)}</strong></div></div>
        <fieldset className="initialization-choice"><legend>What is known?</legend><label className={value.mode === 'manual_historical' ? 'selected' : ''}><input type="radio" name="mode" checked={value.mode === 'manual_historical'} onChange={() => change('mode', 'manual_historical')} /><span><strong>Known last replacement counter</strong><small>Reconstruct usage from a trustworthy historical counter.</small></span></label><label className={value.mode === 'tracking_start' ? 'selected' : ''}><input type="radio" name="mode" checked={value.mode === 'tracking_start'} onChange={() => change('mode', 'tracking_start')} /><span><strong>Initialize as of current counter</strong><small>This starts tracking from now and does not reconstruct previous component usage. It does not mean the component is physically new.</small></span></label></fieldset>
        {value.mode === 'manual_historical' && <div className="form-grid"><label className="form-field"><span>Known replacement counter *</span><input inputMode="numeric" value={value.installedCounter} onChange={(event) => change('installedCounter', event.target.value)} placeholder="e.g. 1,405,775" /></label><label className="form-field"><span>Known replacement date</span><input type="date" value={value.installedDate} onChange={(event) => change('installedDate', event.target.value)} /></label></div>}
        <label className="form-field"><span>Notes</span><textarea rows="3" value={value.notes} onChange={(event) => change('notes', event.target.value)} placeholder="Optional source or context" /></label>
        {error && <div className="form-error">{error}</div>}
      </div>
      <footer className="dialog-actions form-action-footer"><button className="draft-reset-button" type="button" onClick={() => resetDraft(blank)} disabled={!hasDraft || saving} aria-label="Reset draft" title="Reset draft"><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Initialize lifecycle</button></footer>
    </form>
  </BlockingDialog>
}
