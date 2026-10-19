import { useState } from 'react'
import { LoaderCircle, UserRound } from 'lucide-react'
import { mapMaintenanceError } from './maintenanceUtils.js'

// Penanggung Jawab: internal PIC (operational_people, same directory as Input Click) plus
// optional external technician/vendor free text. External people never get an account.
export function ResponsibleSection({ ticket, people, peopleLoading, peopleError, canEdit, onSave }) {
  const [form, setForm] = useState({ internal_pic_person_id: ticket.internal_pic_person_id || '', external_technician_name: ticket.external_technician_name || '', external_vendor_name: ticket.external_vendor_name || '' })
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }))
  // A PIC who later became inactive is kept visible from the stored name snapshot.
  const picMissing = ticket.internal_pic_person_id && !people.some((person) => person.id === ticket.internal_pic_person_id)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      await onSave({ internal_pic_person_id: form.internal_pic_person_id || null, external_technician_name: form.external_technician_name.trim() || null, external_vendor_name: form.external_vendor_name.trim() || null })
    } catch (saveError) {
      setError(mapMaintenanceError(saveError))
    } finally {
      setIsSaving(false)
    }
  }

  if (!canEdit) {
    return (
      <section className="machine-list-card glass-surface" aria-label="Penanggung Jawab">
        <div className="list-toolbar"><div><span className="card-kicker">Penanggung Jawab</span><h2>{ticket.internal_pic_name || 'Belum ditentukan'}</h2></div></div>
        {(ticket.external_technician_name || ticket.external_vendor_name) && <p>Teknisi eksternal: {[ticket.external_technician_name, ticket.external_vendor_name && `(${ticket.external_vendor_name})`].filter(Boolean).join(' ')}</p>}
      </section>
    )
  }

  return (
    <section className="machine-list-card glass-surface" aria-label="Penanggung Jawab">
      <div className="list-toolbar"><div><span className="card-kicker">Penanggung Jawab</span><h2>PIC internal &amp; teknisi eksternal</h2></div></div>
      <form className="machine-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <label className="form-field"><span><UserRound size={14} /> PIC Internal</span>
            <select aria-label="PIC Internal" value={form.internal_pic_person_id} onChange={set('internal_pic_person_id')} disabled={peopleLoading || Boolean(peopleError)}>
              <option value="">{peopleLoading ? 'Memuat operator…' : 'Tanpa PIC internal'}</option>
              {picMissing && <option value={ticket.internal_pic_person_id}>{ticket.internal_pic_name} (tidak aktif)</option>}
              {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
            </select>
            {!peopleLoading && !peopleError && people.length === 0 && <small>Belum ada operator yang ditugaskan ke cabang ini.</small>}
            {peopleError && <small>Daftar operator tidak dapat dimuat.</small>}
          </label>
          <label className="form-field"><span>Teknisi Eksternal</span><input value={form.external_technician_name} onChange={set('external_technician_name')} maxLength={120} placeholder="Opsional, mis. Budi" /></label>
          <label className="form-field"><span>Vendor / Perusahaan</span><input value={form.external_vendor_name} onChange={set('external_vendor_name')} maxLength={120} placeholder="Opsional, mis. Konica Minolta" /></label>
        </div>
        {error && <div className="form-error" role="alert"><span>{error}</span></div>}
        <div className="dialog-actions"><button className="secondary-button" type="submit" disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={16} />}{isSaving ? 'Menyimpan…' : 'Simpan Penanggung Jawab'}</button></div>
      </form>
    </section>
  )
}
