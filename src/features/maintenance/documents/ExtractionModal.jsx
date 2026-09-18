import { AlertCircle, LoaderCircle, Sparkles, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'

// An extraction already in flight (or a permanent FAILED that offers no Retry
// action) must never be allowed to start a duplicate one - the backend already
// rejects this with a 409, but the modal guards it client-side too rather than
// relying solely on that round trip.
const BLOCKING_STATUSES = ['PENDING', 'PROCESSING']

export function ExtractionModal({ document, extraction, isStarting, startError, onClose, onStart }) {
  const isBlocked = BLOCKING_STATUSES.includes(extraction?.status)

  return (
    <BlockingDialog className="machine-dialog glass-surface extraction-modal" backdropClassName="machine-dialog-backdrop" labelledBy="extraction-modal-title" onClose={onClose} busy={isStarting}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><Sparkles size={22} /></span><div><span className="card-kicker">Knowledge extraction</span><h2 id="extraction-modal-title">Extract Knowledge</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isStarting} aria-label="Close"><X size={19} /></button>
      </header>
      <div className="machine-form-body">
        <p><strong>Document:</strong> {document?.title}</p>
        <p>The PDF will be processed in the background. You can leave this page while extraction runs.</p>
        <p><small>Larger documents take longer to process - there is no fixed completion time.</small></p>
        {isBlocked && <div className="form-error" role="alert"><AlertCircle size={16} /><span>An extraction is already {extraction.status === 'PENDING' ? 'queued' : 'running'} for this document.</span></div>}
        {startError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{startError}</span></div>}
      </div>
      <footer className="dialog-actions form-action-footer">
        <button className="secondary-button" type="button" onClick={onClose} disabled={isStarting}>Cancel</button>
        <button className="primary-button" type="button" onClick={onStart} disabled={isStarting || isBlocked}>{isStarting ? <LoaderCircle className="spin" size={17} /> : <Sparkles size={17} />} {isStarting ? 'Starting…' : 'Start Extraction'}</button>
      </footer>
    </BlockingDialog>
  )
}
