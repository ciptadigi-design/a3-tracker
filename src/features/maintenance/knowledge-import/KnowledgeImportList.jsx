import { ClipboardList } from 'lucide-react'
import { formatMaintenanceDate, importStatusLabels, importTypeLabels } from '../maintenanceUtils.js'

const statusPillClass = { DRAFT: '', PROCESSING: '', REVIEW: '', PUBLISHED: 'resolved', REJECTED: 'voided' }

export function KnowledgeImportList({ imports, onOpen }) {
  if (imports.length === 0) {
    return <div className="machine-empty-state"><ClipboardList size={38} strokeWidth={1.35} /><h3>No knowledge imports yet.</h3><p>Start one to turn this document into structured maintenance knowledge.</p></div>
  }

  return (
    <ul className="incident-narrative-list">
      {imports.map((item) => (
        <li key={item.id} className="incident-narrative-card glass-surface maintenance-error-code-row">
          <button type="button" className="maintenance-error-code-row-header" onClick={() => onOpen(item)}>
            <ClipboardList size={16} />
            <div>
              <strong>{item.machine_model?.name || 'No machine model selected'}</strong>
              <span className={`incident-status-pill ${statusPillClass[item.status] ?? ''}`}>{importStatusLabels[item.status] ?? item.status}</span>
            </div>
            <small>{importTypeLabels[item.import_type] ?? item.import_type} · Started {formatMaintenanceDate(item.created_at, undefined, { dateOnly: true })}</small>
          </button>
        </li>
      ))}
    </ul>
  )
}
