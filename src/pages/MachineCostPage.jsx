import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertCircle, BarChart3, Boxes, CalendarRange, CircleDollarSign, Gauge, Package, Printer, RefreshCcw, ShoppingCart } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { machineCostPeriodPresets, resolveMachineCostPeriod, validMachineCostFilters } from '../features/machineCost/machineCostPeriods.js'
import { componentCompositionPresentation, costPerClickPresentation, counterEvidencePresentation, inventoryContextPresentation, knownConsumptionPresentation, primaryCostPerClickPresentation, purchaseContextPresentation, summaryStatusPresentation } from '../features/machineCost/machineCostPresentation.js'
import { OperatingCostDialog } from '../features/machineCost/OperatingCostDialog.jsx'
import { OperatingCostsPanel } from '../features/machineCost/OperatingCostsPanel.jsx'
import { VoidOperatingCostDialog } from '../features/machineCost/VoidOperatingCostDialog.jsx'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { createMachineOperatingCost, loadMachineCostPeriod, loadMachineOperatingCosts, voidMachineOperatingCost } from '../services/supabase/machineCost.js'
import { loadMachines } from '../services/supabase/machines.js'

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const number = new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 })

function currency(value) { return idr.format(Number(value ?? 0)) }
function formatNumber(value) { return value == null ? 'Unavailable' : number.format(Number(value)) }

function SummaryCard({ icon, label, value, hint, tone = 'blue' }) {
  const Icon = icon
  return <article className="machine-cost-summary-card glass-surface"><span className={`machine-cost-summary-icon tone-${tone}`}><Icon size={20} /></span><div><span>{label}</span><strong>{value}</strong><small>{hint}</small></div></article>
}

function SummaryStatusBadge({ summary }) {
  const [label, tone] = summaryStatusPresentation(summary)
  return <span className={`machine-cost-status-badge tone-${tone}`} role="status">{label}</span>
}

