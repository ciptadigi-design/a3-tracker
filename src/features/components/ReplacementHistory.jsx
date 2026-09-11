import { useState } from 'react'
import { CheckCircle2, ChevronDown, History, X, XCircle } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'
import { Pagination } from '../../components/ui/Pagination.jsx'
import { usePagination } from '../pagination/usePagination.js'
import { removalConditionLabels, replacementReasonLabels } from './componentReplacement.js'
import { inventoryItemLabel } from '../inventory/inventoryItemPresentation.js'
import {
  filterReplacementHistory,
  historyForMachine,
  latestReplacementEvent,
  LEARNING_STATUS_OPTIONS,
  replacementHistoryComponentOptions,
} from './replacementHistoryPresentation.js'

function number(value) {
  return value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 })
}

function dateTime(value) {
  return value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value)) : '—'
}

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })

function ReplacementHistoryCard({ event }) {
  return <details className="replacement-history-card">
    <summary><div><strong>{event.component_name}</strong><span>{dateTime(event.replaced_at)} · {replacementReasonLabels[event.replacement_reason] ?? 'Reason not recorded'}</span></div><div><strong>{number(event.actual_usage)}</strong><span>{event.tracking_method === 'consumption_based' ? 'actual yield' : event.performance_percent == null ? 'performance unavailable' : `${Number(event.performance_percent).toFixed(1)}% performance`}</span></div><span className={event.include_in_adaptive_learning ? 'learning-eligible' : 'learning-excluded'}>{event.include_in_adaptive_learning ? <CheckCircle2 size={13} /> : <XCircle size={13} />}{event.include_in_adaptive_learning ? 'Learning eligible' : 'Excluded'}</span><ChevronDown size={17} /></summary>
    <div className="replacement-history-detail">
      <div><span>Previous lifecycle</span><strong>Installed {number(event.previous_installed_counter)}</strong><p>Removed {number(event.replacement_counter)} · Actual {number(event.actual_usage)} · Expected {number(event.expected_at_install)}</p>{event.realized_lifecycle_cost != null && <small>Realized {idr.format(Number(event.realized_lifecycle_cost))} · {idr.format(Number(event.realized_cost_per_click))} / click</small>}</div>
      <div><span>Replacement event</span><strong>{dateTime(event.replaced_at)}</strong><p>PIC {event.performed_by_name_snapshot ?? 'Not recorded'} · {replacementReasonLabels[event.replacement_reason] ?? 'Reason not recorded'} · {removalConditionLabels[event.condition_at_removal] ?? 'Condition not recorded'}</p>{event.notes && <small>{event.notes}</small>}</div>
      <div><span>New lifecycle</span><strong>Installed {number(event.new_installed_counter)}</strong><p>Expected at install {number(event.new_expected_at_install)} · {event.new_lifecycle_status ?? 'Not recorded'}</p>{event.inventory_source === 'inventory' && event.inventory_cost_is_complete && <small>Cost at installation: {idr.format(Number(event.inventory_consumption_cost))}</small>}</div>
      <div className="replacement-history-inventory"><span>Inventory</span>{event.inventory_source === 'inventory' ? <><strong>Source: Inventory</strong><p>Item: {inventoryItemLabel({ name: event.inventory_item_name, sku: event.inventory_item_sku })} · Location: {event.inventory_location_name} · Quantity: {number(event.inventory_quantity)} {event.inventory_unit} · Movement: Issued</p><p>{event.inventory_cost_is_complete ? `Consumption cost: ${idr.format(Number(event.inventory_consumption_cost))} · ${event.inventory_cost_layer_count} cost layer${event.inventory_cost_layer_count === 1 ? '' : 's'}` : `Consumption cost: Unknown (${number(event.inventory_unknown_cost_quantity)} ${event.inventory_unit} without known basis)`}</p>{event.inventory_cost_receipts && <p>Cost basis: Receipt {event.inventory_cost_receipts} · Purchase {event.inventory_cost_purchases} · Supplier {event.inventory_cost_suppliers}</p>}</> : event.inventory_source === 'external_untracked' ? <><strong>Source: External / Untracked</strong><p>Consumption cost: Unknown / External · Reason: {event.external_inventory_reason}</p></> : <><strong>Source: Not recorded / Legacy</strong><p>No inventory relation or consumption cost exists for this historical event.</p></>}</div>
    </div>
  </details>
}

export function ReplacementHistorySummaryCard({ history, machine, onViewHistory }) {
  const rows = historyForMachine(history, machine?.id)
  const latest = latestReplacementEvent(rows)
  return <section className="replacement-history-section replacement-history-summary" aria-labelledby="replacement-history-title">
    <header><div><span className="card-kicker">Immutable lifecycle history</span><h2 id="replacement-history-title"><History size={18} />Replacement History</h2></div><span>{rows.length} event{rows.length === 1 ? '' : 's'}</span></header>
    {rows.length
      ? <div className="replacement-history-summary-body">
        {latest && <p className="replacement-history-latest"><strong>{latest.component_name}</strong> · {dateTime(latest.replaced_at)} · {replacementReasonLabels[latest.replacement_reason] ?? 'Reason not recorded'}</p>}
        <button className="secondary-button" type="button" onClick={onViewHistory}><History size={15} />View history</button>
      </div>
      : <div className="replacement-history-empty">No component replacements have been recorded for this machine.</div>}
  </section>
}

export function ReplacementHistoryDialog({ history, machine, onClose }) {
  const rows = historyForMachine(history, machine?.id)
  const [componentId, setComponentId] = useState('all')
  const [learningStatus, setLearningStatus] = useState('all')
  const componentOptions = replacementHistoryComponentOptions(rows)
  const filtered = filterReplacementHistory(rows, { componentId, learningStatus })
  const pagination = usePagination(filtered.length, `${machine?.id}:${componentId}:${learningStatus}`)
  const visible = filtered.slice(pagination.start, pagination.end)

  return <BlockingDialog className="machine-dialog replacement-history-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="replacement-history-dialog-title" onClose={onClose}>
    <header className="dialog-header">
      <div className="dialog-heading"><span className="dialog-icon"><History size={22} /></span><div><span className="card-kicker">Immutable lifecycle history</span><h2 id="replacement-history-dialog-title">Replacement History · {machine?.display_name ?? machine?.machine_code}</h2><p>{rows.length} event{rows.length === 1 ? '' : 's'} recorded for this machine.</p></div></div>
      <button className="icon-button" type="button" onClick={onClose} aria-label="Close replacement history" data-dialog-initial-focus><X size={19} /></button>
    </header>
    <div className="machine-form-body replacement-history-dialog-body">
      {rows.length > 0 && <div className="replacement-history-filters">
        <label className="form-field"><span>Component</span><select value={componentId} onChange={(event) => setComponentId(event.target.value)}><option value="all">All components</option>{componentOptions.map((component) => <option key={component.id} value={component.id}>{component.name}</option>)}</select></label>
        <label className="form-field"><span>Status</span><select value={learningStatus} onChange={(event) => setLearningStatus(event.target.value)}>{LEARNING_STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
      </div>}
      {filtered.length
        ? <div className="replacement-history-list">{visible.map((event) => <ReplacementHistoryCard event={event} key={event.replacement_event_id} />)}</div>
        : <div className="replacement-history-empty">{rows.length ? 'No events match the selected filters.' : 'No component replacements have been recorded for this machine.'}</div>}
      {filtered.length > 0 && <Pagination total={filtered.length} {...pagination} onPageChange={pagination.setPage} onPageSizeChange={pagination.setPageSize} label="replacement history events" />}
    </div>
  </BlockingDialog>
}
