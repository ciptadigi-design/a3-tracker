import { useState } from 'react'
import { CheckCircle2, ClipboardList, LoaderCircle, RotateCcw, Rocket, X, XCircle } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { bulkReviewEntries, updateKnowledgeEntry } from '../../../services/maintenance.js'
import { collisionStatusLabels, entryStatusLabels, mapMaintenanceError } from '../maintenanceUtils.js'
import { EVIDENCE_LABELS, REVIEW_STATE_LABELS, REVIEW_STATE_PILL_CLASS, evidenceMix, occurrenceLabel, occurrencePageLabel, pageRangeLabel } from './codeGroupUtils.js'
import { useCodeGroupDetail } from './useCodeGroupDetail.js'

const statusPillClass = { DRAFT: '', APPROVED: 'resolved', REJECTED: 'voided' }
const evidencePillClass = { HIGH: 'resolved', MEDIUM: '', LOW: 'voided' }

function OccurrenceRow({ occurrence, canManage, busy, onApprove, onReject, onRestore, onPublish }) {
  const published = Boolean(occurrence.published_at)
  return (
    <li className="maintenance-occurrence-row glass-surface" data-evidence={occurrence.evidence} data-status={occurrence.status}>
      <div className="maintenance-occurrence-head">
        <span className={`incident-status-pill ${evidencePillClass[occurrence.evidence] ?? ''}`}>{EVIDENCE_LABELS[occurrence.evidence] ?? occurrence.evidence ?? 'No evidence'}</span>
        <strong>{occurrencePageLabel(occurrence)}</strong>
        <span className={`incident-status-pill ${statusPillClass[occurrence.status] ?? ''}`}>{published ? 'Published' : (entryStatusLabels[occurrence.status] ?? occurrence.status)}</span>
        {occurrence.collision_status && <span className="incident-status-pill">{collisionStatusLabels[occurrence.collision_status] ?? occurrence.collision_status}</span>}
        {occurrence.reference_like && <span className="incident-status-pill" title="Dotted-leader signal: table-of-contents / index style">Reference-like</span>}
      </div>
      <p className="maintenance-occurrence-title">{occurrence.title}</p>
      {occurrence.description && (
        <details className="maintenance-occurrence-excerpt">
          <summary>Stored excerpt</summary>
          <p>{occurrence.description}</p>
        </details>
      )}
      {canManage && (
        <div className="dialog-actions">
          {occurrence.status === 'DRAFT' && <button className="icon-button" type="button" disabled={busy} onClick={() => onApprove(occurrence)} aria-label={`Approve occurrence on ${occurrencePageLabel(occurrence)}`}><CheckCircle2 size={15} /></button>}
          {occurrence.status === 'DRAFT' && <button className="icon-button" type="button" disabled={busy} onClick={() => onReject(occurrence)} aria-label={`Reject occurrence on ${occurrencePageLabel(occurrence)}`}><XCircle size={15} /></button>}
          {occurrence.status === 'REJECTED' && <button className="secondary-button" type="button" disabled={busy} onClick={() => onRestore(occurrence)}><RotateCcw size={14} /> Restore to draft</button>}
          {occurrence.status === 'APPROVED' && !published && <button className="secondary-button" type="button" disabled={busy} onClick={() => onPublish(occurrence)}><Rocket size={14} /> Publish</button>}
        </div>
      )}
    </li>
  )
}

/**
 * V1.7.2 - one normalized code with EVERY underlying candidate occurrence, individually.
 * Order comes from the backend (High, then Medium, then Low, then page) and is never
 * collapsed or re-sorted here. Each occurrence keeps its own status: reviewing one
 * occurrence never silently reviews another, and publishing stays a separate explicit step.
 */
export function CodeGroupDetailDialog({ importId, code, canManage, version, onClose, onChanged, onPublish }) {
  const detail = useCodeGroupDetail({ importId, code, version })
  const [busyId, setBusyId] = useState(null)
  const [actionError, setActionError] = useState(null)
  const group = detail.group

  async function run(occurrence, action) {
    if (busyId) return
    setBusyId(occurrence.id)
    setActionError(null)
    try {
      await action()
      detail.refresh()
      onChanged?.()
    } catch (error) {
      setActionError(mapMaintenanceError(error))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="code-group-title" onClose={onClose}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><ClipboardList size={22} /></span><div><span className="card-kicker">Code group</span><h2 id="code-group-title">{code}</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} aria-label="Close code group"><X size={19} /></button>
      </header>
      <div className="machine-form-body">
        {detail.isLoading && !group ? <LoadingScreen label="Loading occurrences" />
          : detail.error ? <ErrorState title="Code group could not be loaded" detail="It may have been removed or belong to a different account." onRetry={detail.refresh} />
          : !group ? null
          : (
            <>
              <div className="maintenance-code-group-header">
                <span className={`incident-status-pill ${evidencePillClass[group.best_evidence] ?? ''}`}>Best evidence: {EVIDENCE_LABELS[group.best_evidence] ?? group.best_evidence}</span>
                <span className={`incident-status-pill ${REVIEW_STATE_PILL_CLASS[group.review_state] ?? ''}`}>{REVIEW_STATE_LABELS[group.review_state] ?? group.review_state}</span>
                <span>{occurrenceLabel(group.occurrence_count)}</span>
                <span>{evidenceMix(group)}</span>
                <span>{pageRangeLabel(group)}</span>
              </div>
              <p><small>Each row below is one detected candidate. Several can support the same machine error code, from different places in the manual. Approving an occurrence is a review decision for that occurrence only; publishing is a separate step.</small></p>
              {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}
              <ul className="incident-narrative-list">
                {group.occurrences.map((occurrence) => (
                  <OccurrenceRow
                    key={occurrence.id}
                    occurrence={occurrence}
                    canManage={canManage}
                    busy={busyId !== null}
                    onApprove={(o) => run(o, () => updateKnowledgeEntry(o.id, { status: 'APPROVED' }))}
                    onReject={(o) => run(o, () => updateKnowledgeEntry(o.id, { status: 'REJECTED' }))}
                    onRestore={(o) => run(o, () => bulkReviewEntries(importId, { entryIds: [o.id], action: 'restore' }))}
                    onPublish={onPublish}
                  />
                ))}
              </ul>
              {group.occurrences_truncated && <small>Showing the first {group.occurrences.length} occurrences.</small>}
              {busyId && <small><LoaderCircle className="spin" size={13} /> Saving…</small>}
            </>
          )}
      </div>
    </BlockingDialog>
  )
}
