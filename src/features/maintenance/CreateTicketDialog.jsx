import { useMemo, useState } from 'react'
import { AlertCircle, ClipboardPlus, LoaderCircle, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useMachineErrorCodes } from './useMachineErrorCodes.js'
import { mapMaintenanceError, ticketPriorityLabels, ticketTypeLabels } from './maintenanceUtils.js'

function FieldError({ message }) {
  return message ? <small className="field-error"><AlertCircle size={13} />{message}</small> : null
}

export function CreateTicketDialog({ machines, defaultMachineId, onClose, onSave }) {
  const [machineId, setMachineId] = useState(defaultMachineId || '')
  const [errorCodeId, setErrorCodeId] = useState('')
  const [type, setType] = useState('breakdown')
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [priority, setPriority] = useState('normal')
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  const selectedMachine = useMemo(() => machines.find((machine) => machine.id === machineId), [machines, machineId])
  const errorCodesState = useMachineErrorCodes(selectedMachine?.machine_model_id)

  function validate() {
    const next = {}
    if (!machineId) next.machineId = 'Select a machine.'
    if (!title.trim()) next.title = 'Title is required.'
    return next
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    const nextErrors = validate()
    setErrors(nextErrors)
    if (Object.keys(nextErrors).length) {
      setFormError('Check the highlighted fields.')
      return
    }
    setIsSaving(true)
    setFormError(null)
    try {
      await onSave({
        machine_id: machineId,
        error_code_id: errorCodeId || null,
        type,
        title: title.trim(),
        description: description.trim() || null,
        priority,
        client_request_id: crypto.randomUUID(),
      })
      onClose()
    } catch (error) {
      setFormError(mapMaintenanceError(error))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="maintenance-ticket-dialog-title" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><ClipboardPlus size={22} /></span><div><span className="card-kicker">Maintenance</span><h2 id="maintenance-ticket-dialog-title">Open a maintenance ticket</h2><p>Log a machine problem so it can be tracked to resolution.</p></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close"><X size={19} /></button>
      </header>
      <form className="machine-form" onSubmit={handleSubmit} noValidate>
        <div className="machine-form-body">
          <div className="form-grid">
            <label className="form-field"><span>Machine <b className="required-mark">*</b></span>
              <select value={machineId} onChange={(event) => { setMachineId(event.target.value); setErrorCodeId('') }} aria-invalid={Boolean(errors.machineId)}>
                <option value="">Select a machine</option>
                {machines.filter((machine) => machine.is_active).map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}
              </select>
              <FieldError message={errors.machineId} />
            </label>
            <label className="form-field"><span>Type</span>
              <select value={type} onChange={(event) => setType(event.target.value)}>{Object.entries(ticketTypeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
            </label>
            <label className="form-field"><span>Priority</span>
              <select value={priority} onChange={(event) => setPriority(event.target.value)}>{Object.entries(ticketPriorityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
            </label>
            <label className="form-field"><span>Known error code <small>Optional</small></span>
              <select value={errorCodeId} onChange={(event) => setErrorCodeId(event.target.value)} disabled={!selectedMachine || errorCodesState.isLoading}>
                <option value="">No specific code</option>
                {errorCodesState.errorCodes.map((code) => <option key={code.id} value={code.id}>{code.code} · {code.title}</option>)}
              </select>
            </label>
            <label className="form-field form-field-wide"><span>Title <b className="required-mark">*</b></span>
              <input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="e.g. Fuser overheating" aria-invalid={Boolean(errors.title)} />
              <FieldError message={errors.title} />
            </label>
            <label className="form-field form-field-wide"><span>Description <small>Optional</small></span>
              <textarea value={description} onChange={(event) => setDescription(event.target.value)} rows="3" placeholder="Symptoms, when it started, anything the operator noticed" />
            </label>
          </div>
          {formError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
        </div>
        <footer className="dialog-actions form-action-footer">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Cancel</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : <ClipboardPlus size={17} />}{isSaving ? 'Saving…' : 'Open ticket'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
