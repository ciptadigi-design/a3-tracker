import { AlertCircle, LoaderCircle, Sparkles, X } from 'lucide-react'
import { BlockingDialog } from '../../../components/ui/BlockingDialog.jsx'

export function ExtractionModal({ document, isStarting, startError, onClose, onStart }) {
  return (
    <BlockingDialog className="machine-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="extraction-modal-title" onClose={onClose} busy={isStarting}>
      <header className="dialog-header">
        <div className="dialog-heading"><span className="dialog-icon"><Sparkles size={22} /></span><div><span className="card-kicker">Knowledge extraction</span><h2 id="extraction-modal-title">Extract Knowledge From Document</h2></div></div>
        <button className="icon-button" type="button" onClick={onClose} disabled={isStarting} aria-label="Close"><X size={19} /></button>
      </header>
      <div className="machine-form-body">
        <p><strong>Document:</strong> {document?.title}</p>
        <p>This process extracts text from the PDF and prepares it for knowledge processing.</p>
        <p><small>Status: Background process</small></p>
        {startError && <div className="form-error" role="alert"><AlertCircle size={16} /><span>{startError}</span></div>}
      </div>
      <footer className="dialog-actions form-action-footer">
        <button className="secondary-button" type="button" onClick={onClose} disabled={isStarting}>Cancel</button>
        <button className="primary-button" type="button" onClick={onStart} disabled={isStarting}>{isStarting ? <LoaderCircle className="spin" size={17} /> : <Sparkles size={17} />} {isStarting ? 'Starting…' : 'Start Extraction'}</button>
      </footer>
    </BlockingDialog>
  )
}
