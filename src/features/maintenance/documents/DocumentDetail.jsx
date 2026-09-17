import { useState } from 'react'
import { AlertCircle, ExternalLink, FileText, LoaderCircle, Plus, ShieldAlert, Trash2, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { addDocumentReference, deleteDocumentReference } from '../../../services/maintenance.js'
import { useMachineErrorCodes } from '../useMachineErrorCodes.js'
import { documentStatusLabels, documentTypeLabels, formatMaintenanceDate, mapMaintenanceError } from '../maintenanceUtils.js'
import { useMaintenanceDocument } from './useMaintenanceDocument.js'

function ReferenceForm({ documentId, onCancel, onAdded }) {
  const errorCodesState = useMachineErrorCodes()
  const [errorCodeId, setErrorCodeId] = useState('')
  const [pageNumber, setPageNumber] = useState('')
  const [sectionTitle, setSectionTitle] = useState('')
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    if (!errorCodeId) { setError('Select an error code to link.'); return }
    setIsSaving(true)
    setError(null)
    try {
      const reference = await addDocumentReference(documentId, {
        machine_error_code_id: errorCodeId,
        page_number: pageNumber ? Number(pageNumber) : null,
        section_title: sectionTitle.trim() || null,
      })
      onAdded(reference)
    } catch (submitError) {
      setError(mapMaintenanceError(submitError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <form className="form-grid maintenance-step-form" onSubmit={handleSubmit}>
      <label className="form-field form-field-wide"><span>Error code</span>
        <select value={errorCodeId} onChange={(event) => setErrorCodeId(event.target.value)} disabled={errorCodesState.isLoading}>
          <option value="">Select an error code</option>
          {errorCodesState.errorCodes.map((code) => <option key={code.id} value={code.id}>{code.code} · {code.title}</option>)}
        </select>
      </label>
      <label className="form-field"><span>Page number <small>Optional</small></span><input value={pageNumber} onChange={(event) => setPageNumber(event.target.value)} inputMode="numeric" /></label>
      <label className="form-field"><span>Section title <small>Optional</small></span><input value={sectionTitle} onChange={(event) => setSectionTitle(event.target.value)} placeholder="e.g. Image Adjustment Error" /></label>
      {error && <small className="field-error"><AlertCircle size={13} />{error}</small>}
      <div className="dialog-actions">
        <button className="secondary-button" type="button" onClick={onCancel} disabled={isSaving}>Cancel</button>
        <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={15} /> : null} Link error code</button>
      </div>
    </form>
  )
}

export function DocumentDetail({ documentId, canManage, onClose }) {
  const state = useMaintenanceDocument(documentId)
  const [showReferenceForm, setShowReferenceForm] = useState(false)

  async function handleRemoveReference(referenceId) {
    await deleteDocumentReference(documentId, referenceId)
    state.setDocument((prev) => ({ ...prev, references: prev.references.filter((ref) => ref.id !== referenceId) }))
  }

  function handleAdded(reference) {
    state.setDocument((prev) => ({ ...prev, references: [...(prev.references ?? []), reference] }))
    setShowReferenceForm(false)
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="document-detail-title" onClose={onClose}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><FileText size={22} /></span><div><span className="card-kicker">Document repository</span><h2 id="document-detail-title">{state.document?.title ?? 'Document'}</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button>
      </header>

      <div className="machine-form-body">
        {state.isLoading ? <LoadingScreen label="Loading document" />
          : state.error ? <ErrorState title="Document could not be loaded" detail={userErrorMessage(state.error, 'Try again shortly.')} onRetry={state.refresh} />
          : !state.document ? <ErrorState title="Document not available" detail="It may belong to a different account." />
          : (
            <>
              <p><strong>Type:</strong> {documentTypeLabels[state.document.document_type] ?? state.document.document_type} · <strong>Status:</strong> {documentStatusLabels[state.document.status] ?? state.document.status}{state.document.version && <> · <strong>Version:</strong> {state.document.version}</>}</p>
              {state.document.description && <p>{state.document.description}</p>}
              {state.document.file_path && <p><a href={state.document.file_path} target="_blank" rel="noreferrer"><ExternalLink size={14} /> {state.document.file_name || 'Open file reference'}</a></p>}
              <small>Added {formatMaintenanceDate(state.document.created_at, undefined, { dateOnly: true })}</small>

              <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
                <div className="form-section-heading"><strong>Related error knowledge</strong><span>Error codes this document is a source for.</span></div>
                {(state.document.references ?? []).length === 0 ? (
                  <small>No error code references linked yet.</small>
                ) : (
                  <ul className="incident-narrative-list">
                    {state.document.references.map((reference) => (
                      <li key={reference.id} className="incident-narrative-card glass-surface">
                        <span><FileText size={14} /></span>
                        <div>
                          <strong>{reference.error_code?.code} · {reference.error_code?.title}</strong>
                          {(reference.page_number || reference.section_title) && <p>{reference.section_title}{reference.page_number ? ` (Page ${reference.page_number})` : ''}</p>}
                        </div>
                        {canManage && <button className="icon-button" type="button" onClick={() => handleRemoveReference(reference.id)} aria-label="Remove reference"><Trash2 size={14} /></button>}
                      </li>
                    ))}
                  </ul>
                )}
                {canManage && (showReferenceForm ? (
                  <ReferenceForm documentId={documentId} onCancel={() => setShowReferenceForm(false)} onAdded={handleAdded} />
                ) : (
                  <button className="secondary-button" type="button" onClick={() => setShowReferenceForm(true)}><Plus size={16} /> Link error code</button>
                ))}
              </div>

              {!canManage && <div className="permission-banner"><ShieldAlert size={18} /><span>Read-only access.</span></div>}
            </>
          )}
      </div>
    </BlockingDialog>
  )
}
