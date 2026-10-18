import { AlertTriangle, ClipboardPlus, LoaderCircle, X } from 'lucide-react'
import { useState } from 'react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapDowntimeError, newClientRequestId } from './downtimeModel.js'

/** Adds a maintenance note to an active downtime event; never closes the event. */
export function AddNoteDialog({ machineName, onClose, onSave }) {
  const [note, setNote] = useState('')
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [clientRequestId] = useState(newClientRequestId)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    if (!note.trim()) { setError('Tulis catatan terlebih dahulu.'); return }
    setIsSaving(true)
    setError(null)
    try {
      await onSave({ note: note.trim(), client_request_id: clientRequestId })
      onClose()
    } catch (saveError) {
      setError(mapDowntimeError(saveError))
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="confirm-dialog glass-surface" labelledBy="add-note-title" onClose={onClose} busy={isSaving}>
      <form onSubmit={handleSubmit}>
        <header className="dialog-header"><span className="dialog-icon"><ClipboardPlus size={23} /></span><button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup"><X size={19} /></button></header>
        <h2 id="add-note-title">Tambah Catatan — {machineName}</h2>
        <p>Catatan penanganan disimpan pada downtime yang sedang berjalan. Mesin tetap berstatus berhenti sampai Anda memilih Mesin Sudah Normal.</p>
        <label className="form-field"><span>Catatan</span><textarea value={note} onChange={(event) => setNote(event.target.value)} rows={4} disabled={isSaving} autoFocus required /></label>
        {error && <div className="form-error" role="alert"><AlertTriangle size={16} />{error}</div>}
        <footer className="dialog-actions">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batalkan</button>
          <button className="primary-button" type="submit" disabled={isSaving || !note.trim()}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Menyimpan…' : 'Simpan Catatan'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
