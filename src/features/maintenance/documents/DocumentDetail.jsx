import { useState } from 'react'
import { AlertCircle, ClipboardList, Download, ExternalLink, Eye, FileText, LoaderCircle, Plus, Sparkles, ShieldAlert, Trash2, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { addDocumentReference, createDocumentImport, deleteDocumentReference, deleteMaintenanceDocumentFile, maintenanceDocumentFileUrl, uploadMaintenanceDocumentFile } from '../../../services/maintenance.js'
import { useMachineCatalog } from '../../machines/useMachineCatalog.js'
import { useMachineErrorCodes } from '../useMachineErrorCodes.js'
import { documentStatusLabels, documentTypeLabels, formatFileSize, formatMaintenanceDate, mapMaintenanceError } from '../maintenanceUtils.js'
import { KnowledgeImportDetail } from '../knowledge-import/KnowledgeImportDetail.jsx'
import { KnowledgeImportList } from '../knowledge-import/KnowledgeImportList.jsx'
import { useKnowledgeImports } from '../knowledge-import/useKnowledgeImports.js'
import { ExtractedPagesViewer } from './ExtractedPagesViewer.jsx'
import { ExtractionModal } from './ExtractionModal.jsx'
import { ExtractionProgress } from './ExtractionProgress.jsx'
import { PdfUploadField } from './PdfUploadField.jsx'
import { useDocumentExtraction } from './useDocumentExtraction.js'
import { useMaintenanceDocument } from './useMaintenanceDocument.js'

function DocumentFileSection({ document, canManage, onChanged }) {
  const [selectedFile, setSelectedFile] = useState(null)
  const [isUploading, setIsUploading] = useState(false)
  const [error, setError] = useState(null)

  async function handleUpload() {
    if (!selectedFile || isUploading) return
    setIsUploading(true)
    setError(null)
    try {
      const updated = await uploadMaintenanceDocumentFile(document.id, selectedFile)
      setSelectedFile(null)
      onChanged(updated)
    } catch (uploadError) {
      setError(mapMaintenanceError(uploadError))
    } finally {
      setIsUploading(false)
    }
  }

  async function handleDeleteFile() {
    setError(null)
    try {
      const updated = await deleteMaintenanceDocumentFile(document.id)
      onChanged(updated)
    } catch (deleteError) {
      setError(mapMaintenanceError(deleteError))
    }
  }

  return (
    <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
      <div className="form-section-heading"><strong>Document file</strong><span>The stored PDF for this document.</span></div>

      {document.storage_disk ? (
        <div className="incident-narrative-card glass-surface">
          <span><FileText size={16} /></span>
          <div>
            <strong>{document.file_name || 'document.pdf'}</strong>
            <p>Size: {formatFileSize(document.file_size)}</p>
            <small>Uploaded {formatMaintenanceDate(document.uploaded_at, undefined, { dateOnly: true })}</small>
          </div>
          <div className="dialog-actions">
            <a className="secondary-button" href={maintenanceDocumentFileUrl(document.id, { inline: true })} target="_blank" rel="noreferrer"><Eye size={15} /> View PDF</a>
            <a className="secondary-button" href={maintenanceDocumentFileUrl(document.id)}><Download size={15} /> Download</a>
            {canManage && <button className="icon-button" type="button" onClick={handleDeleteFile} aria-label="Delete PDF"><Trash2 size={15} /></button>}
          </div>
        </div>
      ) : document.file_path ? (
        <p><a href={document.file_path} target="_blank" rel="noreferrer"><ExternalLink size={14} /> {document.file_name || 'Open file reference'}</a></p>
      ) : (
        <p className="machine-empty-state" style={{ minHeight: 0, padding: '18px' }}>No PDF uploaded yet</p>
      )}

      {canManage && !document.storage_disk && (
        <>
          <PdfUploadField file={selectedFile} onFileSelected={setSelectedFile} disabled={isUploading} />
          {error && <small className="field-error"><AlertCircle size={13} />{error}</small>}
          {selectedFile && <button className="primary-button" type="button" onClick={handleUpload} disabled={isUploading} style={{ marginTop: 10 }}>{isUploading ? <LoaderCircle className="spin" size={16} /> : null} {isUploading ? 'Uploading…' : 'Upload PDF'}</button>}
        </>
      )}
    </div>
  )
}

function ExtractionSection({ document, canManage }) {
  const { extraction, isLoading, start, isStarting, startError } = useDocumentExtraction(document.id)
  const [showModal, setShowModal] = useState(false)
  const hasExtraction = Boolean(extraction) && extraction.status !== 'NONE'
  const isCompleted = extraction?.status === 'COMPLETED'
  const canRetry = extraction?.status === 'FAILED'

  async function handleStart() {
    await start()
    setShowModal(false)
  }

  return (
    <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
      <div className="form-section-heading"><strong>Extracted content</strong><span>Text extracted from the PDF, prepared for future knowledge processing.</span></div>

      {isLoading ? <small>Loading extraction status…</small> : !hasExtraction ? (
        canManage && <button className="secondary-button" type="button" onClick={() => setShowModal(true)}><Sparkles size={16} /> Extract Knowledge</button>
      ) : (
        <>
          <ExtractionProgress extraction={extraction} />
          {canManage && canRetry && <button className="secondary-button" type="button" onClick={() => setShowModal(true)} style={{ marginTop: 10 }}><Sparkles size={16} /> Retry Extraction</button>}
        </>
      )}

      {isCompleted && (
        <div style={{ marginTop: 14 }}>
          <ExtractedPagesViewer documentId={document.id} />
        </div>
      )}

      {showModal && <ExtractionModal document={document} isStarting={isStarting} startError={startError} onClose={() => setShowModal(false)} onStart={handleStart} />}
    </div>
  )
}

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

function CreateImportForm({ documentId, accountId, onCancel, onCreated }) {
  const catalog = useMachineCatalog(accountId, true)
  const [machineModelId, setMachineModelId] = useState('')
  const [error, setError] = useState(null)
  const [isSaving, setIsSaving] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    if (isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      const created = await createDocumentImport({ document_id: documentId, machine_model_id: machineModelId || null })
      onCreated(created)
    } catch (submitError) {
      setError(mapMaintenanceError(submitError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <form className="form-grid maintenance-step-form" onSubmit={handleSubmit}>
      <label className="form-field form-field-wide"><span>Machine model <small>Optional</small></span>
        <select value={machineModelId} onChange={(event) => setMachineModelId(event.target.value)} disabled={catalog.isLoading}>
          <option value="">Not specified</option>
          {catalog.models.map((model) => <option key={model.id} value={model.id}>{model.name}</option>)}
        </select>
      </label>
      {error && <small className="field-error"><AlertCircle size={13} />{error}</small>}
      <div className="dialog-actions">
        <button className="secondary-button" type="button" onClick={onCancel} disabled={isSaving}>Cancel</button>
        <button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? <LoaderCircle className="spin" size={15} /> : null} Create knowledge import</button>
      </div>
    </form>
  )
}

export function DocumentDetail({ documentId, canManage, onClose }) {
  const state = useMaintenanceDocument(documentId)
  const importsState = useKnowledgeImports({ documentId })
  const [showReferenceForm, setShowReferenceForm] = useState(false)
  const [showCreateImport, setShowCreateImport] = useState(false)
  const [openImportId, setOpenImportId] = useState(null)

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
              <small>Added {formatMaintenanceDate(state.document.created_at, undefined, { dateOnly: true })}</small>

              <DocumentFileSection document={state.document} canManage={canManage} onChanged={(updated) => state.setDocument((prev) => ({ ...prev, ...updated }))} />

              {/* V1.5: PDF -> per-page text extraction (this section). Turning that
                  extracted text into maintenance_knowledge_entries (AI-assisted or
                  manual) stays a future phase, same as V1.3's import workflow was
                  built before any OCR/AI step. */}
              <ExtractionSection document={state.document} canManage={canManage} />

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

              <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
                <div className="form-section-heading"><strong>Knowledge imports</strong><span>Turn this document into structured maintenance knowledge, then review and publish it.</span></div>
                {importsState.isLoading ? <small>Loading import sessions…</small> : <KnowledgeImportList imports={importsState.imports} onOpen={(item) => setOpenImportId(item.id)} />}
                {canManage && (showCreateImport ? (
                  <CreateImportForm documentId={documentId} accountId={state.document.account_id} onCancel={() => setShowCreateImport(false)} onCreated={(created) => { importsState.refresh(); setShowCreateImport(false); setOpenImportId(created.id) }} />
                ) : (
                  <button className="secondary-button" type="button" onClick={() => setShowCreateImport(true)}><ClipboardList size={16} /> Import Knowledge</button>
                ))}
              </div>

              {!canManage && <div className="permission-banner"><ShieldAlert size={18} /><span>Read-only access.</span></div>}
            </>
          )}
      </div>

      {openImportId && <KnowledgeImportDetail importId={openImportId} canManage={canManage} onClose={() => { setOpenImportId(null); importsState.refresh() }} />}
    </BlockingDialog>
  )
}
