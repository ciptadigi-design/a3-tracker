import { useCallback, useEffect, useRef, useState } from 'react'
import { AlertTriangle, CheckCircle2, LoaderCircle, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { applyFilterBulkReview, previewFilterBulkReview } from '../../../services/maintenance.js'
import { EVIDENCE_LABELS, blockedReasonMessage, canConfirmBulk, classifyBulkError, describeBulkFilters, needsAcknowledgement, occurrencePageLabel } from './codeGroupUtils.js'

/**
 * V1.7.2 - deliberate bulk triage. The reviewer picks filters in the panel; this dialog then:
 *   1. asks the server for a PREVIEW (read-only: matching / eligible / excluded counts, a small
 *      structural sample, and a short-lived confirmation token),
 *   2. shows exactly what would change, and
 *   3. only after an explicit confirmation (plus an acknowledgement for large sets) applies it,
 *      reporting the count the SERVER actually changed.
 * Only REJECT and RESTORE exist. Nothing here approves, publishes or deletes.
 */
export function TriageDialog({ importId, action, bulkFilters: openedWithFilters, ignoredFilters = [], onClose, onApplied }) {
  // Frozen at open: the parent recomputes its filter object every render, and a bulk action must always
  // operate on exactly the criteria the reviewer saw when they opened it (the dialog is modal).
  const [bulkFilters] = useState(() => openedWithFilters)
  const isReject = action === 'reject'
  const [phase, setPhase] = useState('previewing') // previewing | ready | applying | done | failed
  const [preview, setPreview] = useState(null)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [acknowledged, setAcknowledged] = useState(false)
  const requestId = useRef(0)
  const chips = describeBulkFilters(bulkFilters)

  const loadPreview = useCallback(() => previewFilterBulkReview(importId, { action, filters: bulkFilters }), [importId, action, bulkFilters])

  // Auto-preview on open. It is read-only, and it guarantees the reviewer never acts on stale numbers.
  // State is only set from the promise callbacks, never synchronously inside the effect body.
  useEffect(() => {
    let active = true
    loadPreview()
      .then((data) => { if (active) { setPreview(data); setPhase('ready') } })
      .catch((previewError) => { if (active) { setError(classifyBulkError(previewError)); setPreview(null); setPhase('failed') } })
    return () => { active = false }
  }, [loadPreview])

  // Re-preview from a click (Refresh / Preview again after a stale or invalid confirmation).
  async function runPreview() {
    const request = ++requestId.current
    setPhase('previewing')
    setError(null)
    setAcknowledged(false)
    try {
      const data = await loadPreview()
      if (requestId.current !== request) return
      setPreview(data)
      setPhase('ready')
    } catch (previewError) {
      if (requestId.current !== request) return
      setError(classifyBulkError(previewError))
      setPreview(null)
      setPhase('failed')
    }
  }

  async function handleConfirm() {
    if (!canConfirmBulk({ preview, acknowledged, isApplying: phase === 'applying' })) return
    setPhase('applying')
    setError(null)
    try {
      // Send the server's own canonical filters for exactly the previewed set, plus its token.
      const data = await applyFilterBulkReview(importId, { action, filters: preview.filters, confirmationToken: preview.confirmation_token })
      setResult(data)
      setPhase('done')
      onApplied?.(data)
    } catch (applyError) {
      setError(classifyBulkError(applyError))
      setPhase('failed')
    }
  }

  const busy = phase === 'applying'
  const blocked = preview && !preview.can_apply ? blockedReasonMessage(preview.blocked_reason) : null
  const eligible = preview?.eligible_count ?? 0
  const verb = isReject ? 'Reject' : 'Restore'

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" role="alertdialog" labelledBy="triage-dialog-title" describedBy="triage-dialog-description" onClose={onClose} busy={busy}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className={isReject ? 'danger-dialog-icon' : 'dialog-icon'}>{isReject ? <AlertTriangle size={23} /> : <RotateCcw size={23} />}</span><div><span className="card-kicker">Bulk triage</span><h2 id="triage-dialog-title">{isReject ? 'Reject candidates by filter' : 'Restore candidates by filter'}</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={busy} aria-label="Close bulk triage"><X size={19} /></button>
      </header>
      <div className="machine-form-body">
        <p id="triage-dialog-description">
          {isReject
            ? 'Candidates stay in the import with their provenance and can be restored later. Nothing is approved or published by this action.'
            : 'Candidates return to draft for review. Nothing is approved or published by this action.'}
        </p>

        <div className="maintenance-filter-chips" aria-label="Active filters">
          {chips.map((chip) => <span key={chip} className="incident-status-pill">{chip}</span>)}
        </div>
        {ignoredFilters.length > 0 && <small>Not part of a bulk selection: {ignoredFilters.join(', ')}.</small>}

        {phase === 'previewing' && <p role="status"><LoaderCircle className="spin" size={15} /> Calculating what would change…</p>}

        {preview && phase !== 'previewing' && phase !== 'done' && (
          <div className="maintenance-triage-preview" data-testid="triage-preview">
            <dl className="maintenance-triage-counts">
              <div><dt>Matching</dt><dd>{preview.matching_count}</dd></div>
              <div><dt>Eligible</dt><dd>{eligible}</dd></div>
              <div><dt>Excluded</dt><dd>{preview.excluded_count}</dd></div>
            </dl>
            {preview.excluded_count > 0 && (
              <small>
                Excluded because they are not {isReject ? 'drafts' : 'rejected'} or are already published:
                {' '}{Object.entries(preview.excluded_breakdown).filter(([, n]) => n > 0).map(([k, n]) => `${k.toLowerCase()} ${n}`).join(', ')}.
              </small>
            )}
            {blocked && <div className="form-error" role="alert"><AlertTriangle size={16} /><span>{blocked}</span></div>}
            {preview.sample.length > 0 && (
              <>
                <strong>Sample of {preview.sample.length} of {eligible}</strong>
                <ul className="maintenance-triage-sample">
                  {preview.sample.map((row) => <li key={row.id}>{row.normalized_code} · {occurrencePageLabel(row)} · {EVIDENCE_LABELS[row.evidence] ?? row.evidence}</li>)}
                </ul>
              </>
            )}
            {preview.can_apply && needsAcknowledgement(eligible) && (
              <label className="maintenance-select-page-row">
                <input type="checkbox" checked={acknowledged} onChange={(event) => setAcknowledged(event.target.checked)} aria-label={`I understand ${eligible} candidates will be changed`} />
                <span>I understand {eligible} candidates will be {isReject ? 'rejected' : 'restored to draft'}.</span>
              </label>
            )}
          </div>
        )}

        {phase === 'done' && result && (
          <p role="status" className="maintenance-triage-result"><CheckCircle2 size={16} /> {isReject ? 'Rejected' : 'Restored'} {result.affected} candidate{result.affected === 1 ? '' : 's'}.</p>
        )}

        {error && (
          <div className="form-error" role="alert"><AlertTriangle size={16} /><span>{error.message}</span>
            {(error.kind === 'STALE' || error.kind === 'INVALID') && <button className="secondary-button" type="button" onClick={runPreview}>Preview again</button>}
          </div>
        )}
      </div>
      <footer className="dialog-actions">
        {phase === 'done' ? (
          <button className="primary-button" type="button" onClick={onClose}>Done</button>
        ) : (
          <>
            <button className="secondary-button" type="button" onClick={onClose} disabled={busy}>Cancel</button>
            <button className="secondary-button" type="button" onClick={runPreview} disabled={busy || phase === 'previewing'}>Refresh preview</button>
            <button className={isReject ? 'danger-button' : 'primary-button'} type="button" onClick={handleConfirm} disabled={!canConfirmBulk({ preview, acknowledged, isApplying: busy })}>
              {busy && <LoaderCircle className="spin" size={17} />}
              {busy ? 'Working…' : `${verb} ${eligible} eligible candidate${eligible === 1 ? '' : 's'}`}
            </button>
          </>
        )}
      </footer>
    </BlockingDialog>
  )
}
