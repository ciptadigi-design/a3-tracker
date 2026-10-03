import { AnimatePresence, motion as Motion, useReducedMotion } from 'motion/react'
import { formatClicks, formatSignedClicks } from '../clickTargets/clickTargetModel.js'

function formatRowTime(value, timezone) {
  return new Intl.DateTimeFormat('en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

/**
 * Fixed list, no pagination, no scrolling. Rows carry a stable identity
 * (reading_id) so Motion's layout animation repositions existing rows
 * instead of replaying the whole list when a genuinely new row appears at
 * the top.
 */
export function RecentActivityStrip({ rows, timezone }) {
  const reducedMotion = useReducedMotion()

  return (
    <section className="live-panel live-activity-panel">
      <span className="live-panel-label">Recent Activity</span>
      {rows.length === 0 ? (
        <p className="live-activity-empty">No recent counter submissions.</p>
      ) : (
        <ul className="live-activity-list">
          <AnimatePresence initial={false}>
            {rows.map((row) => (
              <Motion.li
                key={row.id}
                layout={!reducedMotion}
                initial={reducedMotion ? false : { opacity: 0, y: -12 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0 }}
                transition={{ duration: reducedMotion ? 0 : 0.4, ease: 'easeOut' }}
                className="live-activity-row"
              >
                <span className="live-activity-time">{formatRowTime(row.observedAt, timezone)}</span>
                <span className="live-activity-who">{[row.shiftCode, row.operatorName].filter(Boolean).join(' · ') || 'Operator not recorded'}</span>
                <span className="live-activity-usage">{row.usage != null ? formatSignedClicks(row.usage) : '—'}</span>
                <span className="live-activity-counter">{formatClicks(row.counter)}</span>
              </Motion.li>
            ))}
          </AnimatePresence>
        </ul>
      )}
    </section>
  )
}
