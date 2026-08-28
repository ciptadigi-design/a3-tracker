import { useState } from 'react'
import { AlertCircle, LoaderCircle } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'

export function VoidOperatingCostDialog({ cost, onClose, onVoid }) {
  const [reason, setReason] = useState(''); const [error, setError] = useState(null); const [saving, setSaving] = useState(false)
  async function submit(event) { event.preventDefault(); if (!reason.trim()) return setError('Void reason is required.'); setSaving(true); try { await onVoid(reason.trim()); onClose() } catch (nextError) { setError(nextError.message) } finally { setSaving(false) } }
  return <BlockingDialog className="confirm-dialog glass-surface" labelledBy="void-cost-title" onClose={onClose} busy={saving}><div className="confirm-dialog-body"><span className="confirm-dialog-icon"><AlertCircle size={24} /></span><h2 id="void-cost-title">Void operating cost?</h2><p>{cost.description}. The posted fact remains in history and is excluded from economics after voiding.</p><form onSubmit={submit}><label className="form-field"><span>Void reason *</span><textarea data-dialog-initial-focus rows="3" value={reason} onChange={(event) => { setReason(event.target.value); setError(null) }} /></label>{error && <div className="form-error" role="alert">{error}</div>}<footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="danger-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={15} />}Void Cost</button></footer></form></div></BlockingDialog>
}
