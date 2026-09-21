import { useCallback, useEffect, useRef, useState } from 'react'
import { AlertTriangle, CheckCircle2, LoaderCircle, Rocket, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { previewGroupPublish, publishCodeGroup } from '../../../services/maintenance.js'
import { collisionStatusLabels } from '../maintenanceUtils.js'
import { blockedMessage, buildPublishPayload, canConfirmPublish, changedFieldLabels, classifyPublishError, collisionLabel, mutationLines, supportingPagesLabel } from './groupPublishUtils.js'

/**
 * V1.8 - the deliberate publish step for ONE code group. It asks the server for a read-only preview of exactly what
 * would be written, shows it (including create vs update and the fields an update would change), and only after an
 * explicit confirmation - plus an explicit acknowledgement when a catalog record already exists - publishes with the
 * server-issued token. The dialog freezes the canonical occurrence and content it opened with, so a re-render can never
 * change what is being confirmed. There is no bulk variant: one group, one publication.
 */
export function GroupPublishDialog({ importId, code, canonicalId, draft: openedWithDraft, onClose, onPreviewed, onPublished }) {
  const [draft] = useState(() => openedWithDraft)
  const [phase, setPhase] = useState('previewing') // previewing | ready | publishing | done | failed
  const [preview, setPreview] = useState(null)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [acknowledged, setAcknowledged] = useState(false)
  const requestId = useRef(0)
  const payload = buildPublishPayload(canonicalId, draft)

  const loadPreview = useCallback(() => previewGroupPublish(importId, code, buildPublishPayload(canonicalId, draft)), [importId, code, canonicalId, draft])

  // Auto-preview on open (read-only). State is only set from promise callbacks, never synchronously in the effect body.
  useEffect(() => {
    let active = true
    loadPreview()
      .then((data) => { if (active) { setPreview(data); setPhase('ready'); onPreviewed?.(data) } })
      .catch((previewError) => { if (active) { setError(classifyPublishError(previewError)); setPreview(null); setPhase('failed') } })
    return () => { active = false }
  // eslint-disable-next-line react-hooks/exhaustive-deps -- onPreviewed is a stable notification, not an input of the preview
  }, [loadPreview])

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
      onPreviewed?.(data)
    } catch (previewError) {
      if (requestId.current !== request) return
      setError(classifyPublishError(previewError))
      setPreview(null)
      setPhase('failed')
    }
  }

  async function handlePublish() {
    if (!canConfirmPublish({ preview, acknowledgedUpdate: acknowledged, isPublishing: phase === 'publishing' })) return
    setPhase('publishing')
    setError(null)
    try {
      const data = await publishCodeGroup(importId, code, payload, { confirmationToken: preview.confirmation_token, confirmUpdate: acknowledged })
      setResult(data)
      setPhase('done')
      onPublished?.(data)
    } catch (publishError) {
      setError(classifyPublishError(publishError))
      setPhase('failed')
    }
  }

  const busy = phase === 'publishing'
  const isUpdate = Boolean(preview?.requires_update_confirmation)
  const blocked = preview && !preview.can_publish ? blockedMessage(preview.blocked_reason, preview.already_published) : null
  const prov = preview?.provenance

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" role="alertdialog" labelledBy="group-publish-title" describedBy="group-publish-description" onClose={onClose} busy={busy}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><Rocket size={22} /></span><div><span className="card-kicker">Publish knowledge</span><h2 id="group-publish-title">Publish {code}</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={busy} aria-label="Close publish review"><X size={19} /></button>
      </header>
      <div className="machine-form-body">
        <p id="group-publish-description">
          This publishes ONE error code from your reviewed canonical knowledge. The other occurrences of {code} are kept as supporting page references only; they are not published separately.
        </p>

        {phase === 'previewing' && <p role="status"><LoaderCircle className="spin" size={15} /> Checking what would be published…</p>}

        {preview && phase !== 'previewing' && phase !== 'done' && (
          <div className="maintenance-publish-preview" data-testid="publish-preview">
            <dl className="maintenance-publish-facts">
              <div><dt>Code</dt><dd>{preview.normalized_code}</dd></div>
              <div><dt>Title</dt><dd>{preview.proposed.title}</dd></div>
              <div><dt>Canonical source</dt><dd>{preview.canonical_source_page == null ? 'Page unknown' : `Page ${preview.canonical_source_page}`}</dd></div>
              <div><dt>Supporting occurrences</dt><dd>{prov.supporting_occurrence_count} of {prov.occurrence_count}{prov.excluded_rejected_count > 0 ? ` (${prov.excluded_rejected_count} rejected, not used)` : ''}</dd></div>
              <div><dt>Supporting pages</dt><dd>{prov.supporting_page_count} · {supportingPagesLabel(prov.supporting_pages, prov.supporting_page_count)}</dd></div>
              <div><dt>Catalog state</dt><dd>{collisionLabel(preview.collision_status)}{preview.collision_status && preview.collision_status !== 'NEW' ? ` (${collisionStatusLabels[preview.collision_status] ?? preview.collision_status})` : ''}</dd></div>
            </dl>

            <strong>What will be written</strong>
            <ul className="maintenance-publish-mutation">{mutationLines(preview).map((line) => <li key={line}>{line}</li>)}</ul>

            {isUpdate && (
              <div className="form-error" role="alert">
                <AlertTriangle size={16} />
                <span>A catalog record for {preview.normalized_code} already exists{preview.existing?.title ? ` (“${preview.existing.title}”)` : ''}. Publishing will UPDATE it{changedFieldLabels(preview).length > 0 ? `: ${changedFieldLabels(preview).join(', ')} will change` : ''}. Existing solution steps are kept.</span>
              </div>
            )}
            {blocked && <div className="form-error" role="alert"><AlertTriangle size={16} /><span>{blocked}</span></div>}

            {preview.can_publish && isUpdate && (
              <label className="maintenance-select-page-row">
                <input type="checkbox" checked={acknowledged} onChange={(event) => setAcknowledged(event.target.checked)} aria-label="I understand this updates an existing record" />
                <span>I understand this updates the existing catalog record.</span>
              </label>
            )}
          </div>
        )}

        {phase === 'done' && result && (
          <p role="status" className="maintenance-triage-result">
            <CheckCircle2 size={16} />
            {result.published ? `Published ${result.normalized_code} (${result.mode}).` : `${result.normalized_code} was already published with this content. Nothing changed.`}
            {' '}{result.supporting_page_count} supporting page{result.supporting_page_count === 1 ? '' : 's'}.
          </p>
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
            <button className="primary-button" type="button" onClick={handlePublish} disabled={!canConfirmPublish({ preview, acknowledgedUpdate: acknowledged, isPublishing: busy })}>
              {busy && <LoaderCircle className="spin" size={17} />}
              {busy ? 'Publishing…' : 'Publish Knowledge'}
            </button>
          </>
        )}
      </footer>
    </BlockingDialog>
  )
}
