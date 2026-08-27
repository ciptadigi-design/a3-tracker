import { useState } from 'react'
import { AlertCircle, LoaderCircle, ShieldX, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { mapIncidentError } from './incidentUtils.js'

export function VoidIncidentDialog({ incident, onClose, onConfirm }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    if (!reason.trim()) {
      setError('Alasan void wajib diisi.')
      return
    }
    setIsSaving(true)
    setError(null)
    try {
      await onConfirm(reason)
      onClose()
    } catch (submitError) {
      setError(mapIncidentError(submitError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="confirm-dialog glass-surface" labelledBy="void-incident-title" onClose={onClose} busy={isSaving}>
        <header className="dialog-header"><span className="danger-dialog-icon"><ShieldX size={22} /></span><button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Tutup dialog void"><X size={18} /></button></header>
        <h2 id="void-incident-title">Void log error?</h2>
        <p>Record tidak akan dihapus. Status, pelaku, waktu, dan alasan void tetap tersimpan untuk audit.</p>
        <div className="retire-summary"><span>Incident</span><strong>{incident.customer_name_snapshot || incident.product_name_snapshot || incident.description}</strong></div>
        <form className="incident-void-form" onSubmit={handleSubmit}><label className="form-field"><span>Alasan void <b className="required-mark">*</b></span><textarea value={reason} onChange={(event) => setReason(event.target.value)} rows="3" placeholder="Jelaskan mengapa record ini harus di-void" /></label>{error && <div className="form-error" role="alert"><AlertCircle size={15} /><span>{error}</span></div>}<div className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Batal</button><button className="danger-button" type="submit" disabled={isSaving}>{isSaving && <LoaderCircle className="spin" size={16} />}Void incident</button></div></form>
    </BlockingDialog>
  )
}
