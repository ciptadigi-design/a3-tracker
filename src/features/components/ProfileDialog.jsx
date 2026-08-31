import { useState } from 'react'
import { LoaderCircle, LockKeyhole, RotateCcw, SlidersHorizontal, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'

function initialValues({ profile, model, initialComponent }) {
  if (profile) {
    return {
      machineModelId: profile.machine_model_id,
      componentId: profile.component_id,
      slotCode: profile.slot_code,
      displayOrder: String(profile.display_order),
      trackingMethod: profile.tracking_method,
      baselineExpectedClicks: String(profile.baseline_expected_clicks ?? ''),
      adaptiveEnabled: profile.adaptive_enabled,
      healthyThreshold: String(profile.healthy_threshold_percent),
      watchThreshold: String(profile.watch_threshold_percent),
      warningThreshold: String(profile.warning_threshold_percent),
      criticalThreshold: String(profile.critical_threshold_percent),
      notes: profile.notes ?? '', clientRequestId: crypto.randomUUID(),
    }
  }
  return {
    machineModelId: model?.id ?? '',
    componentId: initialComponent?.id ?? '',
    slotCode: initialComponent?.code ?? '',
    displayOrder: '0',
    trackingMethod: initialComponent?.default_tracking_method ?? 'counter_based',
    baselineExpectedClicks: '',
    adaptiveEnabled: true,
    healthyThreshold: '30',
    watchThreshold: '15',
    warningThreshold: '5',
    criticalThreshold: '0',
    notes: '', clientRequestId: crypto.randomUUID(),
  }
}

function validDraft(value) {
  return value && typeof value.slotCode === 'string' && typeof value.componentId === 'string'
}

function profileErrorMessage(error) {
  if (error?.code === '23505') return 'That active slot is already assigned for this machine model. Choose a unique slot code.'
  if (error?.code === '23514') return 'The expected clicks or lifecycle thresholds are outside the allowed range.'
  if (error?.code === '42501') return 'Your current workspace role is not allowed to assign this component.'
  return error?.message ?? 'The profile could not be saved.'
}

export function ProfileDialog({ account, model, models, profile, components, initialComponent, draftEntityId, onClose, onSave }) {
  const { user } = useAuth()
  const initial = initialValues({ profile, model, initialComponent })
  const { value, updateDraft, resetDraft, clearDraft, hasDraft, wasRestored } = usePersistentDraft({
    draftKey: createDraftKey({
      userId: user.id,
      accountId: account.id,
      feature: 'component-profile',
      entityId: draftEntityId ?? profile?.id ?? `new-${model?.id ?? 'model'}`,
    }),
    initialValue: initial,
    metadata: { baseUpdatedAt: profile?.updated_at ?? null },
    validate: validDraft,
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const values = { ...initial, ...value, machineModelId: value.machineModelId ?? initial.machineModelId }
  const selectedModel = models.find((item) => item.id === values.machineModelId) ?? model
  const selectedComponent = components.find((item) => item.id === values.componentId)
  const isAssignment = Boolean(initialComponent) && !profile

  function change(field, next) {
    updateDraft((current) => ({ ...current, [field]: next }))
    setError(null)
  }

  async function submit(event) {
    event.preventDefault()
    const numericFields = ['displayOrder', 'healthyThreshold', 'watchThreshold', 'warningThreshold', 'criticalThreshold']
    if (!values.machineModelId || !values.componentId || !values.slotCode.trim()
      || numericFields.some((field) => values[field] === '' || Number.isNaN(Number(values[field])))
      || (values.baselineExpectedClicks !== '' && Number(values.baselineExpectedClicks) <= 0)) {
      setError('Complete the required fields with valid positive click values.')
      return
    }
    setSaving(true)
    setError(null)
    try {
      await onSave(values)
      clearDraft()
      onClose()
    } catch (saveError) {
      setError(profileErrorMessage(saveError))
    } finally {
      setSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog component-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="profile-dialog-title" onClose={onClose} busy={saving}>
        <header className="dialog-header">
          <div className="dialog-heading"><span className="dialog-icon"><SlidersHorizontal size={22} /></span><div><span className="card-kicker">Machine-model configuration</span><h2 id="profile-dialog-title">{profile ? 'Edit profile' : isAssignment ? 'Assign to model' : 'Add model profile'}</h2><p>A catalog definition is referenced, never duplicated.</p></div></div>
          <button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close profile form"><X size={19} /></button>
        </header>
        <form className="machine-form" onSubmit={submit}>
          <div className="machine-form-body">
            {wasRestored && <div className="draft-restored-status">Unsaved draft restored</div>}
            <div className="form-section-heading"><strong>Assignment</strong><span>Manufacturer does not imply machine-model compatibility.</span></div>
            <div className="form-grid">
              {profile ? <label className="form-field"><span>Machine model</span><div className="locked-field"><LockKeyhole size={15} /><span>{selectedModel?.manufacturers?.name} · {selectedModel?.name}</span></div></label>
                : <label className="form-field"><span>Machine model *</span><select value={values.machineModelId} onChange={(event) => change('machineModelId', event.target.value)}><option value="">Choose machine model</option>{models.map((item) => <option key={item.id} value={item.id}>{item.manufacturers?.name} · {item.name}</option>)}</select></label>}
              {isAssignment ? <label className="form-field"><span>Component</span><div className="locked-field"><LockKeyhole size={15} /><span>{initialComponent.name}</span></div><small>Uses catalog component {initialComponent.code}.</small></label>
                : profile ? <label className="form-field"><span>Component</span><div className="locked-field"><LockKeyhole size={15} /><span>{selectedComponent?.name}</span></div></label>
                  : <label className="form-field"><span>Component *</span><select value={values.componentId} onChange={(event) => { const component = components.find((item) => item.id === event.target.value); updateDraft((current) => ({ ...current, componentId: event.target.value, slotCode: current.slotCode || component?.code || '', trackingMethod: component?.default_tracking_method === 'counter_based' ? 'counter_based' : 'counter_based' })); setError(null) }}><option value="">Choose component</option>{components.filter((component) => component.is_active).map((component) => <option key={component.id} value={component.id}>{component.name}</option>)}</select></label>}
              <label className="form-field"><span>Slot code *</span><input value={values.slotCode} onChange={(event) => change('slotCode', event.target.value)} placeholder="DRUM_C" /></label>
              <label className="form-field"><span>Tracking method</span><select value={values.trackingMethod} onChange={(event) => change('trackingMethod', event.target.value)}><option value="counter_based">Counter based</option><option value="consumption_based" disabled>Consumption based (Coming soon)</option><option value="inspection_based" disabled>Inspection based (Coming soon)</option></select><small>Counter based is currently the fully supported lifecycle method.</small></label>
              <label className="form-field"><span>Baseline expected clicks</span><input type="number" min="1" value={values.baselineExpectedClicks} onChange={(event) => change('baselineExpectedClicks', event.target.value)} /></label>
              <label className="form-field"><span>Display order</span><input type="number" min="0" value={values.displayOrder} onChange={(event) => change('displayOrder', event.target.value)} /></label>
              <label className="form-field checkbox-field"><input type="checkbox" checked={values.adaptiveEnabled} onChange={(event) => change('adaptiveEnabled', event.target.checked)} /><span>Adaptive foundation enabled</span></label>
            </div>
            <div className="form-section-heading"><strong>Remaining-life thresholds</strong><span>Overdue is always 0% or below.</span></div>
            <div className="threshold-grid">{[['healthyThreshold', 'Healthy above %'], ['watchThreshold', 'Watch above %'], ['warningThreshold', 'Warning above %'], ['criticalThreshold', 'Critical above %']].map(([field, label]) => <label className="form-field" key={field}><span>{label}</span><input type="number" min="0" max="100" step="0.01" value={values[field]} onChange={(event) => change(field, event.target.value)} /></label>)}</div>
            <label className="form-field"><span>Notes</span><textarea rows="3" value={values.notes} onChange={(event) => change('notes', event.target.value)} /></label>
            {error && <div className="form-error">{error}</div>}
          </div>
          <footer className="dialog-actions form-action-footer"><button className="draft-reset-button" type="button" onClick={() => resetDraft(initial)} disabled={!hasDraft || saving} aria-label="Reset draft" title="Reset draft"><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}{profile ? 'Save profile' : 'Create assignment'}</button></footer>
        </form>
    </BlockingDialog>
  )
}
