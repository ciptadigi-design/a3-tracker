import { AlertTriangle, LoaderCircle, PowerOff, X } from 'lucide-react'
import { useState } from 'react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { downtimeCauses, mapDowntimeError, newClientRequestId } from './downtimeModel.js'

function toLocalInputValue(date) {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

/**
 * "Laporkan Mesin Berhenti" form. Machines that already have an active downtime
 * are listed but disabled (the API also answers 409 for a duplicate). The start
 * time defaults to now; only a role with downtime.backdate may change it.
 */
export function StartDowntimeDialog({ machines, machineId: initialMachineId = '', activeMachineIds = [], canBackdate, onClose, onStart }) {
  const [machineId, setMachineId] = useState(() => (initialMachineId && !activeMachineIds.includes(initialMachineId) ? initialMachineId : ''))
  const [cause, setCause] = useState('error_code')
  const [errorCodeText, setErrorCodeText] = useState('')
  const [description, setDescription] = useState('')
  const [now] = useState(() => toLocalInputValue(new Date()))
  const [startAt, setStartAt] = useState(now)
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const [clientRequestId] = useState(newClientRequestId)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    if (!machineId) { setError('Pilih mesin yang berhenti.'); return }
    if (activeMachineIds.includes(machineId)) { setError('Mesin ini sudah memiliki downtime aktif.'); return }
    setIsSaving(true)
    setError(null)
    try {
      await onStart(machineId, {
        cause,
        error_code_text: errorCodeText.trim() || null,
        description: description.trim() || null,
        actual_start_at: canBackdate && startAt && startAt !== now ? `${startAt.replace('T', ' ')}:00` : null,
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
        <header className="dialog-header"><span className="danger-dialog-icon"><PowerOff size={23} /></span><button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup"><X size={19} /></button></header>
        <h2 id="start-downtime-title">Laporkan Mesin Berhenti</h2>
        <p>Catat mesin sebagai tidak beroperasi. Catatan maintenance dapat ditambahkan setelahnya tanpa menutup downtime.</p>

        <label className="form-field"><span>Mesin</span>
          <select value={machineId} onChange={(event) => setMachineId(event.target.value)} disabled={isSaving} required>
            <option value="">Pilih mesin…</option>
            {machines.map((item) => <option key={item.id} value={item.id} disabled={activeMachineIds.includes(item.id)}>{item.display_name || item.machine_code}{item.machine_code && item.display_name ? ` · ${item.machine_code}` : ''}{activeMachineIds.includes(item.id) ? ' (sudah berhenti)' : ''}</option>)}
          </select>
        </label>
        <label className="form-field"><span>Waktu mulai <small>{canBackdate ? 'Default: sekarang' : 'Sekarang (dicatat server)'}</small></span><input type="datetime-local" value={startAt} onChange={(event) => setStartAt(event.target.value)} max={now} disabled={isSaving || !canBackdate} required /></label>
        <label className="form-field"><span>Penyebab</span><select value={cause} onChange={(event) => setCause(event.target.value)} disabled={isSaving} required>{downtimeCauses.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
        <label className="form-field"><span>Kode error <small>Opsional</small></span><input value={errorCodeText} onChange={(event) => setErrorCodeText(event.target.value)} placeholder="Contoh: C-3913" maxLength={16} disabled={isSaving} /></label>
        <label className="form-field"><span>Deskripsi <small>Opsional</small></span><textarea value={description} onChange={(event) => setDescription(event.target.value)} rows={3} disabled={isSaving} /></label>

        {error && <div className="form-error" role="alert"><AlertTriangle size={16} />{error}</div>}
        <footer className="dialog-actions">
          <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batalkan</button>
          <button className="danger-button" type="submit" disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={17} />}{isSaving ? 'Menyimpan…' : 'Laporkan Berhenti'}</button>
        </footer>
      </form>
    </BlockingDialog>
  )
}
