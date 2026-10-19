import { useState } from 'react'
import { AlertCircle, LoaderCircle, PencilLine, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapMaintenanceError } from './maintenanceUtils.js'

// Tambah Aktivitas: one append-only entry in the ticket's activity log. Leaving every
// performer field empty makes the backend inherit the ticket's current responsible people.
export function RecordActionDialog({ people, peopleLoading, peopleError, onClose, onSave }) {
  const [form, setForm] = useState({ action_description: '', findings: '', operator_person_id: '', external_technician_name: '', vendor_name: '' })
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }))

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    if (!form.action_description.trim()) {
      setError('Uraian aktivitas wajib diisi.')
      return
    }
    setIsSaving(true)
    setError(null)
    try {
      await onSave({
        action_description: form.action_description.trim(),
        findings: form.findings.trim() || null,
        operator_person_id: form.operator_person_id || null,
        external_technician_name: form.external_technician_name.trim() || null,
        vendor_name: form.vendor_name.trim() || null,
      })
      onClose()
    } catch (saveError) {
      setError(mapMaintenanceError(saveError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="maintenance-action-dialog-title" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><PencilLine size={22} /></span><div><span className="card-kicker">Maintenance</span><h2 id="maintenance-action-dialog-title">Tambah Aktivitas</h2><p>Catatan ini tidak dapat diubah setelah disimpan.</p></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup"><X size={19} /></button>
      </header>
      <form className="machine-form" onSubmit={handleSubmit} noValidate>
        <div className="machine-form-body">
          <div className="form-grid">
            <label className="form-field form-field-wide"><span>Aktivitas <b className="required-mark">*</b></span>
              <textarea value={form.action_description} onChange={set('action_description')} rows="3" placeholder="mis. Pembersihan area transfer" />
            </label>
            <label className="form-field form-field-wide"><span>Temuan / hasil</span>
              <textarea value={form.findings} onChange={set('findings')} rows="2" placeholder="mis. Hasil masih kotor" />
            </label>
            <label className="form-field form-field-wide"><span>Dikerjakan oleh (operator)</span>
              <select aria-label="Operator" value={form.operator_person_id} onChange={set('operator_person_id')} disabled={peopleLoading || Boolean(peopleError)}>
                <option value="">{peopleLoading ? 'Memuat operator…' : 'Ikuti penanggung jawab tiket / tanpa operator'}</option>
                {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
              </select>
              {!peopleLoading && !peopleError && people.length === 0 && <small>Belum ada operator yang ditugaskan ke cabang ini.</small>}
              {peopleError && <small>Daftar operator tidak dapat dimuat.</small>}
            </label>
            <label className="form-field"><span>Teknisi eksternal</span><input value={form.external_technician_name} onChange={set('external_technician_name')} maxLength={120} placeholder="mis. Budi" /></label>
            <label className="form-field"><span>Vendor / perusahaan</span><input value={form.vendor_name} onChange={set('vendor_name')} maxLength={120} placeholder="mis. Konica Minolta" /></label>
          </div>
          <small>Kosongkan semua kolom pelaksana untuk memakai penanggung jawab tiket.</small>
          {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
        </div>
        <footer className="dialog-actions form-action-footer">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batal</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={17} /> : <PencilLine size={17} />}{isSaving ? 'Menyimpan…' : 'Simpan Aktivitas'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
