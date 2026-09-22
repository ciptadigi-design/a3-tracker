import { useState } from 'react'
import { BookOpenCheck, CheckCircle2, ClipboardList, LoaderCircle, Plus, RotateCcw, Rocket, Trash2, X, XCircle } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { bulkReviewEntries, updateKnowledgeEntry } from '../../../services/maintenance.js'
import { collisionStatusLabels, entryStatusLabels, formatMaintenanceDate, mapMaintenanceError } from '../maintenanceUtils.js'
import { CONTEXT_HINT_HELP, CONTEXT_HINT_LABELS, CONTEXT_REASON_LABELS, EVIDENCE_LABELS, REVIEW_STATE_LABELS, REVIEW_STATE_PILL_CLASS, evidenceMix, occurrenceLabel, occurrencePageLabel, pageRangeLabel } from './codeGroupUtils.js'
import { GroupPublishDialog } from './GroupPublishDialog.jsx'
import { SourcePageContext } from './SourcePageContext.jsx'
import {
  DRAFT_LIMITS, EMPTY_DRAFT, EMPTY_SOLUTION, PLACEHOLDER_TITLE_MESSAGE, WORKFLOW_LABELS, canRequestPreview, collisionLabel, isPlaceholderTitle, previewIsCurrent, supportingPagesLabel, validateDraft, workflowState,
} from './groupPublishUtils.js'
import { useCodeGroupDetail } from './useCodeGroupDetail.js'

const statusPillClass = { DRAFT: '', APPROVED: 'resolved', REJECTED: 'voided' }
const evidencePillClass = { HIGH: 'resolved', MEDIUM: '', LOW: 'voided' }

function OccurrenceRow({ occurrence, canManage, busy, isCanonical, isSuggested, canChooseCanonical, onUseCanonical, onApprove, onReject, onRestore }) {
  const published = Boolean(occurrence.published_at)
  return (
    <li className={`maintenance-occurrence-row glass-surface${isCanonical ? ' is-canonical' : ''}`} data-evidence={occurrence.evidence} data-status={occurrence.status} data-canonical={isCanonical ? 'true' : 'false'}>
      <div className="maintenance-occurrence-head">
        <span className={`incident-status-pill ${evidencePillClass[occurrence.evidence] ?? ''}`}>{EVIDENCE_LABELS[occurrence.evidence] ?? occurrence.evidence ?? 'No evidence'}</span>
        <strong>{occurrencePageLabel(occurrence)}</strong>
        <span className={`incident-status-pill ${statusPillClass[occurrence.status] ?? ''}`}>{published ? 'Published' : (entryStatusLabels[occurrence.status] ?? occurrence.status)}</span>
        {occurrence.collision_status && <span className="incident-status-pill">{collisionStatusLabels[occurrence.collision_status] ?? occurrence.collision_status}</span>}
        {occurrence.reference_like && <span className="incident-status-pill" title="Dotted-leader signal: table-of-contents / index style">Reference-like</span>}
        {isCanonical && <span className="incident-status-pill resolved">Canonical</span>}
        {isSuggested && !isCanonical && <span className="incident-status-pill" title="Strongest evidence, earliest page. A suggestion only - you decide.">Suggested</span>}
      </div>
      <p className="maintenance-occurrence-title">{occurrence.title}{occurrence.title_is_placeholder ? ' (placeholder title)' : ''}</p>
      {occurrence.description && (
        <details className="maintenance-occurrence-excerpt">
          <summary>Stored excerpt</summary>
          <p>{occurrence.description}</p>
        </details>
      )}
      {canManage && (
        <div className="dialog-actions">
          {canChooseCanonical && occurrence.status !== 'REJECTED' && (
            <button className={isCanonical ? 'primary-button' : 'secondary-button'} type="button" aria-pressed={isCanonical} disabled={busy} onClick={() => onUseCanonical(occurrence)}>
              {isCanonical ? 'Canonical occurrence' : 'Use as canonical'}
            </button>
          )}
          {occurrence.status === 'DRAFT' && <button className="icon-button" type="button" disabled={busy} onClick={() => onApprove(occurrence)} aria-label={`Approve occurrence on ${occurrencePageLabel(occurrence)}`}><CheckCircle2 size={15} /></button>}
          {occurrence.status === 'DRAFT' && <button className="icon-button" type="button" disabled={busy} onClick={() => onReject(occurrence)} aria-label={`Reject occurrence on ${occurrencePageLabel(occurrence)}`}><XCircle size={15} /></button>}
          {occurrence.status === 'REJECTED' && <button className="secondary-button" type="button" disabled={busy} onClick={() => onRestore(occurrence)}><RotateCcw size={14} /> Restore to draft</button>}
        </div>
      )}
    </li>
  )
}

