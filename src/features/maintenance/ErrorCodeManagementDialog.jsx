import { useState } from 'react'
import { AlertCircle, ListPlus, LoaderCircle, PencilLine, Plus, Trash2, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { addErrorCodeSolution, createMachineErrorCode, deleteErrorCodeSolution, updateErrorCodeSolution, updateMachineErrorCode } from '../../services/maintenance.js'
import { errorCodeSeverityLabels, mapMaintenanceError } from './maintenanceUtils.js'

function FieldError({ message }) {
  return message ? <small className="field-error"><AlertCircle size={13} />{message}</small> : null
}

function emptyForm(errorCode) {
  return {
    code: errorCode?.code ?? '',
    title: errorCode?.title ?? '',
    category: errorCode?.category ?? '',
    severity: errorCode?.severity ?? 'warning',
    manufacturer_description: errorCode?.manufacturer_description ?? '',
    operator_description: errorCode?.operator_description ?? '',
    official_solution: errorCode?.official_solution ?? '',
    solution_summary: errorCode?.solution_summary ?? '',
  }
}

function StepForm({ initial, onCancel, onSubmit, submitLabel }) {
  const [stepNumber, setStepNumber] = useState(String(initial?.step_number ?? ''))
  const [instruction, setInstruction] = useState(initial?.instruction ?? '')
  const [requiresTechnician, setRequiresTechnician] = useState(initial?.requires_technician ?? false)
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    const parsedStep = Number(stepNumber)
    if (!Number.isInteger(parsedStep) || parsedStep < 1) { setError('Step number must be a whole number of 1 or more.'); return }
    if (!instruction.trim()) { setError('Instruction is required.'); return }
    setIsSaving(true)
    setError(null)
    try {
      await onSubmit({ step_number: parsedStep, instruction: instruction.trim(), requires_technician: requiresTechnician })
    } catch (submitError) {
      setError(mapMaintenanceError(submitError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <form className="form-grid maintenance-step-form" onSubmit={handleSubmit}>
      <label className="form-field"><span>Step #</span><input value={stepNumber} onChange={(event) => setStepNumber(event.target.value)} inputMode="numeric" style={{ maxWidth: '5rem' }} /></label>
      <label className="form-field form-field-wide"><span>Instruction</span><textarea value={instruction} onChange={(event) => setInstruction(event.target.value)} rows="2" placeholder="e.g. Power cycle the machine and wait 30 seconds." /></label>
      <label className="form-field checkbox-field"><input type="checkbox" checked={requiresTechnician} onChange={(event) => setRequiresTechnician(event.target.checked)} /><span>Requires a technician</span></label>
      {error && <FieldError message={error} />}
      <div className="dialog-actions">
        <button className="secondary-button" type="button" onClick={onCancel} disabled={isSaving}>Cancel</button>
        <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={15} /> : null} {submitLabel}</button>
      </div>
    </form>
  )
}

export function ErrorCodeManagementDialog({ errorCode, accountId, onClose, onSaved }) {
  const [current, setCurrent] = useState(errorCode)
  const isEdit = Boolean(current?.id)
  const [values, setValues] = useState(() => emptyForm(errorCode))
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const [stepWorkflow, setStepWorkflow] = useState(null) // null | 'add' | step.id being edited

  function change(field, value) {
    setValues((prev) => ({ ...prev, [field]: value }))
    setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  function validate() {
    const next = {}
    if (!isEdit && !values.code.trim()) next.code = 'Code is required.'
    if (!values.title.trim()) next.title = 'Title is required.'
    return next
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    const nextErrors = validate()
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length) { setFormError('Check the highlighted fields.'); return }
    setIsSaving(true)
    setFormError(null)
    try {
      const payload = { ...values, code: values.code.trim().toUpperCase() }
      const saved = isEdit ? await updateMachineErrorCode(current.id, payload) : await createMachineErrorCode({ ...payload, account_id: accountId })
      setCurrent((prev) => ({ ...prev, ...saved }))
      onSaved()
    } catch (error) {
      setFormError(mapMaintenanceError(error))
    } finally {
      setIsSaving(false)
    }
  }

  async function handleAddStep(payload) {
    const step = await addErrorCodeSolution(current.id, payload)
    setCurrent((prev) => ({ ...prev, solutions: [...(prev.solutions ?? []), step].sort((a, b) => a.step_number - b.step_number) }))
    setStepWorkflow(null)
    onSaved()
  }

  async function handleUpdateStep(stepId, payload) {
    const step = await updateErrorCodeSolution(current.id, stepId, payload)
    setCurrent((prev) => ({ ...prev, solutions: prev.solutions.map((s) => (s.id === stepId ? step : s)).sort((a, b) => a.step_number - b.step_number) }))
    setStepWorkflow(null)
    onSaved()
  }

  async function handleDeleteStep(stepId) {
    await deleteErrorCodeSolution(current.id, stepId)
    setCurrent((prev) => ({ ...prev, solutions: prev.solutions.filter((s) => s.id !== stepId) }))
    onSaved()
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="error-code-dialog-title" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon">{isEdit ? <PencilLine size={22} /> : <Plus size={22} />}</span><div><span className="card-kicker">Maintenance knowledge base</span><h2 id="error-code-dialog-title">{isEdit ? `Edit ${current.code}` : 'Add error code'}</h2><p>Manufacturer/tenant error codes with an ordered resolution procedure.</p></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close"><X size={19} /></button>
      </header>
      <form className="machine-form" onSubmit={handleSubmit} noValidate>
        <div className="machine-form-body">
          <div className="form-grid">
            {!isEdit && <label className="form-field"><span>Code <b className="required-mark">*</b></span><input value={values.code} onChange={(event) => change('code', event.target.value)} placeholder="e.g. C-2801" aria-invalid={Boolean(errors.code)} /><FieldError message={errors.code} /></label>}
            <label className="form-field form-field-wide"><span>Title <b className="required-mark">*</b></span><input value={values.title} onChange={(event) => change('title', event.target.value)} placeholder="e.g. Fuser unit error" aria-invalid={Boolean(errors.title)} /><FieldError message={errors.title} /></label>
            <label className="form-field"><span>Category</span><input value={values.category} onChange={(event) => change('category', event.target.value)} placeholder="e.g. fuser" /></label>
            <label className="form-field"><span>Severity</span><select value={values.severity} onChange={(event) => change('severity', event.target.value)}>{Object.entries(errorCodeSeverityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className="form-field form-field-wide"><span>Solution summary <small>Short recommended fix</small></span><textarea value={values.solution_summary} onChange={(event) => change('solution_summary', event.target.value)} rows="2" placeholder="e.g. Restart machine and check registration sensor condition." /></label>
            <label className="form-field form-field-wide"><span>Operator description <small>Optional</small></span><textarea value={values.operator_description} onChange={(event) => change('operator_description', event.target.value)} rows="2" /></label>
            <label className="form-field form-field-wide"><span>Manufacturer description <small>Optional</small></span><textarea value={values.manufacturer_description} onChange={(event) => change('manufacturer_description', event.target.value)} rows="2" /></label>
            <label className="form-field form-field-wide"><span>Official solution <small>Optional</small></span><textarea value={values.official_solution} onChange={(event) => change('official_solution', event.target.value)} rows="2" /></label>
          </div>
          {formError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
          <footer className="dialog-actions form-action-footer">
            <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>{isEdit ? 'Close' : 'Cancel'}</button>
            <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : (isEdit ? <PencilLine size={17} /> : <Plus size={17} />)} {isSaving ? 'Saving…' : isEdit ? 'Save changes' : 'Create error code'}</button>
          </footer>
        </div>
      </form>

      {isEdit && (
        <section className="maintenance-step-section">
          <div className="form-section-heading"><strong>Ordered solution steps</strong><span>Shown to operators/technicians in order.</span></div>
          <ol className="incident-narrative-list">
            {(current.solutions ?? []).map((step) => (
              <li key={step.id} className="incident-narrative-card glass-surface">
                {stepWorkflow === step.id ? (
                  <StepForm initial={step} submitLabel="Save step" onCancel={() => setStepWorkflow(null)} onSubmit={(payload) => handleUpdateStep(step.id, payload)} />
                ) : (
                  <>
                    <span>{step.step_number}</span>
                    <div>
                      <p>{step.instruction}</p>
                      {step.requires_technician && <small>Requires a technician</small>}
                    </div>
                    <div className="dialog-actions">
                      <button className="icon-button" type="button" onClick={() => setStepWorkflow(step.id)} aria-label={`Edit step ${step.step_number}`}><PencilLine size={15} /></button>
                      <button className="icon-button" type="button" onClick={() => handleDeleteStep(step.id)} aria-label={`Remove step ${step.step_number}`}><Trash2 size={15} /></button>
                    </div>
                  </>
                )}
              </li>
            ))}
          </ol>
          {stepWorkflow === 'add' ? (
            <StepForm initial={{ step_number: (current.solutions ?? []).length + 1 }} submitLabel="Add step" onCancel={() => setStepWorkflow(null)} onSubmit={handleAddStep} />
          ) : (
            <button className="secondary-button" type="button" onClick={() => setStepWorkflow('add')}><ListPlus size={16} /> Add solution step</button>
          )}
        </section>
      )}
    </BlockingDialog>
  )
}
