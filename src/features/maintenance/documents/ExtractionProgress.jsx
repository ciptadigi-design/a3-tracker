import { AlertCircle } from 'lucide-react'
import { extractionStatusLabels } from '../maintenanceUtils.js'

export function ExtractionProgress({ extraction }) {
  const { status, total_pages: totalPages, processed_pages: processedPages, error_message: errorMessage } = extraction
  const showBar = (status === 'PROCESSING' || status === 'COMPLETED') && totalPages != null
  const percent = showBar && totalPages > 0 ? Math.round((processedPages / totalPages) * 100) : 0

  return (
    <div className="maintenance-extraction-progress">
      <div className="maintenance-extraction-status-row">
        <span className={`maintenance-extraction-badge status-${status.toLowerCase()}`}>{extractionStatusLabels[status] ?? status}</span>
        {status === 'PROCESSING' && <span>Processing PDF…</span>}
      </div>
      {showBar && (
        <>
          <p>Pages: {processedPages} / {totalPages}</p>
          <div className="maintenance-progress-bar"><div className="maintenance-progress-bar-fill" style={{ width: `${percent}%` }} /></div>
        </>
      )}
      {status === 'FAILED' && errorMessage && <small className="field-error"><AlertCircle size={13} />{errorMessage}</small>}
    </div>
  )
}
