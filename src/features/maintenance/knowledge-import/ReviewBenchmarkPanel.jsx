import { useState } from 'react'
import { Gauge } from 'lucide-react'
import { useReviewBenchmark } from './useReviewBenchmark.js'

const COHORTS = [
  { value: 'all', label: 'All sessions' },
  { value: 'self_contained_high', label: 'HIGH · SELF_CONTAINED' },
  { value: 'context_recommended_high', label: 'HIGH · CONTEXT_RECOMMENDED' },
]

function fmtSeconds(v) {
  if (v === null || v === undefined) return '—'
  if (v < 60) return `${Math.round(v)}s`

  return `${Math.floor(v / 60)}m ${Math.round(v % 60)}s`
}

function fmtCount(v) {
  return v === null || v === undefined ? '—' : v
}

/**
 * V1.11 - a compact workflow-friction summary, not an analytics module. Never shows a
 * reviewer's identity, and never ranks or compares reviewers - see ReviewBenchmarkSummary's
 * docblock. Medians only; an explicit "Limited sample" note replaces any implied precision on a
 * small sample, which is the expected state until the human benchmark cohort (V1.11) is reviewed.
 */
export function ReviewBenchmarkPanel({ importId }) {
  const [cohort, setCohort] = useState('all')
  const { isLoading, error, summary } = useReviewBenchmark({ importId, cohort })

  return (
    <section className="maintenance-review-benchmark glass-surface" aria-label="Review workflow benchmark" data-testid="review-benchmark-panel">
      <div className="form-section-heading">
        <strong><Gauge size={16} /> Review workflow benchmark</strong>
        <span>How long review-to-publish actually takes for this import - measured, not estimated.</span>
      </div>

      <div className="maintenance-view-tabs" role="tablist" aria-label="Benchmark cohort">
        {COHORTS.map((c) => (
          <button key={c.value} role="tab" type="button" aria-selected={cohort === c.value} className={cohort === c.value ? 'primary-button' : 'secondary-button'} onClick={() => setCohort(c.value)}>
            {c.label}
          </button>
        ))}
      </div>

      {isLoading && <small>Loading benchmark…</small>}
      {error && <div className="form-error" role="alert"><span>{error}</span></div>}

      {summary && (
        <>
          {summary.limited_sample && <small data-testid="limited-sample-note">Limited sample - {summary.sessions_started} session{summary.sessions_started === 1 ? '' : 's'}. Figures below are not statistically reliable yet.</small>}
          <dl className="maintenance-publish-facts" data-testid="review-benchmark-stats">
            <div><dt>Sessions started</dt><dd>{fmtCount(summary.sessions_started)}</dd></div>
            <div><dt>Sessions published</dt><dd>{fmtCount(summary.sessions_published)}</dd></div>
            <div><dt>Median active time to publish</dt><dd>{fmtSeconds(summary.median_active_time_to_publish_seconds)}</dd></div>
            <div><dt>Median active authoring time</dt><dd>{fmtSeconds(summary.median_active_authoring_seconds)}</dd></div>
            <div><dt>Median source interactions</dt><dd>{fmtCount(summary.median_source_interactions)}</dd></div>
            <div><dt>Median authoring edits</dt><dd>{fmtCount(summary.median_authoring_edits)}</dd></div>
            <div><dt>Validation failure rate</dt><dd>{summary.validation_failure_rate === null ? '—' : summary.validation_failure_rate}</dd></div>
            <div><dt>Incomplete sessions</dt><dd>{fmtCount(summary.incomplete_session_count)}</dd></div>
          </dl>
        </>
      )}
    </section>
  )
}
