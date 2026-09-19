import { useState } from 'react'
import { AlertTriangle, LoaderCircle, RotateCcw, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'
import { bulkReviewEntries } from '../../../services/maintenance.js'
import { mapMaintenanceError } from '../maintenanceUtils.js'

// V1.7 - explicit confirmation required before any bulk mutation (Section M).
// Reject is destructive-styled (danger-button, alertdialog role, matching
// RetireMachineDialog's existing convention); restore is a plain reversible
// action. Neither is a browser confirm() - both reuse BlockingDialog so
// focus-trap/inert behavior stays correct.
export function BulkReviewDialog({ importId, entryIds, action, onClose, onReviewed }) {
  const [isSaving, setIsSaving] = useState(false)
  const [error, setError] = useState(null)
  const isReject = action === 'reject'
  const count = entryIds.length

  async function handleConfirm() {
    if (isSaving) return
    setIsSaving(true)
    setError(null)
    try {
      const result = await bulkReviewEntries(importId, { entryIds, action })
      onReviewed(result)
      onClose()
    } catch (submitError) {
      setError(mapMaintenanceError(submitError))
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <BlockingDialog className="confirm-dialog glass-surface" role="alertdialog" labelledBy="bulk-review-dialog-title" describedBy="bulk-review-dialog-description" onClose={onClose} busy={isSaving}>
      <header className="dialog-header">
        <span className={isReject ? 'danger-dialog-icon' : 'dialog-icon'}>{isReject ? <AlertTriangle size={23} /> : <RotateCcw size={23} />}</span>
        <button className="icon-button" type="button" onClick={onClose} disabled={isSaving} aria-label="Close confirmation"><X size={19} /></button>
      </header>
      <h2 id="bulk-review-dialog-title">{isReject ? `Reject ${count} candidate${count === 1 ? '' : 's'}?` : `Restore ${count} candidate${count === 1 ? '' : 's'} to draft?`}</h2>
      <p id="bulk-review-dialog-description">
        {isReject
          ? 'These candidates will remain in the knowledge import and stay fully auditable. They will not be published, and can be restored to draft later.'
          : 'These candidates will return to draft for review. Nothing is published by this action.'}
      </p>
      {error && <div className="form-error" role="alert"><AlertTriangle size={16} /><span>{error}</span></div>}
      <footer className="dialog-actions">
        <button className="secondary-button" type="button" onClick={onClose} disabled={isSaving}>Cancel</button>
        <button className={isReject ? 'danger-button' : 'primary-button'} type="button" onClick={handleConfirm} disabled={isSaving}>
          {isSaving && <LoaderCircle className="spin" size={17} />}
          {isSaving ? 'Working…' : (isReject ? `Reject ${count} candidate${count === 1 ? '' : 's'}` : `Restore ${count} candidate${count === 1 ? '' : 's'}`)}
        </button>
      </footer>
    </BlockingDialog>
  )
}