/**
 * V1.8.1 - a repeatable list, not a single field: a code can have more than one technician
 * procedure, each applying to a different accessory/hardware/model context (a real, recurring
 * pattern in the source manuals - see KnowledgeGroupPublisher's docblock). Applicability is
 * optional; at most one solution may be left unlabelled ("General"), enforced client-side by
 * validateDraft and authoritatively by the server. Examples shown as placeholders are generic
 * ("Accessory A/B") - real labels are reviewer-authored data, never hard-coded here.
 */
function SolutionEditor({ solutions, onChange, rowErrors, showErrors }) {
  function updateRow(index, patch) {
    onChange(solutions.map((s, i) => (i === index ? { ...s, ...patch } : s)))
  }
  function addRow() {
    onChange([...solutions, { ...EMPTY_SOLUTION }])
  }
  function removeRow(index) {
    onChange(solutions.filter((_, i) => i !== index))
  }

  return (
    <div className="maintenance-solution-list" role="group" aria-label="Technician solutions">
      {solutions.map((solution, index) => {
        const errors = rowErrors?.[index] ?? {}
        return (
          <div className="maintenance-solution-row" key={index}>
            <label className="maintenance-field">
              <span>Applicability <small>(optional)</small></span>
              <input value={solution.applicabilityLabel} onChange={(event) => updateRow(index, { applicabilityLabel: event.target.value })} maxLength={DRAFT_LIMITS.applicabilityLabel} placeholder="e.g. Accessory A/B" aria-label={`Applicability for technician solution ${index + 1}`} aria-invalid={Boolean(showErrors && errors.applicabilityLabel)} />
            </label>
            <label className="maintenance-field">
              <span>Technician solution</span>
              <textarea rows={4} value={solution.instruction} onChange={(event) => updateRow(index, { instruction: event.target.value })} maxLength={DRAFT_LIMITS.instruction} aria-label={`Technician solution ${index + 1}`} aria-invalid={Boolean(showErrors && errors.instruction)} />
            </label>
            {showErrors && (errors.applicabilityLabel || errors.instruction) && <div className="form-error" role="alert"><span>{errors.applicabilityLabel ?? errors.instruction}</span></div>}
            <button className="icon-button" type="button" onClick={() => removeRow(index)} aria-label={`Remove technician solution ${index + 1}`}><Trash2 size={15} /></button>
          </div>
        )
      })}
      <button className="secondary-button" type="button" onClick={addRow}><Plus size={15} /> Add another solution</button>
      {solutions.length === 0 && <small>Optional - a code can be published with no technician solution yet, using only its description and operator guidance.</small>}
    </div>
  )
}

