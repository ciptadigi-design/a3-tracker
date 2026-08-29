import { Archive, Edit3, Factory, Plus, RotateCcw, Workflow } from 'lucide-react'

export function MachineMasterGovernance({ view, onViewChange, manufacturers, models, onCreate, onEdit, onSetStatus }) {
  const records = view === 'manufacturers' ? manufacturers : models
  return <section className="settings-card glass-surface machine-master-governance">
    <header><div><span className="card-kicker">Canonical equipment identity</span><h2>Machine Models</h2><p>Manufacturers and Models are account masters reused across every Branch. Only explicit Platform Superusers can change them.</p></div><button className="primary-button compact-button" type="button" onClick={() => onCreate(view === 'manufacturers' ? 'manufacturer' : 'model')}><Plus size={15} />{view === 'manufacturers' ? 'Manufacturer' : 'Machine Model'}</button></header>
    <div className="machine-view-tabs record-tabs machine-master-tabs" role="tablist" aria-label="Machine master type">
      <button type="button" role="tab" aria-selected={view === 'manufacturers'} className={view === 'manufacturers' ? 'selected' : ''} onClick={() => onViewChange('manufacturers')}><Factory size={14} />Manufacturers <span>{manufacturers.length}</span></button>
      <button type="button" role="tab" aria-selected={view === 'models'} className={view === 'models' ? 'selected' : ''} onClick={() => onViewChange('models')}><Workflow size={14} />Models <span>{models.length}</span></button>
    </div>
    <div className="settings-row-list machine-master-list">{records.map((record) => {
      const shared = record.account_id == null
      const manufacturer = view === 'models' ? record.manufacturers : null
      const modelCount = view === 'manufacturers' ? models.filter((model) => model.manufacturer_id === record.id).length : null
      return <article key={record.id} className={!record.is_active ? 'archived' : ''}>
        <span className="settings-row-icon">{view === 'manufacturers' ? <Factory size={18} /> : <Workflow size={18} />}</span>
        <div><strong>{record.name}</strong><span>{view === 'manufacturers' ? record.code : `${manufacturer?.name ?? 'Unknown manufacturer'} · ${record.model_code}`}</span><small>{shared ? 'Platform shared master · read only' : view === 'manufacturers' ? `${modelCount} ${modelCount === 1 ? 'model' : 'models'} in this account` : 'Account machine model'}</small></div>
        <span className={`scope-pill ${record.is_active ? 'custom' : 'archived'}`}>{record.is_active ? 'Active' : 'Archived'}</span>
        <div className="settings-row-actions">{!shared && <><button type="button" aria-label={`Edit ${record.name}`} title={`Edit ${record.name}`} onClick={() => onEdit(view === 'manufacturers' ? 'manufacturer' : 'model', record)}><Edit3 size={15} /></button><button type="button" aria-label={`${record.is_active ? 'Archive' : 'Restore'} ${record.name}`} title={`${record.is_active ? 'Archive' : 'Restore'} ${record.name}`} onClick={() => onSetStatus(view === 'manufacturers' ? 'manufacturer' : 'model', record, !record.is_active)}>{record.is_active ? <Archive size={15} /> : <RotateCcw size={15} />}</button></>}</div>
      </article>
    })}</div>
    {!records.length && <div className="settings-empty"><strong>No {view === 'manufacturers' ? 'manufacturers' : 'machine models'} yet.</strong><span>Create only the real machine master needed for onboarding.</span></div>}
  </section>
}
