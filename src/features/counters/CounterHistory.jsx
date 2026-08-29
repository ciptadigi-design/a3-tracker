import { useState } from 'react'
import { Clock3, FilePenLine, History, RefreshCcw } from 'lucide-react'
import { CorrectCounterDialog } from './CorrectCounterDialog.jsx'
import { formatCounter, formatUsage } from './counterUtils.js'
import { userErrorMessage } from '../../lib/appErrors.js'

function formatObservedAt(value, timezone) {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: timezone,
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

export function CounterHistory({ history, profiles, currentUserId, timezone, isLoading, error, canCorrect, onRefresh, onCorrected }) {
  const [correctingReading, setCorrectingReading] = useState(null)
  const latestEffective = history.find((reading) => reading.status === 'effective')
  const profileNames = new Map(profiles.map((profile) => [profile.user_id, profile.display_name]))

  function enteredBy(reading) {
    if (reading.entered_by === currentUserId) return 'You'
    return profileNames.get(reading.entered_by) || 'Team member'
  }

  return (
    <section className="counter-history-card glass-surface">
      <header className="history-header"><div><span className="card-kicker">Counter history</span><h2>Effective and corrected readings</h2><p>Usage is calculated by PostgreSQL from each reading's linked previous effective value.</p></div><button className="icon-button" type="button" onClick={onRefresh} disabled={isLoading} aria-label="Refresh counter history"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></header>
      {isLoading ? <div className="history-state"><RefreshCcw className="spin" size={23} /><strong>Loading counter history…</strong></div>
        : error ? <div className="history-state error-state"><strong>Counter history could not be loaded.</strong><span>{userErrorMessage(error, 'Counter history is temporarily unavailable.')}</span><button className="secondary-button" type="button" onClick={onRefresh}>Try again</button></div>
          : history.length === 0 ? <div className="history-state"><span className="history-empty-icon"><History size={27} /></span><strong>No counter history yet.</strong><span>The first real submission will establish the cumulative baseline.</span></div>
            : <div className="counter-history-list">{history.map((reading) => <article className={`counter-history-row counter-history-${reading.status}`} key={reading.reading_id}>
              <div className="history-time"><Clock3 size={15} /><div><strong>{formatObservedAt(reading.observed_at, timezone)}</strong><span>{reading.shift_code || 'No shift'} · Operator: {reading.operator_name_snapshot || 'Not recorded'}</span><small>Recorded by {enteredBy(reading)}</small></div></div>
              <div className="history-value"><span>Reading</span><strong>{formatCounter(reading.reading_value)}</strong></div>
              <div className="history-value usage"><span>Usage</span><strong>{formatUsage(reading.usage)}</strong></div>
              <div className="history-status"><span className={`reading-status reading-status-${reading.status}`}>{reading.status}</span>{reading.source === 'correction' && <small>Correction</small>}</div>
              {canCorrect && reading.reading_id === latestEffective?.reading_id ? <button className="history-correct-button" type="button" onClick={() => setCorrectingReading(reading)}><FilePenLine size={15} /> Correct latest</button> : <span />}
              {reading.correction_reason && <div className="history-correction-note"><strong>Correction reason</strong><span>{reading.correction_reason}</span></div>}
              {reading.notes && <div className="history-notes">{reading.notes}</div>}
            </article>)}</div>}
      {correctingReading && <CorrectCounterDialog reading={correctingReading} onClose={() => setCorrectingReading(null)} onCorrected={onCorrected} />}
    </section>
  )
}
