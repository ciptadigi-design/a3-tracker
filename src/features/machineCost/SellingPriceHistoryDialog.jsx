import { useState } from 'react'
import { History, LoaderCircle, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 4 })
const dateTime = (value, timezone) => value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(new Date(value)) : 'Current'

export function SellingPriceHistoryDialog({ machine, timezone, prices, canManage, onClose, onVoid }) {
  const [voidTarget, setVoidTarget] = useState(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  async function submitVoid(event) {
    event.preventDefault()
    if (!reason.trim()) return setError('Correction / void reason is required.')
    setSaving(true); setError(null)
    try { await onVoid(voidTarget, reason.trim()); setVoidTarget(null); setReason('') }
    catch (saveError) { setError(saveError.message) } finally { setSaving(false) }
  }
  return <BlockingDialog className="machine-dialog selling-price-history-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="selling-price-history-title" describedBy="selling-price-history-description" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><History size={22} /></span><div><span className="card-kicker">Audit Evidence</span><h2 id="selling-price-history-title">Selling Price History</h2><p id="selling-price-history-description">{machine.display_name} · effective intervals in {timezone}. Interval end is exclusive.</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close selling price history"><X size={19} /></button></header>
    <div className="machine-form-body selling-price-history-body">{prices.length ? <div className="selling-price-history-list">{prices.map((price) => <article key={price.id} className={price.status === 'voided' ? 'is-voided' : ''}>
      <div><strong>{idr.format(Number(price.price_per_click))} / click</strong><span>{dateTime(price.effective_from, timezone)} → {price.status === 'posted' ? dateTime(price.effective_to, timezone) : 'Voided'}</span></div>
      <div><span className={`selling-price-status status-${price.status}`}>{price.status === 'posted' ? 'Active evidence' : 'Voided'}</span><small>Created by {price.created_by_name_snapshot}</small></div>
      <div><span>{price.notes || 'No notes'}</span>{price.status === 'voided' && <small>Correction: {price.void_reason} · {price.voided_by_name_snapshot}</small>}</div>
      {canManage && price.status === 'posted' && <button className="secondary-button" type="button" onClick={() => { setVoidTarget(price); setError(null) }}>Correct / Void</button>}
    </article>)}</div> : <div className="machine-cost-empty compact"><History size={22} /><strong>No selling-price history</strong><span>Configure the first price from the Machine Cost summary.</span></div>}
    {voidTarget && <form className="selling-price-void-form" onSubmit={submitVoid}><label className="form-field"><span>Correction / Void Reason *</span><textarea data-dialog-initial-focus rows="2" maxLength="500" value={reason} onChange={(event) => { setReason(event.target.value); setError(null) }} /></label>{error && <div className="form-error" role="alert">{error}</div>}<div><button className="secondary-button" type="button" onClick={() => { setVoidTarget(null); setReason(''); setError(null) }} disabled={saving}>Cancel</button><button className="danger-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Void Evidence</button></div></form>}
    </div><footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Close</button></footer>
  </BlockingDialog>
}
