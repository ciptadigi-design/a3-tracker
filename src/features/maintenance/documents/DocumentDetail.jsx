import { useState } from 'react'
import { AlertCircle, Download, ExternalLink, Eye, FileText, LoaderCircle, ShieldAlert, Trash2, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { deleteMaintenanceDocumentFile, maintenanceDocumentFileUrl, uploadMaintenanceDocumentFile } from '../../../services/maintenance.js'
import { documentStatusLabels, documentTypeLabels, formatFileSize, formatMaintenanceDate, mapMaintenanceError } from '../maintenanceUtils.js'
import { PdfUploadField } from './PdfUploadField.jsx'
import { useMaintenanceDocument } from './useMaintenanceDocument.js'

// Same green/neutral/red convention as the rest of the Documents UI.
const documentStatusPillClass = { DRAFT: '', PUBLISHED: 'resolved', ARCHIVED: 'voided' }

// Maintenance Clean Slate Phase 1: this dialog is now scoped to the original-PDF
// lifecycle only (view/download/upload/replace/delete the file). It no longer exposes
// extraction, knowledge imports, code groups, review/publish, benchmark, or document
// references - those API entry points are no longer routed at all (see
// backend/routes/api.php); the underlying tables/services/data are untouched, and this
// UI is simply no longer the place that reaches them.
//
// Quick View PDF/Download actions live in the document header (they're the primary
// per-document actions, and every viewer benefits from them being easy to find).
// This section handles everything else: the initial upload when no file exists yet,
// and replace/delete for managers once one does - it stays out of the way (renders
// nothing) for a viewer once a file already exists, rather than repeating a second,
// visually heavier copy of the same file card the header already covers.
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

  if (document.storage_disk) {
    if (!canManage) return null
    return (
      <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
        <div className="form-section-heading"><strong>Document file</strong><span>{document.file_name || 'document.pdf'} · {formatFileSize(document.file_size)}</span></div>
        <PdfUploadField file={selectedFile} onFileSelected={setSelectedFile} disabled={isUploading} />
        {error && <small className="field-error"><AlertCircle size={13} />{error}</small>}
        <div className="dialog-actions" style={{ marginTop: 10 }}>
          {selectedFile && <button className="primary-button" type="button" onClick={handleUpload} disabled={isUploading}>{isUploading ? <LoaderCircle className="spin" size={16} /> : null} {isUploading ? 'Uploading…' : 'Replace PDF'}</button>}
          <button className="icon-button" type="button" onClick={handleDeleteFile} aria-label="Delete PDF"><Trash2 size={15} /></button>
        </div>
      </div>
    )
  }

  if (document.file_path) {
    return (
      <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
        <div className="form-section-heading"><strong>Document file</strong><span>External file reference.</span></div>
        <p><a href={document.file_path} target="_blank" rel="noreferrer"><ExternalLink size={14} /> {document.file_name || 'Open file reference'}</a></p>
      </div>
    )
  }

  return (
    <div className="maintenance-step-section" style={{ margin: '18px 0 0', paddingTop: '16px' }}>
      <div className="form-section-heading"><strong>Document file</strong><span>Upload the PDF for this document.</span></div>
      {canManage ? (
        <>
          <PdfUploadField file={selectedFile} onFileSelected={setSelectedFile} disabled={isUploading} />
          {error && <small className="field-error"><AlertCircle size={13} />{error}</small>}
          {selectedFile && <button className="primary-button" type="button" onClick={handleUpload} disabled={isUploading} style={{ marginTop: 10 }}>{isUploading ? <LoaderCircle className="spin" size={16} /> : null} {isUploading ? 'Uploading…' : 'Upload PDF'}</button>}
        </>
      ) : (
        <p className="maintenance-compact-empty"><FileText size={16} /> No PDF uploaded yet.</p>
      )}
    </div>
  )
}

export function DocumentDetail({ documentId, canManage, onClose }) {
  const state = useMaintenanceDocument(documentId)

  return (
    <BlockingDialog className="machine-dialog glass-surface document-detail-dialog" backdropClassName="machine-dialog-backdrop" labelledBy="document-detail-title" onClose={onClose}>
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
              <div className="document-header">
                <div className="document-header-badges">
                  <span className="incident-status-pill info">{documentTypeLabels[state.document.document_type] ?? state.document.document_type}</span>
                  <span className={`incident-status-pill ${documentStatusPillClass[state.document.status] ?? ''}`}>{documentStatusLabels[state.document.status] ?? state.document.status}</span>
                </div>
                {state.document.storage_disk && (
                  <div className="document-header-actions">
                    <a className="secondary-button" href={maintenanceDocumentFileUrl(state.document.id, { inline: true })} target="_blank" rel="noreferrer"><Eye size={15} /> View PDF</a>
                    <a className="secondary-button" href={maintenanceDocumentFileUrl(state.document.id)}><Download size={15} /> Download</a>
                  </div>
                )}
              </div>

              <div className="document-meta-grid">
                <div><span>Manufacturer</span><strong>{state.document.manufacturer?.name ?? '—'}</strong></div>
                <div><span>Machine model</span><strong>{state.document.machine_model?.name ?? '—'}</strong></div>
                <div><span>File size</span><strong>{formatFileSize(state.document.file_size)}</strong></div>
                <div><span>Uploaded</span><strong>{formatMaintenanceDate(state.document.uploaded_at, undefined, { dateOnly: true })}</strong></div>
                <div><span>Updated</span><strong>{formatMaintenanceDate(state.document.updated_at, undefined, { dateOnly: true })}</strong></div>
                {state.document.version && <div><span>Version</span><strong>{state.document.version}</strong></div>}
              </div>
              {state.document.description && <p>{state.document.description}</p>}

              <DocumentFileSection document={state.document} canManage={canManage} onChanged={(updated) => state.setDocument((prev) => ({ ...prev, ...updated }))} />

              {!canManage && <div className="permission-banner"><ShieldAlert size={18} /><span>Read-only access.</span></div>}
            </>
          )}
      </div>
    </BlockingDialog>
  )
}
