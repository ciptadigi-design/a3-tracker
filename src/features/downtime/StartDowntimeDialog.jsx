import { AlertTriangle, LoaderCircle, PowerOff, X } from 'lucide-react'
import { useState } from 'react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { downtimeCauses, mapDowntimeError, newClientRequestId } from './downtimeModel.js'

export function StartDowntimeDialog({ machine, canBackdate, onClose, onStart }) {
  const [cause, setCause] = useState('error_code')
  const [errorCodeText, setErrorCodeText] = useState('')
  const [description, setDescription] = useState('')
  const [backdate, setBackdate] = useState(false)
  const [actualStartAt, setActualStartAt] = useState('')
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [clientRequestId] = useState(newClientRequestId)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      await onStart({
        cause,
        error_code_text: errorCodeText.trim() || null,
        description: description.trim() || null,
        actual_start_at: backdate && actualStartAt ? actualStartAt.replace('T', ' ') + ':00' : null,
        client_request_id: clientRequestId,
      })
      onClose()
    } catch (startError) {
      setError(mapDowntimeError(startError))
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="confirm-dialog glass-surface" labelledBy="start-downtime-title" onClose={onClose} busy={isSaving}>
      <form onSubmit={handleSubmit}>
        <header className="dialog-header"><span className="danger-dialog-icon"><PowerOff size={23} /></span><button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close"><X size={19} /></button></header>
        <h2 id="start-downtime-title">Laporkan Mesin Berhenti — {machine.display_name}</h2>
        <p>Catat mesin ini sebagai tidak beroperasi. Maintenance Note dapat ditambahkan setelahnya tanpa menutup downtime.</p>

        <label className="form-field"><span>Cause</span><select value={cause} onChange={(event) => setCause(event.target.value)} disabled={isSaving} required>{downtimeCauses.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
        <label className="form-field"><span>Error code <small>Optional</small></span><input value={errorCodeText} onChange={(event) => setErrorCodeText(event.target.value)} placeholder="Contoh: C-3913" maxLength={16} disabled={isSaving} /></label>
        <label className="form-field"><span>Description <small>Optional</small></span><textarea value={description} onChange={(event) => setDescription(event.target.value)} rows={3} disabled={isSaving} /></label>

        {canBackdate && (
          <div className="form-field">
            <label><input type="checkbox" checked={backdate} onChange={(event) => setBackdate(event.target.checked)} disabled={isSaving} /> Catat waktu mulai historis (backdated)</label>
            {backdate && <input type="datetime-local" value={actualStartAt} onChange={(event) => setActualStartAt(event.target.value)} max={new Date().toISOString().slice(0, 16)} disabled={isSaving} required />}
          </div>
        )}

        {error && <div className="form-error" role="alert"><AlertTriangle size={16} />{error}</div>}
        <footer className="dialog-actions">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batalkan</button>
          <button className="danger-button" type="submit" disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Menyimpan…' : 'Laporkan Berhenti'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
