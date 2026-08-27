import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertCircle, BarChart3, Boxes, CalendarRange, CheckCircle2, CircleDollarSign, Gauge, Package, Printer, RefreshCcw, ShoppingCart } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { machineCostPeriodPresets, resolveMachineCostPeriod, validMachineCostFilters } from '../features/machineCost/machineCostPeriods.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { loadMachineCostPeriod } from '../services/supabase/machineCost.js'
import { loadMachines } from '../services/supabase/machines.js'

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const number = new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 })

const statusCopy = {
  COMPLETE: ['Complete', 'Known inventory-backed consumption has complete cost evidence.'],
  PARTIAL: ['Partial', 'Known cost is shown, but one or more consumption events have unavailable cost.'],
  NO_CONSUMPTION: ['No consumption', 'Counter evidence is available and no component consumption was recorded.'],
  INSUFFICIENT_COUNTER_DATA: ['Counter evidence incomplete', 'A start or end boundary reading is missing, so clicks and cost per click are unavailable.'],
  NO_DATA: ['No data', 'No counter or component-consumption evidence is available for this period.'],
}

function currency(value) { return idr.format(Number(value ?? 0)) }
function formatNumber(value) { return value == null ? 'Unavailable' : number.format(Number(value)) }

function SummaryCard({ icon, label, value, hint, tone = 'blue' }) {
  const Icon = icon
  return <article className="machine-cost-summary-card glass-surface"><span className={`machine-cost-summary-icon tone-${tone}`}><Icon size={20} /></span><div><span>{label}</span><strong>{value}</strong><small>{hint}</small></div></article>
}

function CostStatus({ summary }) {
  const [label, description] = statusCopy[summary.cost_status] ?? statusCopy.NO_DATA
  const Icon = ['COMPLETE', 'NO_CONSUMPTION'].includes(summary.cost_status) ? CheckCircle2 : AlertCircle
  return <section className={`machine-cost-status status-${summary.cost_status.toLowerCase()}`} aria-live="polite"><Icon size={18} /><div><strong>{label}</strong><span>{description}</span>{summary.total_consumption_events > 0 && <small>{summary.known_consumption_events} of {summary.total_consumption_events} events have known cost · {summary.consumption_event_coverage_percent ?? 0}% event coverage</small>}</div></section>
}

