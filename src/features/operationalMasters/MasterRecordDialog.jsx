import { useState } from 'react'
import { AlertCircle, LoaderCircle, RotateCcw, Settings2, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { useAuth } from '../auth/useAuth.js'
import { createDraftKey } from '../drafts/draftKeys.js'
import { usePersistentDraft } from '../drafts/usePersistentDraft.js'

function initialValues(kind, record) {
  if (kind === 'person') return { name: record?.name ?? '', code: record?.code ?? '', notes: record?.notes ?? '', isActive: record?.is_active ?? true, branchIds: record?.operational_person_branches?.filter((item) => item.is_active).map((item) => item.branch_id) ?? [], clientRequestId: crypto.randomUUID() }
  if (kind === 'manufacturer') return { name: record?.name ?? '', code: record?.code ?? '', website: record?.website ?? '', notes: record?.notes ?? '', isActive: record?.is_active ?? true }
  return {
    name: record?.name ?? '', modelCode: record?.model_code ?? '', manufacturerId: record?.manufacturer_id ?? '',
    machineCategory: record?.machine_category ?? 'digital_a3', colorCapability: record?.color_capability ?? 'color',
    description: record?.description ?? '', notes: record?.notes ?? '', isActive: record?.is_active ?? true,
  }
}

function isDraft(value) {
  return value && typeof value.name === 'string' && typeof value.notes === 'string' && typeof value.isActive === 'boolean' && (!('branchIds' in value) || Array.isArray(value.branchIds))
}

const labels = { person: 'PIC / Operator', manufacturer: 'Manufacturer', model: 'Machine model' }

export function MasterRecordDialog({ kind, record, account, branches = [], manufacturers = [], onClose, onSave }) {
  const { user } = useAuth()
  const serverValues = initialValues(kind, record)
  const draftKey = createDraftKey({ userId: user.id, accountId: account.id, feature: `operational-master-${kind}`, entityId: record?.id ?? 'new' })
  const { value, updateDraft, hasDraft, resetDraft, clearDraft } = usePersistentDraft({ draftKey, initialValue: serverValues, validate: isDraft })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const title = `${record ? 'Edit' : 'Add'} ${labels[kind]}`

  function change(field, next) {
    updateDraft((current) => ({ ...current, [field]: next }))
    setError(null)
  }

  async function submit(event) {
    event.preventDefault()
    if (!value.name.trim()) return setError('Name is required.')
    if (kind === 'person' && value.isActive && value.branchIds.length === 0) return setError('Choose at least one Branch for an active PIC / Operator.')
    if (kind !== 'person' && !value.code?.trim() && !value.modelCode?.trim()) return setError('Code is required.')
    if (kind === 'model' && !value.manufacturerId) return setError('Choose a manufacturer.')
    setSaving(true)
    try {
      await onSave(value)
      clearDraft()
      onClose()
    } catch (saveError) {
      const duplicate = saveError.code === '23505' ? 'A record with that code or name already exists in this workspace.' : null
      setError(duplicate || saveError.message || 'The record could not be saved.')
    } finally {
      setSaving(false)
    }
  }

  return <BlockingDialog className="machine-dialog master-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="master-dialog-title" onClose={onClose} busy={saving}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><Settings2 size={22} /></span><div><span className="card-kicker">Operational master</span><h2 id="master-dialog-title">{title}</h2><p>Workspace records remain account-scoped and auditable.</p></div></div><button className="icon-button" type="button" onClick={onClose} disabled={saving} aria-label="Close form"><X size={19} /></button></header>
    <form className="machine-form" onSubmit={submit} noValidate><div className="machine-form-body"><div className="form-grid">
      <label className="form-field"><span>Name <b className="required-mark">*</b></span><input value={value.name} onChange={(event) => change('name', event.target.value)} autoComplete="off" /></label>
      {kind === 'person' && <label className="form-field"><span>Code <small>Optional</small></span><input value={value.code} onChange={(event) => change('code', event.target.value)} autoComplete="off" /></label>}
      {kind === 'person' && <fieldset className="form-field form-field-wide branch-checklist"><legend>Assigned Branches <b className="required-mark">*</b></legend>{branches.filter((branch) => branch.is_active).map((branch) => <label key={branch.id}><input type="checkbox" checked={value.branchIds.includes(branch.id)} onChange={() => change('branchIds', value.branchIds.includes(branch.id) ? value.branchIds.filter((id) => id !== branch.id) : [...value.branchIds, branch.id])} /><span>{branch.name}</span></label>)}</fieldset>}
      {kind === 'manufacturer' && <><label className="form-field"><span>Code <b className="required-mark">*</b></span><input value={value.code} onChange={(event) => change('code', event.target.value)} autoComplete="off" /></label><label className="form-field form-field-wide"><span>Website <small>Optional</small></span><input type="url" value={value.website} onChange={(event) => change('website', event.target.value)} autoComplete="url" /></label></>}
      {kind === 'model' && <><label className="form-field"><span>Model code <b className="required-mark">*</b></span><input value={value.modelCode} onChange={(event) => change('modelCode', event.target.value)} autoComplete="off" /></label><label className="form-field"><span>Manufacturer <b className="required-mark">*</b></span><select value={value.manufacturerId} onChange={(event) => change('manufacturerId', event.target.value)}><option value="">Choose manufacturer</option>{manufacturers.filter((item) => item.is_active).map((item) => <option key={item.id} value={item.id}>{item.name}{item.account_id ? ' · Workspace' : ' · Shared'}</option>)}</select></label><label className="form-field"><span>Category</span><select value={value.machineCategory} onChange={(event) => change('machineCategory', event.target.value)}><option value="digital_a3">Digital A3</option></select></label><label className="form-field"><span>Color capability</span><select value={value.colorCapability} onChange={(event) => change('colorCapability', event.target.value)}><option value="color">Color</option><option value="monochrome">Monochrome</option></select></label><label className="form-field form-field-wide"><span>Description <small>Optional</small></span><textarea rows="3" value={value.description} onChange={(event) => change('description', event.target.value)} /></label></>}
      <label className="form-field form-field-wide"><span>Notes <small>Optional</small></span><textarea rows="3" value={value.notes} onChange={(event) => change('notes', event.target.value)} /></label>
      {record && <label className="master-active-toggle"><input type="checkbox" checked={value.isActive} onChange={(event) => change('isActive', event.target.checked)} /><span><strong>Active</strong><small>Turn off to archive while preserving historical references.</small></span></label>}
    </div>{error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}</div>
    <footer className="dialog-actions form-action-footer"><button className="draft-reset-button" type="button" onClick={() => resetDraft(serverValues)} disabled={!hasDraft || saving} aria-label="Reset draft" title="Reset draft"><RotateCcw size={15} />Reset draft</button><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving && <LoaderCircle className="spin" size={17} />}{saving ? 'Saving…' : 'Save'}</button></footer></form>
  </BlockingDialog>
}

export function DeleteMasterDialog({ label, onClose, onDelete }) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  async function remove() {
    setSaving(true)
    try { await onDelete(); onClose() } catch (deleteError) {
      setError(deleteError.code === '23503' ? 'This record is referenced and cannot be deleted. Archive it instead.' : deleteError.message)
    } finally { setSaving(false) }
  }
  return <BlockingDialog className="confirm-dialog glass-surface" labelledBy="delete-master-title" onClose={onClose} busy={saving}><div className="confirm-dialog-body"><span className="confirm-dialog-icon"><AlertCircle size={24} /></span><h2 id="delete-master-title">Delete {label}?</h2><p>Only records that have never been referenced can be permanently deleted.</p>{error && <div className="form-error" role="alert">{error}</div>}</div><footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose} disabled={saving}>Cancel</button><button className="danger-button" type="button" onClick={remove} disabled={saving}>{saving ? 'Deleting…' : 'Delete permanently'}</button></footer></BlockingDialog>
}
