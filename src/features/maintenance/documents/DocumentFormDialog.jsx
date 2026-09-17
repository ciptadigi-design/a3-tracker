import { useMemo, useState } from 'react'
import { AlertCircle, LoaderCircle, PencilLine, Plus, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { useMachineCatalog } from '../../machines/useMachineCatalog.js'
import { createMaintenanceDocument, updateMaintenanceDocument } from '../../../services/maintenance.js'
import { documentStatusLabels, documentTypeLabels, mapMaintenanceError } from '../maintenanceUtils.js'

function FieldError({ message }) {
  return message ? <small className="field-error"><AlertCircle size={13} />{message}</small> : null
}

function emptyForm(doc) {
  return {
    title: doc?.title ?? '',
    document_type: doc?.document_type ?? 'SERVICE_MANUAL',
    description: doc?.description ?? '',
    version: doc?.version ?? '',
    manufacturer_id: doc?.manufacturer_id ?? '',
    machine_model_id: doc?.machine_model_id ?? '',
    file_path: doc?.file_path ?? '',
    file_name: doc?.file_name ?? '',
    mime_type: doc?.mime_type ?? 'application/pdf',
    status: doc?.status ?? 'DRAFT',
  }
}

export function DocumentFormDialog({ document, accountId, onClose, onSaved }) {
  const isEdit = Boolean(document?.id)
  const [values, setValues] = useState(() => emptyForm(document))
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const catalog = useMachineCatalog(accountId, true)
  const modelsForManufacturer = useMemo(
    () => (values.manufacturer_id ? catalog.models.filter((model) => model.manufacturer_id === values.manufacturer_id) : catalog.models),
    [catalog.models, values.manufacturer_id],
  )

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
      const payload = {
        ...values,
        manufacturer_id: values.manufacturer_id || null,
        machine_model_id: values.machine_model_id || null,
        description: values.description.trim() || null,
        version: values.version.trim() || null,
        file_path: values.file_path.trim() || null,
        file_name: values.file_name.trim() || null,
        mime_type: values.file_path.trim() ? values.mime_type : null,
      }
      if (isEdit) await updateMaintenanceDocument(document.id, payload)
      else await createMaintenanceDocument({ ...payload, account_id: accountId })
      onSaved()
      onClose()
    } catch (error) {
      setFormError(mapMaintenanceError(error))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="document-dialog-title" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon">{isEdit ? <PencilLine size={22} /> : <Plus size={22} />}</span><div><span className="card-kicker">Document repository</span><h2 id="document-dialog-title">{isEdit ? 'Edit document' : 'Register document'}</h2><p>Reference metadata only - a title, an external file reference, and machine linkage. No file is uploaded here.</p></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close"><X size={19} /></button>
      </header>
      <form className="machine-form" onSubmit={handleSubmit} noValidate>
        <div className="machine-form-body">
          <div className="form-grid">
            <label className="form-field form-field-wide"><span>Title <b className="required-mark">*</b></span><input value={values.title} onChange={(event) => change('title', event.target.value)} placeholder="e.g. Konica Minolta C1070 Service Manual" aria-invalid={Boolean(errors.title)} /><FieldError message={errors.title} /></label>
            <label className="form-field"><span>Document type</span><select value={values.document_type} onChange={(event) => change('document_type', event.target.value)}>{Object.entries(documentTypeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className="form-field"><span>Status</span><select value={values.status} onChange={(event) => change('status', event.target.value)}>{Object.entries(documentStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className="form-field"><span>Version <small>Optional</small></span><input value={values.version} onChange={(event) => change('version', event.target.value)} placeholder="e.g. Rev 3" /></label>
            <label className="form-field"><span>Manufacturer <small>Optional</small></span>
              <select value={values.manufacturer_id} onChange={(event) => { change('manufacturer_id', event.target.value); change('machine_model_id', '') }} disabled={catalog.isLoading}>
                <option value="">Any manufacturer</option>
                {catalog.manufacturers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </label>
            <label className="form-field"><span>Machine model <small>Optional</small></span>
              <select value={values.machine_model_id} onChange={(event) => change('machine_model_id', event.target.value)} disabled={catalog.isLoading}>
                <option value="">Any model</option>
                {modelsForManufacturer.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </label>
            <label className="form-field form-field-wide"><span>Description <small>Optional</small></span><textarea value={values.description} onChange={(event) => change('description', event.target.value)} rows="2" /></label>
            <label className="form-field form-field-wide"><span>File reference (path or URL) <small>Optional - PDF only, metadata reference, nothing is uploaded</small></span><input value={values.file_path} onChange={(event) => change('file_path', event.target.value)} placeholder="e.g. https://.../km-c1070-service-manual.pdf" /></label>
            <label className="form-field"><span>File name <small>Optional</small></span><input value={values.file_name} onChange={(event) => change('file_name', event.target.value)} placeholder="e.g. km-c1070-service-manual.pdf" /></label>
          </div>
          {formError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
        </div>
        <footer className="dialog-actions form-action-footer">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Cancel</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : (isEdit ? <PencilLine size={17} /> : <Plus size={17} />)} {isSaving ? 'Saving…' : isEdit ? 'Save changes' : 'Register document'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