function CanonicalEditor({ code, draft, onChange, canonicalOccurrence, publication, errors, showErrors }) {
  const set = (key) => (event) => onChange({ ...draft, [key]: event.target.value })
  const titleIsPlaceholder = draft.title.trim() !== '' && isPlaceholderTitle(draft.title)
  return (
    <section className="maintenance-canonical-editor" aria-label="Canonical knowledge">
      <div className="form-section-heading"><strong>Canonical knowledge</strong><span>You are writing the published knowledge for {code}; the source PDF and its excerpts are never changed.</span></div>
      {canonicalOccurrence
        ? <p><small>Based on the occurrence from {occurrencePageLabel(canonicalOccurrence).toLowerCase()}.</small></p>
        : <p><small>Choose the canonical occurrence above to begin.</small></p>}

      <label className="maintenance-field"><span>Code</span><input value={code} readOnly aria-readonly="true" aria-label="Code (fixed by the group)" /></label>
      <label className="maintenance-field">
        <span>Title</span>
        <input value={draft.title} onChange={set('title')} maxLength={DRAFT_LIMITS.title} aria-label="Canonical title" aria-invalid={Boolean(titleIsPlaceholder || (showErrors && errors.title))} />
      </label>
      {(titleIsPlaceholder || (showErrors && errors.title)) && <div className="form-error" role="alert"><span>{PLACEHOLDER_TITLE_MESSAGE}</span></div>}
      {publication?.suggested_title && draft.title.trim() === '' && (
        <button className="secondary-button" type="button" onClick={() => onChange({ ...draft, title: publication.suggested_title })}>Use suggested title: “{publication.suggested_title}”</button>
      )}
      <label className="maintenance-field"><span>Description / cause</span><textarea rows={4} value={draft.description} onChange={set('description')} maxLength={DRAFT_LIMITS.description} aria-label="Canonical description or cause" /></label>
      {canonicalOccurrence?.description && draft.description.trim() === '' && (
        <button className="secondary-button" type="button" onClick={() => onChange({ ...draft, description: canonicalOccurrence.description })}>Start from the stored excerpt</button>
      )}
      <label className="maintenance-field"><span>Operator guidance</span><textarea rows={3} value={draft.operatorGuidance} onChange={set('operatorGuidance')} maxLength={DRAFT_LIMITS.operatorGuidance} aria-label="Canonical operator guidance" /></label>
      {showErrors && errors.content && <div className="form-error" role="alert"><span>{errors.content}</span></div>}
      <div className="form-section-heading"><strong>Technician solutions</strong><span>One code can have more than one procedure - add a separate row for each accessory, hardware or model context the manual distinguishes.</span></div>
      <SolutionEditor solutions={draft.solutions} onChange={(solutions) => onChange({ ...draft, solutions })} rowErrors={errors.solutionRows} showErrors={showErrors} />
      {showErrors && errors.solutions && <div className="form-error" role="alert"><span>{errors.solutions}</span></div>}
      <small>Nothing is generated for you: every field is written or confirmed by a reviewer.</small>
    </section>
  )
}

/**
 * One normalized code with EVERY underlying candidate occurrence, and the place where a reviewer turns them into ONE
 * published error code: choose the canonical occurrence explicitly, author the canonical knowledge, review the
 * server's publish preview, then publish that single code. Supporting occurrences are provenance (page references),
 * not competing publications; there is no publish-all and no bulk publish anywhere.
 */
