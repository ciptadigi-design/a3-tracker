import { formatClicks, formatSignedClicks } from '../clickTargets/clickTargetModel.js'
import { formatCounter } from '../counters/counterUtils.js'
import { AnimatedNumber } from './AnimatedNumber.jsx'

function formatReadingTime(value, timezone) {
  if (!value) return null
  return new Intl.DateTimeFormat('en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

/**
 * The single largest number on the display. Operator/shift metadata is
 * omitted entirely when unavailable rather than inventing a placeholder -
 * same rule CounterHistory.jsx already follows ("Not recorded").
 */
export function CurrentCounterPanel({ latestReading, todayActual, timezone }) {
  const counterValue = latestReading ? Number(latestReading.reading_value) : null
  const readingTime = formatReadingTime(latestReading?.observed_at, timezone)
  const shift = latestReading?.shift_code
  const operator = latestReading?.operator_name_snapshot

  return (
    <section className="live-panel live-current-counter-panel">
      <span className="live-panel-label">Current Counter</span>
      <div className="live-current-counter-value">
        <AnimatedNumber value={counterValue} format={formatClicks} durationMs={850} />
      </div>
      {todayActual != null && (
        <div className="live-current-counter-today">
          <AnimatedNumber value={todayActual} format={(value) => formatSignedClicks(value)} durationMs={700} />
          <span>TODAY</span>
        </div>
      )}
      {latestReading ? (
        <div className="live-current-counter-meta">
          <span>Last reading {readingTime}</span>
          {(shift || operator) && <span>{[shift, operator].filter(Boolean).join(' · ')}</span>}
          {latestReading.usage != null && <span className="live-current-counter-usage">+{formatCounter(latestReading.usage)} since previous</span>}
        </div>
      ) : (
        <div className="live-current-counter-meta"><span>No counter readings recorded yet.</span></div>
      )}
    </section>
  )
}
