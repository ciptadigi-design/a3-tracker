import { AlertTriangle, CheckCircle2, Clock, LoaderCircle, Sparkles } from 'lucide-react'
import { extractionErrorMessages, extractionStatusLabels, formatMaintenanceDate, permanentExtractionErrorCodes } from '../maintenanceUtils.js'

// State-specific "Knowledge Extraction" card covering the full NONE -> PENDING ->
// PROCESSING -> COMPLETED/FAILED lifecycle in one place, so DocumentDetail only has
// to decide *when* the Extracted Content section appears (COMPLETED only), not how
// each intermediate state should look.
export function ExtractionProgress({ extraction, canManage, isStarting, onStart, onReExtract }) {
  const status = extraction?.status ?? 'NONE'

  if (status === 'NONE') {
    return (
      <div className="extraction-card extraction-card-none">
        <div className="extraction-card-icon"><Sparkles size={18} /></div>
        <div className="extraction-card-body">
          <strong>No extracted knowledge yet</strong>
          <p>Extract this PDF's text so it can be reviewed and turned into structured maintenance knowledge.</p>
          {canManage && (
            <button className="primary-button" type="button" onClick={onStart} disabled={isStarting}>
              {isStarting ? <LoaderCircle className="spin" size={16} /> : <Sparkles size={16} />} Extract Knowledge
            </button>
          )}
        </div>
      </div>
    )
  }

  if (status === 'PENDING') {
    return (
      <div className="extraction-card extraction-card-pending">
        <div className="extraction-card-icon"><Clock size={18} /></div>
        <div className="extraction-card-body">
          <div className="extraction-card-heading-row"><strong>Waiting for extraction worker</strong><span className="maintenance-extraction-badge status-pending">{extractionStatusLabels.PENDING}</span></div>
          <p>Extraction has been queued and will start shortly.</p>
        </div>
      </div>
    )
  }

  if (status === 'PROCESSING') {
    const totalPages = extraction.total_pages
    const processedPages = extraction.processed_pages ?? 0
    const showDeterminate = totalPages != null && totalPages > 0
    const percent = showDeterminate ? Math.round((processedPages / totalPages) * 100) : 0

    return (
      <div className="extraction-card extraction-card-processing">
        <div className="extraction-card-icon"><LoaderCircle className="spin" size={18} /></div>
        <div className="extraction-card-body">
          <div className="extraction-card-heading-row"><strong>Extracting PDF</strong><span className="maintenance-extraction-badge status-processing">{extractionStatusLabels.PROCESSING}</span></div>
          {showDeterminate ? (
            <>
              <p>Pages: {processedPages} / {totalPages}</p>
              <div className="maintenance-progress-bar"><div className="maintenance-progress-bar-fill" style={{ width: `${percent}%` }} /></div>
            </>
          ) : (
            <>
              <p>Reading document structure…</p>
              <div className="maintenance-progress-bar maintenance-progress-bar-indeterminate"><div className="maintenance-progress-bar-fill-indeterminate" /></div>
            </>
          )}
        </div>
      </div>
    )
  }

  if (status === 'COMPLETED') {
    const totalPages = extraction.total_pages ?? 0
    return (
      <div className="extraction-card extraction-card-completed">
        <div className="extraction-card-icon"><CheckCircle2 size={18} /></div>
        <div className="extraction-card-body">
          <div className="extraction-card-heading-row"><strong>Extraction complete</strong><span className="maintenance-extraction-badge status-completed">{extractionStatusLabels.COMPLETED}</span></div>
          <p>{totalPages} page{totalPages === 1 ? '' : 's'} extracted{extraction.completed_at ? ` · ${formatMaintenanceDate(extraction.completed_at)}` : ''}</p>
          {canManage && (
            <button className="secondary-button" type="button" onClick={onReExtract} disabled={isStarting}>
              {isStarting ? <LoaderCircle className="spin" size={15} /> : <Sparkles size={15} />} Re-extract
            </button>
          )}
        </div>
      </div>
    )
  }

  // FAILED
  const errorCode = extraction.error_code
  const isPermanent = permanentExtractionErrorCodes.includes(errorCode)
  const explanation = extractionErrorMessages[errorCode] ?? extraction.error_message ?? 'Extraction failed due to an unexpected error.'

  return (
    <div className="extraction-card extraction-card-failed">
      <div className="extraction-card-icon"><AlertTriangle size={18} /></div>
      <div className="extraction-card-body">
        <div className="extraction-card-heading-row"><strong>Extraction failed</strong><span className="maintenance-extraction-badge status-failed">{extractionStatusLabels.FAILED}</span></div>
        <p>{explanation}</p>
        {canManage && !isPermanent && (
          <button className="secondary-button" type="button" onClick={onStart} disabled={isStarting}>
            {isStarting ? <LoaderCircle className="spin" size={15} /> : <Sparkles size={15} />} Retry Extraction
          </button>
        )}
      </div>
    </div>
  )
}