export function CodeGroupDetailDialog({ importId, code, canManage, version, onClose, onChanged }) {
  const detail = useCodeGroupDetail({ importId, code, version })
  const [busyId, setBusyId] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [canonicalId, setCanonicalId] = useState(null)
  const [draft, setDraft] = useState({ ...EMPTY_DRAFT })
  const [showErrors, setShowErrors] = useState(false)
  const [showPublish, setShowPublish] = useState(false)
  const [lastPreview, setLastPreview] = useState(null)
  const group = detail.group
  const publication = group?.publication
  const published = Boolean(publication?.published)
  const canonicalOccurrence = group?.occurrences.find((o) => o.id === (published ? publication.canonical_candidate_id : canonicalId)) ?? null
  const errors = validateDraft(draft, canonicalId)
  const state = workflowState({ published, canonicalId, draft, previewIsFresh: previewIsCurrent(lastPreview, canonicalId, draft) && Boolean(lastPreview?.can_publish) })

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

  function requestPublishReview() {
    if (!canRequestPreview({ published, canonicalId, draft })) { setShowErrors(true); return }
    setShowPublish(true)
  }

  function handlePublished() {
    detail.refresh()
    onChanged?.()
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
              <div className="maintenance-code-group-header" data-testid="group-header">
                <span className={`incident-status-pill ${evidencePillClass[group.best_evidence] ?? ''}`}>Best evidence: {EVIDENCE_LABELS[group.best_evidence] ?? group.best_evidence}</span>
                <span className="incident-status-pill">{collisionLabel(publication?.server_collision_status)}</span>
                <span className={`incident-status-pill ${REVIEW_STATE_PILL_CLASS[group.review_state] ?? ''}`}>{REVIEW_STATE_LABELS[group.review_state] ?? group.review_state}</span>
                <span className={`incident-status-pill ${state === 'PUBLISHED' ? 'resolved' : ''}`} data-testid="workflow-state">{WORKFLOW_LABELS[state]}</span>
                <span>{occurrenceLabel(group.occurrence_count)}</span>
                <span>{publication?.supporting_page_count ?? 0} supporting page{publication?.supporting_page_count === 1 ? '' : 's'}</span>
                <span>{evidenceMix(group)}</span>
                <span>{pageRangeLabel(group)}</span>
                {group.context_hint === 'CONTEXT_RECOMMENDED' && (
                  <span className="maintenance-context-hint-badge" data-testid="context-hint" title={CONTEXT_HINT_HELP}>
                    <BookOpenCheck size={13} /> {CONTEXT_HINT_LABELS.CONTEXT_RECOMMENDED} — {CONTEXT_REASON_LABELS[group.context_reason] ?? ''}
                  </span>
                )}
              </div>

              {published && (
                <div className="maintenance-published-banner" role="status" data-testid="published-banner">
                  <CheckCircle2 size={18} />
                  <div>
                    <strong>Published</strong>
                    <span>{code} · {publication.published_at ? formatMaintenanceDate(publication.published_at) : 'published'} · {publication.supporting_page_count} supporting page{publication.supporting_page_count === 1 ? '' : 's'}</span>
                    <small>Find it under Maintenance → Error Codes. Changing published knowledge needs a separate update review.</small>
                  </div>
                </div>
              )}

              <p><small>Each row below is one detected candidate. Several can support the same machine error code, from different places in the manual. {published ? '' : 'Choose ONE canonical occurrence, then write the canonical knowledge. The rest stay as supporting page references.'}</small></p>
              {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}
              <ul className="incident-narrative-list">
                {group.occurrences.map((occurrence) => (
                  <OccurrenceRow
                    key={occurrence.id}
                    occurrence={occurrence}
                    canManage={canManage}
                    busy={busyId !== null}
                    isCanonical={occurrence.id === (published ? publication.canonical_candidate_id : canonicalId)}
                    isSuggested={occurrence.id === publication?.suggested_canonical_candidate_id}
                    canChooseCanonical={!published}
                    onUseCanonical={(o) => { setCanonicalId(o.id); setLastPreview(null) }}
                    onApprove={(o) => run(o, () => updateKnowledgeEntry(o.id, { status: 'APPROVED' }))}
                    onReject={(o) => run(o, () => updateKnowledgeEntry(o.id, { status: 'REJECTED' }))}
                    onRestore={(o) => run(o, () => bulkReviewEntries(importId, { entryIds: [o.id], action: 'restore' }))}
                  />
                ))}
              </ul>
              {group.occurrences_truncated && <small>Showing the first {group.occurrences.length} occurrences.</small>}
              {busyId && <small><LoaderCircle className="spin" size={13} /> Saving…</small>}

              <section className="maintenance-supporting-evidence" aria-label="Supporting evidence">
                <div className="form-section-heading"><strong>Supporting evidence</strong><span>{publication?.supporting_occurrence_count ?? 0} occurrence{publication?.supporting_occurrence_count === 1 ? '' : 's'}{publication?.excluded_rejected_count > 0 ? ` · ${publication.excluded_rejected_count} rejected (not used)` : ''}</span></div>
                <p data-testid="supporting-pages">{supportingPagesLabel(publication?.supporting_pages, publication?.supporting_page_count ?? 0)}</p>
                <small>Each unique page becomes a page reference when this code is published. Reject noise (for example table-of-contents lines) first if it should not be cited.</small>
              </section>

              <SourcePageContext importId={importId} code={code} sourcePages={group.source_pages} truncated={group.source_pages_truncated} />

              {canManage && !published && (
                <>
                  <CanonicalEditor code={code} draft={draft} onChange={(next) => { setDraft(next); setLastPreview(null) }} canonicalOccurrence={canonicalOccurrence} publication={publication} errors={errors} showErrors={showErrors} />
                  {showErrors && errors.canonical && <div className="form-error" role="alert"><span>{errors.canonical}</span></div>}
                  <div className="dialog-actions">
                    <button className="primary-button" type="button" onClick={requestPublishReview}><Rocket size={16} /> Review Publish</button>
                    <small>Opens a read-only preview first. Nothing is published until you confirm there.</small>
                  </div>
                </>
              )}
              {!canManage && <small>Read-only access: reviewing and publishing need a manager role.</small>}
            </>
          )}
      </div>
      {showPublish && (
        <GroupPublishDialog importId={importId} code={code} canonicalId={canonicalId} draft={draft} onClose={() => setShowPublish(false)} onPreviewed={setLastPreview} onPublished={handlePublished} />
      )}
    </BlockingDialog>
  )
}
