import { useState } from 'react'
import { AlertCircle, LoaderCircle, Rocket, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { publishKnowledgeEntry } from '../../../services/maintenance.js'
import { mapMaintenanceError } from '../maintenanceUtils.js'

export function PublishDialog({ entry, onClose, onPublished }) {
  const [error, setError] = useState(null)
  const [isPublishing, setIsPublishing] = useState(false)
  const willCreateErrorCode = Boolean(entry.code)

  async function handleConfirm() {
    if (isPublishing) return
    setIsPublishing(true)
    setError(null)
    try {
      const result = await publishKnowledgeEntry(entry.id)
      onPublished(result)
      onClose()
    } catch (publishError) {
      setError(mapMaintenanceError(publishError))
    } finally {
      setIsPublishing(false)
    }
  }

  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="publish-dialog-title" onClose={onClose} busy={isPublishing}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><Rocket size={22} /></span><div><span className="card-kicker">Knowledge import</span><h2 id="publish-dialog-title">Publish "{entry.title}"</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isPublishing} aria-label="Close"><X size={19} /></button>
      </header>
      <div className="machine-form-body">
        {willCreateErrorCode ? (
          <>
            <p>Publishing this entry will:</p>
            <ul>
              <li>Create or update machine error code <strong>{entry.code}</strong></li>
              {entry.technician_solution && <li>Add a technician solution step</li>}
              <li>Link this document as a reference for that error code{entry.page_reference ? ` (page ${entry.page_reference})` : ''}</li>
            </ul>
          </>
        ) : (
          <p>This entry has no code, so it will be marked published without creating a machine error code - there is no downstream table for uncoded knowledge yet.</p>
        )}
        {error && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{error}</span></div>}
      </div>
      <footer className="dialog-actions form-action-footer">
        <button className="secondary-button" type="button" onClick={onClose} disabled={isPublishing}>Cancel</button>
        <button className="primary-button" type="button" onClick={handleConfirm} disabled={isPublishing}>{isPublishing ? <LoaderCircle className="spin" size={17} /> : <Rocket size={17} />} {isPublishing ? 'Publishing…' : 'Publish'}</button>
      </footer>
    </BlockingDialog>
  )
}
