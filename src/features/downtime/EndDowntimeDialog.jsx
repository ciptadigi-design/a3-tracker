import { AlertTriangle, CheckCircle2, LoaderCircle, X } from 'lucide-react'
import { useState } from 'react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapDowntimeError } from './downtimeModel.js'

export function EndDowntimeDialog({ canBackdate, onClose, onEnd }) {
  const [resolutionNote, setResolutionNote] = useState('')
  const [backdate, setBackdate] = useState(false)
  const [actualEndAt, setActualEndAt] = useState('')
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)

  async function handleSubmit(submitEvent) {
    submitEvent.preventDefault()
    if (isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      await onEnd({
        resolution_note: resolutionNote.trim() || null,
        actual_end_at: backdate && actualEndAt ? actualEndAt.replace('T', ' ') + ':00' : null,
      })
      onClose()
    } catch (endError) {
      setError(mapDowntimeError(endError))
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="confirm-dialog glass-surface" labelledBy="end-downtime-title" onClose={onClose} busy={isSaving}>
      <form onSubmit={handleSubmit}>
        <header className="dialog-header"><span className="dialog-icon"><CheckCircle2 size={23} /></span><button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close"><X size={19} /></button></header>
        <h2 id="end-downtime-title">End Downtime</h2>
        <p>Mencatat mesin kembali beroperasi. Durasi dihitung otomatis dari waktu mulai.</p>

        <label className="form-field"><span>Resolution (opsional)</span><textarea value={resolutionNote} onChange={(event) => setResolutionNote(event.target.value)} rows={3} disabled={isSaving} /></label>

        {canBackdate && (
          <div className="form-field">
            <label><input type="checkbox" checked={backdate} onChange={(event) => setBackdate(event.target.checked)} disabled={isSaving} /> Catat waktu selesai historis (backdated)</label>
            {backdate && <input type="datetime-local" value={actualEndAt} onChange={(changeEvent) => setActualEndAt(changeEvent.target.value)} max={new Date().toISOString().slice(0, 16)} disabled={isSaving} required />}
          </div>
        )}

        {error && <div className="form-error" role="alert"><AlertTriangle size={16} />{error}</div>}
        <footer className="dialog-actions">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batal</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Menyelesaikan…' : 'End Downtime'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
