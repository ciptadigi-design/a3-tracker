import { useMemo, useState } from 'react'
import { AlertTriangle, BarChart3, CheckCircle2, Sparkles, X, XCircle } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { removalConditionLabels, replacementReasonLabels } from './componentReplacement.js'

const confidenceLabels = { no_data: 'No Data', very_low: 'Very Low', low: 'Low', developing: 'Developing', medium: 'Medium', high: 'High', mature: 'Mature' }
const recommendationLabels = { no_data: 'Waiting for lifecycle samples', insufficient_data: 'Data Belum Cukup', keep_baseline: 'Pertahankan Baseline', review_increase: 'Pertimbangkan Naik', review_decrease: 'Pertimbangkan Turun', high_variability: 'Variasi Tinggi — Tinjau Data', adaptive_disabled: 'Rekomendasi Adaptif Dinonaktifkan' }
const variabilityLabels = { no_data: 'No Data', insufficient: 'Belum cukup data', very_consistent: 'Sangat konsisten', moderate: 'Moderat', high: 'Tinggi', very_high: 'Sangat tinggi' }

const number = (value, digits = 0) => value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: digits })
const signed = (value, suffix = '') => value == null ? '—' : `${Number(value) > 0 ? '+' : ''}${number(value, 1)}${suffix}`
const date = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' }).format(new Date(value)) : '—'

function explanation(intelligence) {
  if (!intelligence.usable_samples) return 'Learning begins automatically after eligible real component replacements are recorded.'
  if (intelligence.recommendation_state === 'adaptive_disabled') return `${intelligence.usable_samples} completed lifecycle sample(s) are visible, but adaptive recommendations are disabled for this profile.`
  if (intelligence.usable_samples < 3) return `${intelligence.usable_samples} usable completed lifecycle sample(s) were observed. At least 3 are required before a formal recommendation is produced.`
  if (intelligence.recommendation_state === 'high_variability') return `${intelligence.usable_samples} usable lifecycles were analyzed, but variability exceeds the 40% safety threshold, so no baseline change is suggested.`
  const direction = Number(intelligence.difference_percent) >= 0 ? 'above' : 'below'
  const threshold = Math.abs(Number(intelligence.difference_percent)) <= 10 ? 'remains inside' : 'exceeds'
  return `${intelligence.usable_samples} usable completed lifecycles were analyzed. Median observed ${intelligence.tracking_method === 'consumption_based' ? 'yield' : 'life'} is ${number(Math.abs(intelligence.difference_percent), 1)}% ${direction} the current baseline and ${threshold} the 10% review threshold. Variability is ${variabilityLabels[intelligence.variability_label]?.toLowerCase()}.`
}

