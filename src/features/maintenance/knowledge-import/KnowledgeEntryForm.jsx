import { useState } from 'react'
import { AlertCircle, LoaderCircle } from 'lucide-react'
import { errorCodeSeverityLabels, knowledgeTypeLabels, mapMaintenanceError } from '../maintenanceUtils.js'

function FieldError({ message }) {
  return message ? <small className="field-error"><AlertCircle size={13} />{message}</small> : null
}

function emptyForm(entry) {
  return {
    knowledge_type: entry?.knowledge_type ?? 'ERROR_CODE',
    code: entry?.code ?? '',
    title: entry?.title ?? '',
    category: entry?.category ?? '',
    severity: entry?.severity ?? '',
    description: entry?.description ?? '',
    operator_solution: entry?.operator_solution ?? '',
    technician_solution: entry?.technician_solution ?? '',
    page_reference: entry?.page_reference ?? '',
  }
}

export function KnowledgeEntryForm({ entry, onCancel, onSubmit, submitLabel = 'Add entry' }) {
  const [values, setValues] = useState(() => emptyForm(entry))
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  function change(field, value) {
    setValues((prev) => ({ ...prev, [field]: value }))
    setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  function validate() {
    const next = {}
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
      await onSubmit({
        ...values,
        code: values.code.trim() || null,
        category: values.category.trim() || null,
        severity: values.severity || null,
        description: values.description.trim() || null,
        operator_solution: values.operator_solution.trim() || null,
        technician_solution: values.technician_solution.trim() || null,
        page_reference: values.page_reference.trim() || null,
      })
    } catch (submitError) {
      setFormError(mapMaintenanceError(submitError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <form className="form-grid maintenance-step-form" onSubmit={handleSubmit}>
      <label className="form-field"><span>Knowledge type</span>
        <select value={values.knowledge_type} onChange={(event) => change('knowledge_type', event.target.value)}>{Object.entries(knowledgeTypeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
      </label>
      <label className="form-field"><span>Code <small>Optional</small></span><input value={values.code} onChange={(event) => change('code', event.target.value)} placeholder="e.g. C-3101" /></label>
      <label className="form-field form-field-wide"><span>Title</span><input value={values.title} onChange={(event) => change('title', event.target.value)} placeholder="e.g. Image adjustment error" aria-invalid={Boolean(errors.title)} /><FieldError message={errors.title} /></label>
      <label className="form-field"><span>Category <small>Optional</small></span><input value={values.category} onChange={(event) => change('category', event.target.value)} /></label>
      <label className="form-field"><span>Severity <small>Optional</small></span>
        <select value={values.severity} onChange={(event) => change('severity', event.target.value)}>
          <option value="">Not specified</option>
          {Object.entries(errorCodeSeverityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </label>
      <label className="form-field"><span>Page reference <small>Optional</small></span><input value={values.page_reference} onChange={(event) => change('page_reference', event.target.value)} placeholder="e.g. 1234" /></label>
      <label className="form-field form-field-wide"><span>Description <small>Optional</small></span><textarea value={values.description} onChange={(event) => change('description', event.target.value)} rows="2" /></label>
      <label className="form-field form-field-wide"><span>Operator solution <small>Optional</small></span><textarea value={values.operator_solution} onChange={(event) => change('operator_solution', event.target.value)} rows="2" /></label>
      <label className="form-field form-field-wide"><span>Technician solution <small>Optional - becomes a solution step on publish</small></span><textarea value={values.technician_solution} onChange={(event) => change('technician_solution', event.target.value)} rows="2" /></label>
      {formError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
      <div className="dialog-actions">
        <button className="secondary-button" type="button" onClick={onCancel} disabled={isSaving}>Cancel</button>
        <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={15} /> : null} {submitLabel}</button>
      </div>
    </form>
  )
}
