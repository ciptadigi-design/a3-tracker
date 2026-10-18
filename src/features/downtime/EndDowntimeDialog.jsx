import { AlertTriangle, CheckCircle2, LoaderCircle, X } from 'lucide-react'
import { useState } from 'react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapDowntimeError } from './downtimeModel.js'

function toLocalInputValue(date) {
  const offset = date.getTimezoneOffset()
  return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16)
}

export function EndDowntimeDialog({ startAt, canBackdate, onClose, onEnd }) {
  const [resolutionNote, setResolutionNote] = useState('')
  const [actualEndAt, setActualEndAt] = useState(() => toLocalInputValue(new Date()))
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)

  async function handleSubmit(submitEvent) {
    submitEvent.preventDefault()
    if (isSaving) return
    setError(null)

    const start = startAt ? new Date(startAt).getTime() : null
    const end = actualEndAt ? new Date(actualEndAt.replace('T', ' ') + ':00').getTime() : null
    if (canBackdate && start != null && end != null && !Number.isNaN(start) && !Number.isNaN(end) && end < start) {
      setError('Waktu selesai tidak boleh lebih awal dari waktu mulai.')
      return
    }

    setIsSaving(true)
    try {
      await onEnd({
        resolution_note: resolutionNote.trim() || null,
        actual_end_at: canBackdate && actualEndAt ? actualEndAt.replace('T', ' ') + ':00' : null,
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
        <h2 id="end-downtime-title">Mesin Sudah Normal</h2>
        <p>Catat kapan mesin kembali siap digunakan.</p>

        <label className="form-field">
          <span>Waktu selesai <small>Default: sekarang</small></span>
          <input type="datetime-local" value={actualEndAt} onChange={(event) => setActualEndAt(event.target.value)} max={toLocalInputValue(new Date())} disabled={isSaving || !canBackdate} required />
        </label>
        <label className="form-field"><span>Catatan penyelesaian <small>Optional</small></span><textarea value={resolutionNote} onChange={(event) => setResolutionNote(event.target.value)} rows={3} disabled={isSaving} /></label>

        {error && <div className="form-error" role="alert"><AlertTriangle size={16} />{error}</div>}
        <footer className="dialog-actions">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batalkan</button>
          <button className="primary-button" type="submit" disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Menyelesaikan…' : 'Simpan & Selesaikan'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
