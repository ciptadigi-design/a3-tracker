import { useCallback, useEffect, useMemo, useState } from 'react'
import { Archive, Edit3, Factory, Plus, RefreshCcw, ShieldCheck, Trash2, UserRound, Workflow } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { useAuth } from '../features/auth/useAuth.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { DeleteMasterDialog, MasterRecordDialog } from '../features/operationalMasters/MasterRecordDialog.jsx'
import { deleteMachineModel, deleteManufacturer, deleteOperationalPerson, loadOperationalMasters, saveMachineModel, saveManufacturer, saveOperationalPerson } from '../services/supabase/operationalMasters.js'

const sections = [
  { id: 'people', label: 'Operators / PIC', icon: UserRound },
  { id: 'manufacturers', label: 'Manufacturers', icon: Factory },
  { id: 'models', label: 'Machine Models', icon: Workflow },
]

export function SettingsPage() {
  const { user } = useAuth()
  const { account, branch, membership } = useTenant()
  const canManage = membership?.role === 'owner' || membership?.role === 'admin'
  const stateKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch?.id, feature: 'operational-masters', entityId: 'section' })
  const sectionState = usePersistentUIState({ uiStateKey: stateKey, initialValue: { section: 'people' }, validate: (value) => sections.some((item) => item.id === value?.section) })
  const [data, setData] = useState({ people: [], manufacturers: [], models: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [dialog, setDialog] = useState(null)
  const [success, setSuccess] = useState(null)

  const refresh = useCallback(async () => {
    setLoading(true); setError(null)
    try { setData(await loadOperationalMasters({ accountId: account.id, includeArchived: canManage })) }
    catch (loadError) { setError(loadError) }
    finally { setLoading(false) }
  }, [account.id, canManage])
  useEffect(() => { refresh() }, [refresh])

  const section = sectionState.value.section
  const records = data[section]
  const kind = section === 'people' ? 'person' : section === 'manufacturers' ? 'manufacturer' : 'model'
  const title = sections.find((item) => item.id === section)?.label
  const modelCounts = useMemo(() => data.models.reduce((map, model) => map.set(model.manufacturer_id, (map.get(model.manufacturer_id) || 0) + 1), new Map()), [data.models])

  async function save(values) {
    if (kind === 'person') await saveOperationalPerson({ accountId: account.id, personId: dialog.record?.id, values })
    else if (kind === 'manufacturer') await saveManufacturer({ accountId: account.id, manufacturerId: dialog.record?.id, values })
    else await saveMachineModel({ accountId: account.id, modelId: dialog.record?.id, values })
    setSuccess(`${kind === 'person' ? 'PIC / Operator' : kind === 'manufacturer' ? 'Manufacturer' : 'Machine model'} saved.`)
    await refresh()
  }

  async function remove() {
    const id = dialog.record.id
    if (kind === 'person') await deleteOperationalPerson({ accountId: account.id, personId: id })
    else if (kind === 'manufacturer') await deleteManufacturer({ accountId: account.id, manufacturerId: id })
    else await deleteMachineModel({ accountId: account.id, modelId: id })
    setSuccess('Unreferenced record deleted.')
    await refresh()
  }

  return <div className="page-stack settings-page">
    <PageHeader eyebrow={`${account.name} · Settings`} title="Operational masters" description="Manage the people and machine catalog used by daily operational workflows." action={canManage ? <button className="primary-button" type="button" onClick={() => setDialog({ type: 'form', record: null })}><Plus size={17} />Add {kind === 'person' ? 'operator' : kind}</button> : null} />
    {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role} role can read active master data but cannot manage it.</span></div>}
    {success && <div className="success-banner" role="status"><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
    <section className="master-management glass-surface">
      <div className="master-tabs" role="tablist" aria-label="Operational master sections">{sections.map((item) => <button key={item.id} type="button" role="tab" aria-selected={section === item.id} className={section === item.id ? 'selected' : ''} onClick={() => sectionState.setUIState({ section: item.id })}><item.icon size={16} />{item.label}</button>)}</div>
      <header className="master-toolbar"><div><span className="card-kicker">Workspace directory</span><h2>{title}</h2><p>{section === 'people' ? 'Selectable operational people for Daily and future workflows.' : 'Shared platform definitions are protected; workspace records are manageable here.'}</p></div><button className="icon-button" type="button" onClick={refresh} disabled={loading} aria-label={`Refresh ${title}`}><RefreshCcw className={loading ? 'spin' : ''} size={17} /></button></header>
      {error ? <div className="embedded-error" role="alert"><strong>Master data could not be loaded.</strong><span>{error.message}</span></div> : loading ? <div className="history-state"><RefreshCcw className="spin" size={23} /><strong>Loading operational masters…</strong></div> : records.length === 0 ? <div className="history-state"><strong>No records available.</strong><span>{canManage ? 'Add the first workspace record when ready.' : 'Ask an owner or admin to configure this directory.'}</span></div> : <div className="master-record-list">{records.map((record) => {
        const shared = section !== 'people' && !record.account_id
        const subtitle = section === 'people' ? record.code || 'No code' : section === 'manufacturers' ? `${record.code} · ${modelCounts.get(record.id) || 0} models` : `${record.manufacturers?.name} · ${record.model_code}`
        return <article className={`master-record ${record.is_active ? '' : 'archived'}`} key={record.id}><span className="master-record-icon">{section === 'people' ? <UserRound size={19} /> : section === 'manufacturers' ? <Factory size={19} /> : <Workflow size={19} />}</span><div><div className="master-record-name"><strong>{record.name}</strong><span className={shared ? 'scope-pill' : 'scope-pill custom'}>{shared ? 'Shared' : 'Workspace'}</span>{!record.is_active && <span className="scope-pill archived"><Archive size={12} />Archived</span>}</div><span>{subtitle}</span>{record.notes && <small>{record.notes}</small>}</div>{canManage && !shared && <div className="master-record-actions"><button type="button" onClick={() => setDialog({ type: 'form', record })} aria-label={`Edit ${record.name}`}><Edit3 size={15} /></button><button type="button" onClick={() => setDialog({ type: 'delete', record })} aria-label={`Delete ${record.name}`}><Trash2 size={15} /></button></div>}</article>
      })}</div>}
    </section>
    {dialog?.type === 'form' && <MasterRecordDialog kind={kind} record={dialog.record} account={account} manufacturers={data.manufacturers} onClose={() => setDialog(null)} onSave={save} />}
    {dialog?.type === 'delete' && <DeleteMasterDialog label={dialog.record.name} onClose={() => setDialog(null)} onDelete={remove} />}
  </div>
}
