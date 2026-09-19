import { ClipboardList } from 'lucide-react'
import { formatMaintenanceDate, importStatusLabels, importTypeLabels } from '../maintenanceUtils.js'

const statusPillClass = { DRAFT: '', PROCESSING: '', REVIEW: '', PUBLISHED: 'resolved', REJECTED: 'voided', FAILED: 'voided' }

// V1.6: a PDF_EXTRACTION import in PROCESSING reports real pages_processed/
// candidate_count (never a fabricated percentage) - shown here so progress is
// visible without opening the detail dialog.
function ProcessingProgress({ item }) {
  if (item.import_type !== 'PDF_EXTRACTION' || item.status !== 'PROCESSING') return null
  const totalPages = item.extraction?.total_pages
  return (
    <small className="maintenance-processing-progress">
      {totalPages ? `Page ${item.pages_processed ?? 0} of ${totalPages}` : `${item.pages_processed ?? 0} pages processed`} · {item.candidate_count ?? 0} candidate{item.candidate_count === 1 ? '' : 's'} found
    </small>
  )
}

export function KnowledgeImportList({ imports, onOpen }) {
  if (imports.length === 0) {
    return <p className="maintenance-compact-empty"><ClipboardList size={16} /> No knowledge imports yet. Start one to turn this document into structured maintenance knowledge.</p>
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
            <ProcessingProgress item={item} />
          </button>
        </li>
      ))}
    </ul>
  )
}
