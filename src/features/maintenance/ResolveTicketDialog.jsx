import { useState } from 'react'
import { AlertCircle, CheckCircle2, LoaderCircle, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapMaintenanceError, resolutionOptionalTypes, testResultLabels, validateResolution } from './maintenanceUtils.js'

// Selesaikan Ticket: resolution is saved atomically with the DONE transition by the backend.
export function ResolveTicketDialog({ ticket, downtimeStillActive, onClose, onSave }) {
  const optional = resolutionOptionalTypes.includes(ticket.type)
  const [form, setForm] = useState({ resolution_cause: '', resolution_action: '', resolution_test_result: '', resolution_notes: '' })
  const [fieldErrors, setFieldErrors] = useState({})
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }))
  const untouched = !form.resolution_cause.trim() && !form.resolution_action.trim() && !form.resolution_test_result

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    const errors = optional && untouched ? {} : validateResolution(ticket.type, form)
    setFieldErrors(errors)
    if (Object.keys(errors).length) return
    setIsSaving(true)
    setError(null)
    try {
      await onSave(optional && untouched ? {} : { ...form, resolution_notes: form.resolution_notes.trim() || null })
      onClose()
    } catch (saveError) {
      setFieldErrors(Object.fromEntries(Object.entries(saveError?.errors || {}).map(([key, messages]) => [key, messages[0]])))
      setError(mapMaintenanceError(saveError))
    } finally {
      setIsSaving(false)
    }
  }

  const fieldError = (key) => fieldErrors[key] && <small className="field-error" role="alert">{fieldErrors[key]}</small>

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="maintenance-resolve-dialog-title" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><CheckCircle2 size={22} /></span><div><span className="card-kicker">Penyelesaian</span><h2 id="maintenance-resolve-dialog-title">Selesaikan Ticket</h2><p>{optional ? 'Ringkasan penyelesaian bersifat opsional untuk tiket ini.' : 'Lengkapi ringkasan penyelesaian sebelum menutup tiket.'}</p></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup"><X size={19} /></button>
      </header>
      <form className="machine-form" onSubmit={handleSubmit} noValidate>
        <div className="machine-form-body">
          {downtimeStillActive && <div className="form-warning" role="status"><AlertCircle size={16} /><span>Downtime terkait masih berjalan. Menyelesaikan tiket tidak menutup downtime; akhiri downtime dari Kondisi Mesin.</span></div>}
          <div className="form-grid">
            <label className="form-field form-field-wide"><span>Penyebab Masalah {!optional && <b className="required-mark">*</b>}</span><textarea value={form.resolution_cause} onChange={set('resolution_cause')} rows="2" aria-invalid={Boolean(fieldErrors.resolution_cause)} />{fieldError('resolution_cause')}</label>
            <label className="form-field form-field-wide"><span>Tindakan Penyelesaian {!optional && <b className="required-mark">*</b>}</span><textarea value={form.resolution_action} onChange={set('resolution_action')} rows="2" aria-invalid={Boolean(fieldErrors.resolution_action)} />{fieldError('resolution_action')}</label>
            <label className="form-field"><span>Hasil Pengujian {!optional && <b className="required-mark">*</b>}</span>
              <select aria-label="Hasil Pengujian" value={form.resolution_test_result} onChange={set('resolution_test_result')} aria-invalid={Boolean(fieldErrors.resolution_test_result)}>
                <option value="">Pilih hasil</option>
                {Object.entries(testResultLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>{fieldError('resolution_test_result')}
            </label>
            <label className="form-field form-field-wide"><span>Catatan Tambahan</span><textarea value={form.resolution_notes} onChange={set('resolution_notes')} rows="2" /></label>
          </div>
          {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
        </div>
        <footer className="dialog-actions form-action-footer">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batal</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : <CheckCircle2 size={17} />}{isSaving ? 'Menyimpan…' : 'Selesaikan Ticket'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
