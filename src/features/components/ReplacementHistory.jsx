import { CheckCircle2, ChevronDown, History, XCircle } from 'lucide-react'
import { removalConditionLabels, replacementReasonLabels } from './componentReplacement.js'

function number(value) {
  return value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

function dateTime(value) {
  return value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value)) : '—'
}

export function ReplacementHistory({ history, machine }) {
  const rows = history.filter((event) => event.machine_id === machine?.id)
  return <section className="replacement-history-section" aria-labelledby="replacement-history-title">
    <header><div><span className="card-kicker">Immutable lifecycle history</span><h2 id="replacement-history-title"><History size={18} />Replacement History</h2></div><span>{rows.length} event{rows.length === 1 ? '' : 's'}</span></header>
    {rows.length ? <div className="replacement-history-list">{rows.map((event) => <details className="replacement-history-card" key={event.replacement_event_id}><summary><div><strong>{event.component_name}</strong><span>{dateTime(event.replaced_at)} · {replacementReasonLabels[event.replacement_reason]}</span></div><div><strong>{number(event.actual_usage)}</strong><span>{event.tracking_method === 'consumption_based' ? 'actual yield' : `${Number(event.performance_percent).toFixed(1)}% performance`}</span></div><span className={event.include_in_adaptive_learning ? 'learning-eligible' : 'learning-excluded'}>{event.include_in_adaptive_learning ? <CheckCircle2 size={13} /> : <XCircle size={13} />}{event.include_in_adaptive_learning ? 'Learning eligible' : 'Excluded'}</span><ChevronDown size={17} /></summary><div className="replacement-history-detail"><div><span>Previous lifecycle</span><strong>Installed {number(event.previous_installed_counter)}</strong><p>Removed {number(event.replacement_counter)} · Actual {number(event.actual_usage)} · Expected {number(event.expected_at_install)}</p></div><div><span>Replacement event</span><strong>{dateTime(event.replaced_at)}</strong><p>PIC {event.performed_by_name_snapshot} · {replacementReasonLabels[event.replacement_reason]} · {removalConditionLabels[event.condition_at_removal]}</p>{event.notes && <small>{event.notes}</small>}</div><div><span>New lifecycle</span><strong>Installed {number(event.new_installed_counter)}</strong><p>Expected at install {number(event.new_expected_at_install)} · {event.new_lifecycle_status}</p></div></div></details>)}</div> : <div className="replacement-history-empty">No component replacements have been recorded for this machine.</div>}
  </section>
}