export function ComponentIntelligenceDialog({ intelligence, samples, canManage, onClose, onAdopt }) {
  const [confirming, setConfirming] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [requestId] = useState(() => crypto.randomUUID())
  const consumption = intelligence.tracking_method === 'consumption_based'
  const canAdopt = canManage && intelligence.can_adopt && intelligence.adaptive_enabled
  const profileSamples = useMemo(() => samples.filter((sample) => sample.machine_model_id === intelligence.machine_model_id && sample.slot_code.toLowerCase() === intelligence.slot_code.toLowerCase()), [intelligence, samples])
  const guardrail = intelligence.suggested_baseline != null && Number(intelligence.suggested_baseline) !== Math.round(Number(intelligence.observed_expected_life))

  async function adopt() {
    setBusy(true)
    setError(null)
    try {
      await onAdopt({ intelligence, reason, clientRequestId: requestId })
    } catch (adoptionError) {
      setError(adoptionError)
      setBusy(false)
    }
  }

  if (confirming) return <BlockingDialog className="machine-dialog intelligence-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="intelligence-adopt-title" describedBy="intelligence-adopt-description" onClose={() => setConfirming(false)} busy={busy}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><Sparkles size={20} /></span><div><h2 id="intelligence-adopt-title">Adopt Recommendation</h2><p id="intelligence-adopt-description">This changes only the current workspace profile baseline for future lifecycles.</p></div></div><button className="dialog-close" onClick={() => setConfirming(false)} disabled={busy} aria-label="Close"><X size={19} /></button></header>
    <div className="dialog-body intelligence-adoption-body">
      <div className="intelligence-adoption-summary"><div><span>Current baseline</span><strong>{number(intelligence.current_baseline)}</strong></div><div><span>Observed median</span><strong>{number(intelligence.observed_expected_life)}</strong></div><div><span>Suggested baseline</span><strong>{number(intelligence.suggested_baseline)}</strong></div><div><span>Evidence</span><strong>{intelligence.usable_samples} samples · {confidenceLabels[intelligence.confidence_label]}</strong></div></div>
      {guardrail && <div className="intelligence-guardrail"><AlertTriangle size={17} /><span>The observed estimate exceeds the maximum one-step adjustment. The suggestion is limited to {signed(intelligence.maximum_adjustment_percent, '%')} from the current baseline.</span></div>}
      <label className="form-field form-field-wide"><span>Adoption note <small>optional</small></span><textarea value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} placeholder="Why this recommendation is being adopted" /></label>
      <p className="intelligence-immutability-note">The installed component keeps its original expectation snapshot. The adopted baseline applies when a future lifecycle starts.</p>
      {error && <div className="inline-error"><span>{error.message}</span></div>}
      <div className="dialog-actions"><button className="secondary-button" onClick={() => setConfirming(false)} disabled={busy}>Cancel</button><button className="primary-button" onClick={adopt} disabled={busy}>{busy ? 'Adopting…' : 'Adopt Baseline'}</button></div>
    </div>
  </BlockingDialog>

  return <BlockingDialog className="machine-dialog intelligence-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="intelligence-title" describedBy="intelligence-description" onClose={onClose}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><BarChart3 size={20} /></span><div><span className="card-kicker">Component intelligence · {intelligence.algorithm_version}</span><h2 id="intelligence-title">{intelligence.component_name}</h2><p id="intelligence-description">{intelligence.manufacturer_name} · {intelligence.machine_model_name} · {intelligence.slot_code}</p></div></div><button className="dialog-close" onClick={onClose} aria-label="Close"><X size={19} /></button></header>
    <div className="dialog-body intelligence-body">
      <div className="intelligence-summary-grid"><div><span>Current {consumption ? 'Expected Yield' : 'Baseline'}</span><strong>{number(intelligence.current_baseline)}</strong></div><div><span>Observed Median</span><strong>{number(intelligence.observed_expected_life)}</strong></div><div><span>Difference</span><strong>{signed(intelligence.difference_percent, '%')}</strong></div><div><span>Confidence</span><strong>{intelligence.usable_samples ? `${number(intelligence.confidence_score, 0)} / 100` : 'No Data'}</strong><small>{confidenceLabels[intelligence.confidence_label]}</small></div><div><span>Samples</span><strong>{intelligence.usable_samples} used</strong><small>{intelligence.total_completed_samples} total · {intelligence.outlier_count} outlier</small></div><div><span>Range</span><strong>{intelligence.usable_samples ? `${number(intelligence.minimum_actual_usage)} – ${number(intelligence.maximum_actual_usage)}` : '—'}</strong></div><div><span>Variability</span><strong>{variabilityLabels[intelligence.variability_label]}</strong><small>{intelligence.coefficient_of_variation == null ? '' : `CV ${number(Number(intelligence.coefficient_of_variation) * 100, 1)}%`}</small></div><div><span>Recommendation</span><strong>{recommendationLabels[intelligence.recommendation_state]}</strong></div></div>
      {!intelligence.usable_samples && <div className="intelligence-zero-state"><Sparkles size={20} /><div><strong>No completed lifecycle samples yet.</strong><span>Current baseline: {number(intelligence.current_baseline)} clicks. Learning starts only from eligible real replacements.</span></div></div>}
      {!intelligence.adaptive_enabled && <div className="intelligence-disabled-state"><AlertTriangle size={17} /><span>Adaptive recommendations are disabled for this profile. Historical observations remain readable.</span></div>}
      <div className="intelligence-explanation"><strong>Why?</strong><p>{explanation(intelligence)}</p>{guardrail && <p>The observed estimate exceeds the ±25% one-step guardrail; suggested baseline is limited to {number(intelligence.suggested_baseline)}.</p>}</div>
      {intelligence.suggested_baseline != null && <div className="intelligence-suggestion"><span>Suggested baseline</span><strong>{number(intelligence.suggested_baseline)} clicks</strong>{canAdopt && <button className="primary-button" onClick={() => setConfirming(true)}>Adopt Recommendation</button>}</div>}
      <section className="intelligence-samples"><header><div><span className="card-kicker">Immutable evidence</span><h3>Recent Samples</h3></div><span>{profileSamples.length} recorded</span></header>{profileSamples.length ? <div className="intelligence-sample-list">{profileSamples.map((sample) => <article key={sample.replacement_event_id}><div><strong>{date(sample.replaced_at)} · {sample.machine_code}</strong><span>{sample.machine_name}</span></div><div><strong>{number(sample.actual_usage)} {consumption ? 'yield' : 'clicks'}</strong><span>{sample.performance_percent == null ? 'No install expectation' : `${number(sample.performance_percent, 1)}% performance`}</span></div><div><strong>{replacementReasonLabels[sample.replacement_reason]}</strong><span>{removalConditionLabels[sample.condition_at_removal]}</span></div><span className={sample.is_eligible && !sample.is_outlier ? 'learning-eligible' : 'learning-excluded'}>{sample.is_eligible && !sample.is_outlier ? <CheckCircle2 size={13} /> : <XCircle size={13} />}{!sample.is_eligible ? 'Excluded' : sample.is_outlier ? 'Potential outlier' : 'Used'}</span>{sample.outlier_reason && <small>{sample.outlier_reason}</small>}</article>)}</div> : <div className="replacement-history-empty">No real completed lifecycle samples are available for this model and slot.</div>}</section>
    </div>
  </BlockingDialog>
}
