import { CircleDollarSign, Plus, RotateCcw } from 'lucide-react'
import { categoryLabels } from './operatingCostModel.js'

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const date = (value) => value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '—'

export function OperatingCostsPanel({ costs, canManage, onAdd, onVoid }) {
  return <section className="machine-cost-panel glass-surface operating-cost-panel"><header><div><span className="card-kicker">Auditable evidence</span><h2>Operating Costs</h2><p>Non-inventory costs directly attributable to this machine. Posted records are immutable.</p></div>{canManage && <button className="primary-button" type="button" onClick={onAdd}><Plus size={16} />Add Operating Cost</button>}</header>
    {!costs.length ? <div className="machine-cost-empty"><CircleDollarSign size={25} /><strong>No operating-cost records for this machine.</strong><span>Component consumption remains available from M2.5A. No service, electricity, labor, or other cost is fabricated.</span></div> : <div className="operating-cost-list">{costs.map((cost) => <article key={cost.id} className={cost.status === 'voided' ? 'is-voided' : ''}><div><strong>{categoryLabels[cost.category]}</strong><span>{cost.description}</span></div><div><span>{cost.allocation_method === 'one_time' ? date(cost.effective_at) : `${cost.period_start} → ${cost.period_end}`}</span><small>{cost.allocation_method === 'one_time' ? 'One-time' : 'Daily proration v1'} · {cost.source_type}</small></div><div><strong>{idr.format(Number(cost.amount))}</strong><span>{cost.external_reference || 'No external reference'}</span></div><div><span className={`incident-status-pill ${cost.status === 'posted' ? 'resolved' : 'voided'}`}>{cost.status}</span>{canManage && cost.status === 'posted' && <button className="secondary-button" type="button" onClick={() => onVoid(cost)}><RotateCcw size={14} />Void</button>}</div></article>)}</div>}
  </section>
}
