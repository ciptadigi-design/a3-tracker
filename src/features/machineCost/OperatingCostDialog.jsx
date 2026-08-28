import { useMemo, useState } from 'react'
import { CircleDollarSign, LoaderCircle, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'
import { operatingCostCategories, operatingCostValidation, validOperatingCostDraft } from './operatingCostModel.js'

function localDateTime() { const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000); return now.toISOString().slice(0, 16) }
function localDate() { return localDateTime().slice(0, 10) }

export function OperatingCostDialog({ account, branch, machine, people, onClose, onSave }) {
  const { user } = useAuth()
  const initialValue = useMemo(() => ({ category: '', amount: '', allocationMethod: 'one_time', effectiveAt: localDateTime(), periodStart: localDate(), periodEnd: localDate(), operationalPersonId: '', externalReference: '', description: '', notes: '', clientRequestId: crypto.randomUUID() }), [])
  const { value, updateDraft, clearDraft, resetDraft, hasDraft, wasRestored } = usePersistentDraft({
    draftKey: createDraftKey({ userId: user.id, accountId: account.id, branchId: branch.id, feature: 'machine-operating-cost', entityId: machine.id }),
    initialValue, validate: validOperatingCostDraft, metadata: { machineId: machine.id },
  })
  const [error, setError] = useState(null); const [saving, setSaving] = useState(false)
  const change = (field, next) => { updateDraft((current) => ({ ...current, [field]: next })); setError(null) }
  async function submit(event) {
    event.preventDefault(); const validation = operatingCostValidation(value); if (validation) return setError(validation)
    setSaving(true); setError(null)
    try { await onSave({ ...value, amount: value.amount, description: value.description.trim(), externalReference: value.externalReference.trim(), notes: value.notes.trim() }); clearDraft(); onClose() }
    catch (saveError) { setError(saveError.message) } finally { setSaving(false) }
  }
  const activePeople = people.filter((person) => person.is_active)
  return <BlockingDialog className="machine-dialog operating-cost-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="operating-cost-title" describedBy="operating-cost-description" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><CircleDollarSign size={22} /></span><div><span className="card-kicker">Machine Economics</span><h2 id="operating-cost-title">Add Operating Cost</h2><p id="operating-cost-description">{machine.display_name} · {machine.machine_code}. Record non-inventory operating evidence only.</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close operating cost form"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body">
      {wasRestored && <div className="draft-restored-status" role="status">Unsaved operating-cost draft restored</div>}
      <div className="machine-cost-double-count-warning"><strong>Avoid double counting</strong><span>Parts consumed through Inventory / Replacement already belong to Component Consumption. Enter only non-inventory service, labor, or other operating portions here.</span></div>
      <div className="form-grid operating-cost-form-grid">
        <label className="form-field"><span>Category *</span><select data-dialog-initial-focus value={value.category} onChange={(event) => change('category', event.target.value)}><option value="">Choose category</option>{operatingCostCategories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
        <label className="form-field"><span>Amount (IDR) *</span><input inputMode="decimal" value={value.amount} onChange={(event) => /^\d*(\.\d{0,2})?$/.test(event.target.value) && change('amount', event.target.value)} placeholder="500000" /></label>
        <label className="form-field"><span>Cost Type *</span><select value={value.allocationMethod} onChange={(event) => change('allocationMethod', event.target.value)}><option value="one_time">One-time</option><option value="daily_proration_v1">Period · daily proration</option></select></label>
        {value.allocationMethod === 'one_time' ? <label className="form-field"><span>Effective Date & Time *</span><input type="datetime-local" value={value.effectiveAt} onChange={(event) => change('effectiveAt', event.target.value)} /></label> : <><label className="form-field"><span>Period Start *</span><input type="date" value={value.periodStart} onChange={(event) => change('periodStart', event.target.value)} /></label><label className="form-field"><span>Period End *</span><input type="date" min={value.periodStart} value={value.periodEnd} onChange={(event) => change('periodEnd', event.target.value)} /></label></>}
        <label className="form-field"><span>PIC <small>Optional</small></span><select value={value.operationalPersonId} onChange={(event) => change('operationalPersonId', event.target.value)}><option value="">No PIC attribution</option>{activePeople.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label>
        <label className="form-field"><span>External Reference <small>Optional</small></span><input value={value.externalReference} onChange={(event) => change('externalReference', event.target.value)} placeholder="Invoice / contract reference" /></label>
        <label className="form-field form-field-wide"><span>Description *</span><input value={value.description} onChange={(event) => change('description', event.target.value)} placeholder="What non-inventory operating cost occurred?" /></label>
        <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" value={value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      </div>{error && <div className="form-error" role="alert">{error}</div>}
    </div><footer className="dialog-actions"><button className="draft-reset-button" type="button" onClick={() => resetDraft({ ...initialValue, clientRequestId: crypto.randomUUID() })} disabled={!hasDraft || saving}><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" disabled={saving}>{saving && <LoaderCircle className="spin" size={16} />}Save Posted Cost</button></footer></form>
  </BlockingDialog>
}
