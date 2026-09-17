import { FileText, PencilLine } from 'lucide-react'
import { documentStatusLabels, documentTypeLabels, formatMaintenanceDate } from '../maintenanceUtils.js'

const statusPillClass = { DRAFT: '', PUBLISHED: 'resolved', ARCHIVED: 'voided' }

export function DocumentList({ documents, canManageRow, onOpen, onEdit }) {
  if (documents.length === 0) return null

  return (
    <ul className="incident-narrative-list">
      {documents.map((doc) => (
        <li key={doc.id} className="incident-narrative-card glass-surface maintenance-error-code-row">
          <button type="button" className="maintenance-error-code-row-header" onClick={() => onOpen(doc)}>
            <FileText size={16} />
            <div>
              <strong>{doc.title}</strong>
              <span className={`incident-status-pill ${statusPillClass[doc.status] ?? ''}`}>{documentStatusLabels[doc.status] ?? doc.status}</span>
            </div>
            <small>{documentTypeLabels[doc.document_type] ?? doc.document_type} · {formatMaintenanceDate(doc.created_at, undefined, { dateOnly: true })}</small>
            {canManageRow(doc) && <button className="icon-button" type="button" onClick={(event) => { event.stopPropagation(); onEdit(doc) }} aria-label={`Edit ${doc.title}`}><PencilLine size={15} /></button>}
          </button>
        </li>
      ))}
    </ul>
  )
}
