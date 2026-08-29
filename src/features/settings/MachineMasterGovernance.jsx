import { Archive, Edit3, Factory, Plus, RotateCcw, Workflow } from 'lucide-react'

export function MachineMasterGovernance({ view, onViewChange, manufacturers, models, onCreate, onEdit, onSetStatus }) {
  const records = view === 'manufacturers' ? manufacturers : models
  const kind = view === 'manufacturers' ? 'manufacturer' : 'model'
  const hasActiveManufacturer = manufacturers.some((manufacturer) => manufacturer.is_active)
  return <section className="settings-card glass-surface machine-master-governance">
    <header className="machine-master-header"><div><span className="card-kicker">Canonical equipment identity</span><h2>Machine Models</h2><p>Manufacturers and Models are account masters reused across every Branch. Only explicit Platform Superusers can change them.</p></div><button className="primary-button compact-button machine-master-create" type="button" onClick={() => onCreate(kind)} disabled={view === 'models' && !hasActiveManufacturer} title={view === 'models' && !hasActiveManufacturer ? 'Create a Manufacturer first' : `Add ${kind}`}><Plus size={15} />{view === 'manufacturers' ? 'Manufacturer' : 'Model'}</button></header>
    <div className="machine-master-content">
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
        <div className="machine-master-identity"><strong>{record.name}</strong><span>{view === 'manufacturers' ? record.code : manufacturer?.name ?? 'Unknown manufacturer'}</span><small>{shared ? 'Platform shared master · Read only' : view === 'manufacturers' ? 'Account manufacturer' : `${record.model_code} · Account machine model`}</small></div>
        <div className="machine-master-fact"><span>{view === 'manufacturers' ? 'Models' : 'Manufacturer'}</span><strong>{view === 'manufacturers' ? modelCount : manufacturer?.name ?? 'Unknown'}</strong></div>
        <span className={`scope-pill ${record.is_active ? 'custom' : 'archived'}`}>{record.is_active ? 'Active' : 'Archived'}</span>
        <div className="settings-row-actions machine-master-actions">{shared ? <><span aria-hidden="true" /><span aria-hidden="true" /></> : <><button type="button" aria-label={`Edit ${record.name}`} title={`Edit ${record.name}`} onClick={() => onEdit(kind, record)}><Edit3 size={15} /></button><button type="button" aria-label={`${record.is_active ? 'Archive' : 'Restore'} ${record.name}`} title={`${record.is_active ? 'Archive' : 'Restore'} ${record.name}`} onClick={() => onSetStatus(kind, record, !record.is_active)}>{record.is_active ? <Archive size={15} /> : <RotateCcw size={15} />}</button></>}</div>
      </article>
      })}</div>
      {!records.length && <div className="settings-empty machine-master-empty"><strong>{view === 'manufacturers' ? 'No manufacturers have been added yet.' : 'No machine models have been added yet.'}</strong><span>{view === 'models' && !hasActiveManufacturer ? 'Create a Manufacturer first, then add its Machine Model.' : 'Create only the real machine master needed for onboarding.'}</span></div>}
    </div>
  </section>
}
