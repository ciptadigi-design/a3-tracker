import { AnimatePresence, motion as Motion, useReducedMotion } from 'motion/react'
import { formatClicks, formatPercentage, formatSignedClicks } from '../clickTargets/clickTargetModel.js'
import { AnimatedNumber } from './AnimatedNumber.jsx'

/**
 * Mirrors Overview's target hero card fields exactly (see
 * targetSummaryFromProjection in liveDisplayModel.js) - no business
 * semantics are recomputed here, only laid out for distance viewing.
 */
export function MonthlyTargetPanel({ summary }) {
  const reducedMotion = useReducedMotion()

  if (!summary) return (
    <section className="live-panel live-target-panel">
      <span className="live-panel-label">Monthly Target</span>
      <p className="live-target-empty">Target not available for this machine.</p>
    </section>
  )

  if (summary.notConfigured) return (
    <section className="live-panel live-target-panel">
      <span className="live-panel-label">Monthly Target</span>
      <p className="live-target-empty">Monthly target not configured.</p>
    </section>
  )

  const percentage = Math.max(0, Math.min(100, summary.achievementPercentage ?? 0))

  return (
    <section className="live-panel live-target-panel">
      <span className="live-panel-label">Monthly Target</span>
      <div className="live-target-actual"><AnimatedNumber value={summary.actual} format={formatClicks} durationMs={700} /></div>
      <div className="live-target-of">/ {formatClicks(summary.periodTarget)}</div>

      <div className="live-target-progress-track">
        <Motion.div
          className="live-target-progress-fill"
          initial={false}
          animate={{ width: `${percentage}%` }}
          transition={{ duration: reducedMotion ? 0 : 0.6, ease: 'easeInOut' }}
        />
      </div>
      <div className="live-target-percentage">{formatPercentage(summary.achievementPercentage)}</div>

      <AnimatePresence mode="wait">
        <Motion.div
          key={summary.statusLabel}
          className={`live-target-status tone-${summary.statusTone}`}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: reducedMotion ? 0 : 0.3 }}
        >
          <span>{summary.statusLabel.toUpperCase()}</span>
          {summary.paceVariance != null && <strong>{formatSignedClicks(summary.paceVariance)}</strong>}
        </Motion.div>
      </AnimatePresence>

      <dl className="live-target-metrics">
        <div><dt>Expected today</dt><dd>{formatClicks(summary.expectedByToday)}</dd></div>
        <div><dt>Remaining</dt><dd>{formatClicks(summary.remaining)}</dd></div>
        <div><dt>Required pace</dt><dd>{summary.requiredPaceValue != null ? `${summary.requiredPaceValue}/day` : summary.requiredPaceHint}</dd></div>
      </dl>
    </section>
  )
}