function ComponentBreakdown({ rows, partial }) {
  return <section className="machine-cost-panel glass-surface"><header><div><span className="card-kicker">Composition</span><h2>Component consumption</h2></div>{partial && <span className="machine-cost-partial"><AlertCircle size={13} />Known cost only</span>}</header>
    {!rows.length ? <div className="machine-cost-empty"><Boxes size={24} /><strong>No component consumption in this period.</strong><span>The engine will show replacement consumption here when operational events occur.</span></div> : <div className="machine-cost-breakdown">{rows.map((row) => <article key={row.component_id}><div className="machine-cost-breakdown-label"><strong>{row.component_name}</strong><span>{row.component_category} · {row.total_events} event{row.total_events === 1 ? '' : 's'}{row.unknown_cost_events ? ` · ${row.unknown_cost_events} unknown` : ''}</span></div><div className="machine-cost-bar" role="meter" aria-label={`${row.component_name}: ${row.known_cost_percent}% of known cost`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={Number(row.known_cost_percent)}><span style={{ width: `${Math.min(100, Number(row.known_cost_percent))}%` }} /></div><div><strong>{currency(row.known_consumption_cost)}</strong><span>{Number(row.known_cost_percent).toFixed(2)}%</span></div></article>)}</div>}
  </section>
}

function LifecycleEvidence({ rows }) {
  return <section className="machine-cost-panel glass-surface"><header><div><span className="card-kicker">Analytical evidence</span><h2>Completed lifecycles</h2></div><span className="machine-cost-context-note">Not added to period consumption</span></header>
    {!rows.length ? <div className="machine-cost-empty compact"><RefreshCcw size={22} /><strong>No lifecycle completed in this period.</strong></div> : <div className="machine-cost-lifecycles">{rows.map((row) => <article key={row.lifecycle_id}><div><strong>{row.component_name}</strong><span>{row.slot_code} · {row.component_category}</span></div><dl><div><dt>Installed cost</dt><dd>{row.cost_is_unknown ? 'Unknown' : currency(row.installed_component_cost)}</dd></div><div><dt>Actual clicks / yield</dt><dd>{formatNumber(row.actual_usage)}</dd></div><div><dt>Realized cost / click</dt><dd>{row.realized_cost_per_click == null ? 'Unavailable' : currency(row.realized_cost_per_click)}</dd></div></dl></article>)}</div>}
  </section>
}

export function MachineCostPage() {
  const { user } = useAuth()
  const { account, branch } = useTenant()
  const filterKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch?.id, feature: 'machine-cost-filters', entityId: 'workspace' })
  const { value: filters, setUIState: setFilters } = usePersistentUIState({ uiStateKey: filterKey, initialValue: { machineId: null, preset: 'this_month', customStart: '', customEnd: '' }, validate: validMachineCostFilters })
  const [machines, setMachines] = useState([])
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const selectedMachine = machines.find((machine) => machine.id === filters.machineId) ?? machines[0] ?? null
  const timezone = selectedMachine?.timezone || branch?.timezone || account.default_timezone || 'Asia/Jakarta'
  const resolvedPeriod = useMemo(() => resolveMachineCostPeriod({ preset: filters.preset, timezone, customStart: filters.customStart, customEnd: filters.customEnd }), [filters.customEnd, filters.customStart, filters.preset, timezone])
  const validPeriod = Boolean(resolvedPeriod.start && resolvedPeriod.end && resolvedPeriod.start <= resolvedPeriod.end)

  useEffect(() => {
    let active = true
    setLoading(true); setError(null); setSummary(null); setMachines([])
    loadMachines({ accountId: account.id, branchId: branch?.id })
      .then((rows) => { if (active) setMachines(rows.filter((machine) => machine.is_active)) })
      .catch((loadError) => { if (active) setError(loadError) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [account.id, branch?.id])

  useEffect(() => {
    if (selectedMachine && selectedMachine.id !== filters.machineId) setFilters((current) => ({ ...current, machineId: selectedMachine.id }))
  }, [filters.machineId, selectedMachine, setFilters])

  const refresh = useCallback(async () => {
    if (!selectedMachine || !validPeriod) { setSummary(null); return }
    setLoading(true); setError(null)
    try { setSummary(await loadMachineCostPeriod({ accountId: account.id, machineId: selectedMachine.id, periodStart: resolvedPeriod.start, periodEnd: resolvedPeriod.end })) }
    catch (loadError) { setError(loadError); setSummary(null) }
    finally { setLoading(false) }
  }, [account.id, resolvedPeriod.end, resolvedPeriod.start, selectedMachine, validPeriod])

  useEffect(() => { refresh() }, [refresh])

  const counterHint = summary?.counter_status === 'COMPLETE' ? `${formatNumber(summary.start_counter)} → ${formatNumber(summary.end_counter)}` : 'Boundary evidence unavailable'
  const partial = summary?.consumption_status === 'PARTIAL'

  return <div className="page-stack machine-cost-page">
    <PageHeader eyebrow="Operational economics" title="Machine Cost" description="Component-consumption cost follows physical usage. Purchase timing, inventory balance, and lifecycle performance remain separate evidence." />
    <section className="machine-cost-filters glass-surface" aria-label="Machine cost filters">
      <label><span>Machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => setFilters((current) => ({ ...current, machineId: event.target.value }))} disabled={!machines.length}><option value="">{machines.length ? 'Select machine' : 'No active machines'}</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label>
      <label><span>Period</span><select value={filters.preset} onChange={(event) => setFilters((current) => ({ ...current, preset: event.target.value }))}>{machineCostPeriodPresets.map((preset) => <option value={preset.id} key={preset.id}>{preset.label}</option>)}</select></label>
      {filters.preset === 'custom' && <><label><span>Start date</span><input type="date" value={filters.customStart} onChange={(event) => setFilters((current) => ({ ...current, customStart: event.target.value }))} /></label><label><span>End date</span><input type="date" value={filters.customEnd} min={filters.customStart || undefined} onChange={(event) => setFilters((current) => ({ ...current, customEnd: event.target.value }))} /></label></>}
      <div className="machine-cost-period-readout"><CalendarRange size={16} /><span>{validPeriod ? `${resolvedPeriod.start} → ${resolvedPeriod.end}` : 'Choose a valid date range'}<small>{timezone} operational dates</small></span></div>
      <button className="secondary-button" type="button" onClick={refresh} disabled={loading || !selectedMachine || !validPeriod} aria-label="Refresh machine cost"><RefreshCcw size={15} />Refresh</button>
    </section>

    {error && <div className="inline-error" role="alert">{error.message}</div>}
    {loading ? <div className="machine-loading-state glass-surface"><RefreshCcw className="spin" size={24} /><strong>Loading machine cost evidence…</strong><span>Reading counters, FIFO allocations, and lifecycle facts.</span></div> : !selectedMachine ? <div className="machine-empty-state glass-surface"><span className="empty-machine-icon"><Printer size={38} /></span><h3>No active machine in this branch</h3><p>Add or activate a machine before querying operational component cost.</p></div> : summary && <>
      <CostStatus summary={summary} />
      <section className="machine-cost-summary-grid">
        <SummaryCard icon={Gauge} label="Total Clicks" value={formatNumber(summary.total_clicks)} hint={counterHint} />
        <SummaryCard icon={CircleDollarSign} label={partial ? 'Known Consumption Cost' : 'Component Consumption Cost'} value={currency(summary.known_consumption_cost)} hint={`${summary.total_consumption_events} event${summary.total_consumption_events === 1 ? '' : 's'} · issue/replacement period`} tone="purple" />
        <SummaryCard icon={BarChart3} label={partial ? 'Known Cost / Click' : 'Component Cost / Click'} value={summary.known_component_cost_per_click == null ? 'Unavailable' : currency(summary.known_component_cost_per_click)} hint={Number(summary.total_clicks) === 0 ? 'Valid zero clicks; division unavailable' : 'Known consumption ÷ period clicks'} tone="green" />
        <SummaryCard icon={AlertCircle} label="Unknown Cost Events" value={formatNumber(summary.unknown_consumption_events)} hint={summary.unknown_consumption_events ? 'Excluded from known cost—not treated as zero' : 'No missing consumption cost evidence'} tone="warning" />
      </section>
      <ComponentBreakdown rows={summary.component_breakdown ?? []} partial={partial} />
      <section className="machine-cost-context-grid">
        <article className="machine-cost-context-card glass-surface"><ShoppingCart size={19} /><div><span>Purchase Cost · Account</span><strong>{currency(summary.purchase_cost_context)}</strong><small>Acquisition value purchased in this period. Never included in machine cost/click.</small></div></article>
        <article className="machine-cost-context-card glass-surface"><Package size={19} /><div><span>Ending Inventory Cost Basis · Branch</span><strong>{currency(summary.ending_known_inventory_cost_context)}</strong><small>{formatNumber(summary.ending_known_inventory_quantity_context)} known-cost qty · {formatNumber(summary.ending_unknown_inventory_quantity_context)} unknown-cost qty</small></div></article>
      </section>
      <LifecycleEvidence rows={summary.realized_lifecycle_evidence ?? []} />
    </>}
  </div>
}