function ComponentBreakdown({ rows, partial }) {
  return <section className="machine-cost-panel glass-surface"><header><div><span className="card-kicker">Composition</span><h2>Component consumption</h2></div>{partial && <span className="machine-cost-partial"><AlertCircle size={13} />Known cost only</span>}</header>
    {!rows.length ? <div className="machine-cost-empty"><Boxes size={24} /><strong>No component consumption in this period.</strong><span>The engine will show replacement consumption here when operational events occur.</span></div> : <div className="machine-cost-breakdown">{rows.map((row) => { const display = componentCompositionPresentation(row, currency); return <article key={row.component_id}><div className="machine-cost-breakdown-label"><strong>{row.component_name}</strong><span>{row.component_category} · {display.meta}</span></div>{display.showPercent ? <div className="machine-cost-bar" role="meter" aria-label={`${row.component_name}: ${row.known_cost_percent}% of known cost`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={Number(row.known_cost_percent)}><span style={{ width: `${Math.min(100, Number(row.known_cost_percent))}%` }} /></div> : <div className="machine-cost-bar machine-cost-bar-unknown" aria-label={`${row.component_name}: cost basis unknown`}><span /></div>}<div><strong>{display.value}</strong>{display.showPercent && <span>{Number(row.known_cost_percent).toFixed(2)}% of known cost</span>}</div></article> })}</div>}
  </section>
}

function LifecycleEvidence({ rows }) {
  return <section className="machine-cost-panel glass-surface"><header><div><span className="card-kicker">Analytical evidence</span><h2>Completed lifecycles</h2></div><span className="machine-cost-context-note">Not added to period consumption</span></header>
    {!rows.length ? <div className="machine-cost-empty compact"><RefreshCcw size={22} /><strong>No lifecycle completed in this period.</strong></div> : <div className="machine-cost-lifecycles">{rows.map((row) => <article key={row.lifecycle_id}><div><strong>{row.component_name}</strong><span>{row.slot_code} · {row.component_category}</span></div><dl><div><dt>Installed cost</dt><dd>{row.cost_is_unknown ? 'Unknown' : currency(row.installed_component_cost)}</dd></div><div><dt>Actual clicks / yield</dt><dd>{formatNumber(row.actual_usage)}</dd></div><div><dt>Realized cost / click</dt><dd>{row.realized_cost_per_click == null ? 'Unavailable' : currency(row.realized_cost_per_click)}</dd></div></dl></article>)}</div>}
  </section>
}

export function MachineCostPage() {
  const { user } = useAuth()
  const { account, branch, membership } = useTenant()
  const filterKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch?.id, feature: 'machine-cost-filters', entityId: 'workspace' })
  const { value: filters, setUIState: setFilters } = usePersistentUIState({ uiStateKey: filterKey, initialValue: { machineId: null, preset: 'this_month', customStart: '', customEnd: '', view: 'summary' }, validate: validMachineCostFilters })
  const [machines, setMachines] = useState([])
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [costWorkspace, setCostWorkspace] = useState({ costs: [], people: [] })
  const [costError, setCostError] = useState(null)
  const [costDialog, setCostDialog] = useState(false)
  const [voidTarget, setVoidTarget] = useState(null)
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

  const refreshCosts = useCallback(async () => {
    if (!selectedMachine) return setCostWorkspace({ costs: [], people: [] })
    try { setCostError(null); setCostWorkspace(await loadMachineOperatingCosts({ accountId: account.id, machineId: selectedMachine.id })) }
    catch (loadError) { setCostError(loadError) }
  }, [account.id, selectedMachine])
  useEffect(() => { refreshCosts() }, [refreshCosts])

  async function saveOperatingCost(values) { await createMachineOperatingCost({ accountId: account.id, machineId: selectedMachine.id, values }); await Promise.all([refresh(), refreshCosts()]) }
  async function voidOperatingCost(reason) { await voidMachineOperatingCost({ costId: voidTarget.id, reason, clientRequestId: crypto.randomUUID() }); await Promise.all([refresh(), refreshCosts()]) }

  const partial = summary?.consumption_status === 'PARTIAL'
  const counterDisplay = summary ? counterEvidencePresentation(summary) : null
  const consumptionDisplay = summary ? knownConsumptionPresentation(summary, currency) : null
  const costPerClickDisplay = summary ? costPerClickPresentation(summary, currency) : null
  const primaryCostPerClickDisplay = summary ? primaryCostPerClickPresentation(summary, currency) : null
  const inventoryDisplay = summary ? inventoryContextPresentation(summary) : null
  const purchaseDisplay = purchaseContextPresentation()
  const canManageCosts = ['owner', 'admin'].includes(membership?.role)
  const advancedEnabled = Boolean(summary?.advanced_machine_economics_enabled ?? account.machine_economics_advanced_enabled)
  const activeTab = filters.view ?? 'summary'

  return <div className="page-stack machine-cost-page">
    <PageHeader eyebrow="Operational economics" title="Machine Cost" description="Recorded clicks, consumed component cost, and assessed Error / Waste for the selected period." />
    <section className="machine-cost-filters glass-surface" aria-label="Machine cost filters">
      <label><span>Machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => setFilters((current) => ({ ...current, machineId: event.target.value }))} disabled={!machines.length}><option value="">{machines.length ? 'Select machine' : 'No active machines'}</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label>
      <label><span>Period</span><select value={filters.preset} onChange={(event) => setFilters((current) => ({ ...current, preset: event.target.value }))}>{machineCostPeriodPresets.map((preset) => <option value={preset.id} key={preset.id}>{preset.label}</option>)}</select></label>
      {filters.preset === 'custom' && <><label><span>Start date</span><input type="date" value={filters.customStart} onChange={(event) => setFilters((current) => ({ ...current, customStart: event.target.value }))} /></label><label><span>End date</span><input type="date" value={filters.customEnd} min={filters.customStart || undefined} onChange={(event) => setFilters((current) => ({ ...current, customEnd: event.target.value }))} /></label></>}
      <div className="machine-cost-period-readout"><CalendarRange size={16} /><span>{validPeriod ? `${resolvedPeriod.start} → ${resolvedPeriod.end}` : 'Choose a valid date range'}<small>{timezone} operational dates</small></span></div>
      <button className="secondary-button" type="button" onClick={refresh} disabled={loading || !selectedMachine || !validPeriod} aria-label="Refresh machine cost"><RefreshCcw size={15} />Refresh</button>
    </section>

    <div className="machine-cost-section-nav"><nav className="machine-cost-tabs" aria-label="Machine cost sections" role="tablist"><button type="button" role="tab" aria-selected={activeTab === 'summary'} className={activeTab === 'summary' ? 'active' : ''} onClick={() => setFilters((current) => ({ ...current, view: 'summary' }))}>Summary</button><button type="button" role="tab" aria-selected={activeTab === 'details'} className={activeTab === 'details' ? 'active' : ''} onClick={() => setFilters((current) => ({ ...current, view: 'details' }))}>Cost Details</button><button type="button" role="tab" aria-selected={activeTab === 'operating'} className={activeTab === 'operating' ? 'active' : ''} onClick={() => setFilters((current) => ({ ...current, view: 'operating' }))}>Operating Costs</button></nav>{summary && activeTab !== 'operating' && <SummaryStatusBadge summary={summary} />}</div>

    {error && <div className="inline-error" role="alert">{error.message}</div>}
    {activeTab === 'operating' && selectedMachine ? <><OperatingCostsPanel costs={costWorkspace.costs} canManage={canManageCosts} enabled={advancedEnabled} onAdd={() => setCostDialog(true)} onVoid={setVoidTarget} />{costError && <div className="inline-error" role="alert">{costError.message}</div>}</> : loading ? <div className="machine-loading-state glass-surface"><RefreshCcw className="spin" size={24} /><strong>Loading machine cost evidence…</strong><span>Reading effective counter usage, component consumption, and assessed Error / Waste.</span></div> : !selectedMachine ? <div className="machine-empty-state glass-surface"><span className="empty-machine-icon"><Printer size={38} /></span><h3>No active machine in this branch</h3><p>Add or activate a machine before querying operational component cost.</p></div> : summary && activeTab === 'summary' ? <>
      {summary.counter_status !== 'COMPLETE' && <section className="machine-cost-action-message" role="status"><AlertCircle size={17} /><div><strong>No Counter Data</strong><span>{counterDisplay.hint} Cost / Click is unavailable.</span></div></section>}
      <section className="machine-economics-summary-grid">
        <SummaryCard icon={Gauge} label="Total Clicks" value={formatNumber(summary.total_clicks)} hint={summary.counter_status === 'COMPLETE' ? 'Effective Daily Counter usage in this period' : counterDisplay.hint} />
        <SummaryCard icon={Boxes} label="Component Consumption" value={consumptionDisplay.value} hint={consumptionDisplay.hint} tone="purple" />
        <SummaryCard icon={AlertCircle} label="Error / Waste" value={summary.error_waste_events > 0 && summary.known_error_waste_events === 0 ? '—' : currency(summary.known_error_waste_cost)} hint={`${summary.known_error_waste_events} assessed · ${summary.unknown_error_waste_events} unpriced`} tone="warning" />
        <SummaryCard icon={BarChart3} label="Cost / Click" value={primaryCostPerClickDisplay.value} hint={primaryCostPerClickDisplay.hint} tone="green" />
      </section>
      {advancedEnabled && <section className="machine-cost-panel glass-surface advanced-economics-panel"><header><div><span className="card-kicker">Advanced</span><h2>Advanced Operating Costs</h2><p>Full economics is shown separately. Standard Machine Cost keeps the same meaning.</p></div></header><div className="machine-economics-layers"><div><span>Advanced Operating Costs</span><strong>{currency(summary.known_advanced_operating_cost)}</strong><small>{summary.operating_cost_records ? `${summary.operating_cost_records} posted period record${summary.operating_cost_records === 1 ? '' : 's'}` : 'No advanced operating costs recorded for this period.'}</small></div><div><span>Standard Machine Cost</span><strong>{currency(summary.known_standard_machine_cost)}</strong></div><div className="total"><span>Full Machine Operating Cost</span><strong>{currency(summary.known_full_machine_operating_cost)}</strong></div><div className="total"><span>Full Operating Cost / Click</span><strong>{summary.known_full_operating_cost_per_click == null ? 'Unavailable' : currency(summary.known_full_operating_cost_per_click)}</strong></div></div></section>}
    </> : summary && activeTab === 'details' ? <>
      <section className="machine-cost-context-grid">
        <article className="machine-cost-context-card glass-surface"><CircleDollarSign size={19} /><div><span>Component Cost / Click</span><strong>{costPerClickDisplay.value}</strong><small>{costPerClickDisplay.hint}</small><dl><div><dt>Unknown component events</dt><dd>{formatNumber(summary.unknown_consumption_events)}</dd></div><div><dt>Unpriced Error / Waste</dt><dd>{formatNumber(summary.unknown_error_waste_events)}</dd></div></dl></div></article>
        <article className="machine-cost-context-card glass-surface"><AlertCircle size={19} /><div><span>Cost Evidence</span><strong>{summary.unknown_evidence_events ? 'Partial' : 'Complete'}</strong><small>{summary.unknown_evidence_events ? `${summary.unknown_evidence_events} event${summary.unknown_evidence_events === 1 ? '' : 's'} remain explicitly unknown and are not treated as zero.` : 'No unknown cost evidence in this period.'}</small></div></article>
      </section>
      <ComponentBreakdown rows={summary.component_breakdown ?? []} partial={partial} />
      <section className="machine-cost-context-grid">
        <article className="machine-cost-context-card glass-surface"><ShoppingCart size={19} /><div><span>{purchaseDisplay.label}</span><strong>{currency(summary.purchase_cost_context)}</strong><small>{purchaseDisplay.hint}</small></div></article>
        <article className="machine-cost-context-card glass-surface"><Package size={19} /><div><span>{inventoryDisplay.label}</span><strong>{currency(summary.ending_known_inventory_cost_context)}</strong><small>{inventoryDisplay.hint}</small><dl><div><dt>Known-cost qty</dt><dd>{formatNumber(summary.ending_known_inventory_quantity_context)}</dd></div><div><dt>Unknown-cost qty</dt><dd>{formatNumber(summary.ending_unknown_inventory_quantity_context)}</dd></div></dl></div></article>
      </section>
      <LifecycleEvidence rows={summary.realized_lifecycle_evidence ?? []} />
    </> : null}
    {costDialog && selectedMachine && advancedEnabled && <OperatingCostDialog account={account} branch={branch} machine={selectedMachine} people={costWorkspace.people} onClose={() => setCostDialog(false)} onSave={saveOperatingCost} />}
    {voidTarget && <VoidOperatingCostDialog cost={voidTarget} onClose={() => setVoidTarget(null)} onVoid={voidOperatingCost} />}
  </div>
}
