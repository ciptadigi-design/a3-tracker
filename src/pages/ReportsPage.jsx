import { createElement, useCallback, useEffect, useMemo, useState } from 'react'
import { AlertTriangle, BarChart3, Boxes, CalendarRange, CircleDollarSign, Download, Eye, Gauge, Package, Printer, RefreshCcw, ShoppingCart, TrendingUp } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { ReportDetailDialog } from '../features/reports/ReportDetailDialog.jsx'
import { buildReportExport, downloadCsv } from '../features/reports/reportExport.js'
import { deltaPresentation, priceEvidence, reportStatus, reportTabs, validReportFilters } from '../features/reports/reportModel.js'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { machineCostPeriodPresets, resolveMachineCostPeriod } from '../features/machineCost/machineCostPeriods.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { loadMachines } from '../services/supabase/machines.js'
import { loadOperationalReport } from '../services/supabase/reports.js'

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 })
const numeric = new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 })
const money = (value) => value == null ? 'Unavailable' : idr.format(Number(value))
const count = (value) => value == null ? 'Unavailable' : numeric.format(Number(value))
const percent = (value) => value == null ? 'Unavailable' : `${numeric.format(Number(value))}%`
const comparisonMetrics = {
  estimated_standard_contribution: { label: 'Estimated Contribution', format: money },
  total_clicks: { label: 'Total Clicks', format: count },
  standard_cost_per_click: { label: 'Cost / Click', format: money },
  error_waste_cost: { label: 'Error / Waste', format: money },
}

function isComparableFor(row, metric) {
  if (row[metric] == null) return false
  if (metric === 'total_clicks') return row.comparison_status !== 'NO_COUNTER_DATA'
  if (metric === 'standard_cost_per_click') return row.cost_evidence_status === 'COMPLETE'
  if (metric === 'estimated_standard_contribution') return row.comparison_status === 'COMPLETE'
  return true
}

function ReportKpi({ icon, label, value, hint, tone = 'blue', delta }) {
  const change = deltaPresentation(delta)
  return <article className="report-kpi glass-surface"><span className={`report-kpi-icon tone-${tone}`}>{createElement(icon, { size: 19 })}</span><div><span>{label}</span><strong>{value}</strong>{delta && <b className={`report-delta tone-${change.tone}`}>{change.label}</b>}<small>{hint}</small></div></article>
}

function EmptyReport({ icon = BarChart3, title, detail }) {
  return <div className="report-empty glass-surface">{createElement(icon, { size: 26 })}<strong>{title}</strong><span>{detail}</span></div>
}

function StatusBadge({ status }) {
  const [label, tone] = reportStatus(status)
  return <span className={`report-status tone-${tone}`} role="status">{label}</span>
}

function RankingChart({ title, description, rows, valueKey, label, formatValue = money, tone = 'blue' }) {
  const maximum = Math.max(0, ...rows.map((row) => Math.abs(Number(row[valueKey] ?? 0))))
  return <section className="report-chart glass-surface"><header><div><span className="card-kicker">Comparison</span><h2>{title}</h2><p>{description}</p></div></header>{rows.length ? <div className="report-ranking">{rows.map((row) => <div key={row.key ?? `${label(row)}-${row[valueKey]}`}><div><strong>{label(row)}</strong><span>{formatValue(row[valueKey])}</span></div><span className="report-rank-track"><i className={`tone-${tone}`} style={{ width: `${maximum ? Math.abs(Number(row[valueKey] ?? 0)) / maximum * 100 : 0}%` }} /></span></div>)}</div> : <div className="report-chart-empty">No chart evidence for this period.</div>}</section>
}

function DailyClicksChart({ rows }) {
  return <RankingChart title="Daily Click Trend" description="Effective Daily Counter usage by operational date; baselines, voided, and superseded readings are excluded." rows={rows.map((row) => ({ ...row, key: row.operational_date }))} valueKey="total_clicks" label={(row) => row.operational_date} formatValue={count} />
}

function ReportList({ headers, rows, renderRow, emptyTitle, emptyDetail }) {
  if (!rows.length) return <EmptyReport title={emptyTitle} detail={emptyDetail} />
  return <div className="report-table-wrap glass-surface"><div className="report-table-head" style={{ '--report-columns': headers.length }}>{headers.map((header) => <span key={header}>{header}</span>)}</div><div className="report-table-body">{rows.map(renderRow)}</div></div>
}

function PeriodComparison({ rows }) {
  const metricCodes = ['TOTAL_CLICKS','STANDARD_MACHINE_COST','ESTIMATED_MACHINE_REVENUE','ESTIMATED_CONTRIBUTION']
  const metrics = metricCodes.map((code) => rows.find((row) => row.metric_code === code)).filter(Boolean)
  return <section className="report-period-comparison glass-surface" aria-labelledby="period-comparison-title"><header><div><span className="card-kicker">Current vs Previous</span><h2 id="period-comparison-title">Period Comparison</h2></div>{metrics[0] && <small>{metrics[0].previous_period_start} → {metrics[0].previous_period_end}</small>}</header>{metrics.length ? <div>{metrics.map((row) => { const change = deltaPresentation(row); return <article key={row.metric_code}><span>{row.metric_label}</span><strong>{row.metric_code === 'TOTAL_CLICKS' ? count(row.current_value) : money(row.current_value)}</strong><small>Previous {row.metric_code === 'TOTAL_CLICKS' ? count(row.previous_value) : money(row.previous_value)}</small><b className={`report-delta tone-${change.tone}`}>{change.label}</b></article> })}</div> : <p>No comparable period evidence is available.</p>}</section>
}

export function ReportsPage() {
  const { user } = useAuth()
  const { account, branches } = useTenant()
  const stateKey = createUIStateKey({ userId: user.id, accountId: account.id, feature: 'reports-filters', entityId: 'workspace' })
  const { value: filters, setUIState: setFilters } = usePersistentUIState({
    uiStateKey: stateKey,
    initialValue: { tab: 'overview', branchId: '', machineId: '', preset: 'this_month', customStart: '', customEnd: '', errorCategory: '', errorStatus: '', comparisonMetric: 'estimated_standard_contribution', componentSort: 'cost_rank' },
    validate: validReportFilters,
  })
  const [machines, setMachines] = useState([])
  const [report, setReport] = useState(null)
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [generatedAt, setGeneratedAt] = useState(() => new Date())
  const selectedBranch = branches.find((branch) => branch.id === filters.branchId) ?? null
  const availableMachines = machines.filter((machine) => !filters.branchId || machine.branch_id === filters.branchId)
  const selectedMachine = availableMachines.find((machine) => machine.id === filters.machineId) ?? null
  const timezone = selectedMachine?.timezone || selectedBranch?.timezone || account.default_timezone || 'Asia/Jakarta'
  const period = useMemo(() => resolveMachineCostPeriod({ preset: filters.preset, timezone, customStart: filters.customStart, customEnd: filters.customEnd }), [filters.customEnd, filters.customStart, filters.preset, timezone])
  const validPeriod = Boolean(period.start && period.end && period.start <= period.end)

  useEffect(() => {
    let active = true
    loadMachines({ accountId: account.id }).then((rows) => { if (active) setMachines(rows.filter((machine) => machine.is_active)) }).catch((loadError) => { if (active) setError(loadError) })
    return () => { active = false }
  }, [account.id])
  useEffect(() => {
    if (filters.machineId && !availableMachines.some((machine) => machine.id === filters.machineId)) setFilters((current) => ({ ...current, machineId: '' }))
  }, [availableMachines, filters.machineId, setFilters])

  const refresh = useCallback(async () => {
    if (!validPeriod) { setReport(null); return }
    setLoading(true); setError(null)
    try {
      setReport(await loadOperationalReport({ accountId: account.id, branchId: filters.branchId, machineId: filters.machineId,
        periodStart: period.start, periodEnd: period.end, periodPreset: filters.preset, errorCategory: filters.errorCategory, errorStatus: filters.errorStatus }))
    } catch (loadError) { setError(loadError); setReport(null) } finally { setLoading(false) }
  }, [account.id, filters.branchId, filters.errorCategory, filters.errorStatus, filters.machineId, filters.preset, period.end, period.start, validPeriod])
  useEffect(() => { refresh() }, [refresh])

  const overview = report?.overview
  const incidentTotal = Number(overview?.machine_attributed_error_waste ?? 0) + Number(overview?.branch_only_error_waste ?? 0)
  const comparisonByMetric = useMemo(() => Object.fromEntries((report?.periodComparison ?? []).map((row) => [row.metric_code, row])), [report?.periodComparison])
  const comparisonMetric = filters.comparisonMetric || 'estimated_standard_contribution'
  const componentSort = filters.componentSort || 'cost_rank'
  const componentRanking = useMemo(() => [...(report?.componentRanking ?? [])].sort((left, right) => Number(left[componentSort] ?? Number.MAX_SAFE_INTEGER) - Number(right[componentSort] ?? Number.MAX_SAFE_INTEGER)), [componentSort, report?.componentRanking])
  const scopeLabel = selectedMachine ? `${selectedMachine.machine_code} · ${selectedMachine.display_name}` : selectedBranch ? `${selectedBranch.code} · ${selectedBranch.name}` : 'All Branches · All Machines'

  function handleExport() {
    const exported = buildReportExport({ tab: filters.tab, report, periodStart: period.start, periodEnd: period.end,
      branchLabel: selectedBranch?.code, machineLabel: selectedMachine?.machine_code })
    downloadCsv(exported)
  }

  function handlePrint() {
    setGeneratedAt(new Date())
    window.requestAnimationFrame(() => window.print())
  }

  return <div className="page-stack reports-page">
    <PageHeader eyebrow="Read-only operational projections" title="Reports" description="Reusable period reports derived from Daily Counter, consumption, Error/Waste, inventory, and machine economics evidence." />
    <section className="report-print-header" aria-hidden="true"><span>A3 Tracker Report</span><h1>{reportTabs.find((tab) => tab.id === filters.tab)?.label}</h1><dl><div><dt>Scope</dt><dd>{scopeLabel}</dd></div><div><dt>Period</dt><dd>{period.start} → {period.end}</dd></div><div><dt>Generated</dt><dd>{generatedAt.toLocaleString('id-ID', { timeZone: timezone })} ({timezone})</dd></div></dl></section>
    <section className="report-filter-bar glass-surface" aria-label="Report filters">
      <label><span>Branch</span><select value={filters.branchId} onChange={(event) => setFilters((current) => ({ ...current, branchId: event.target.value, machineId: '' }))}><option value="">All Branches</option>{branches.map((branch) => <option value={branch.id} key={branch.id}>{branch.code} · {branch.name}</option>)}</select></label>
      <label><span>Machine</span><select value={filters.machineId} onChange={(event) => setFilters((current) => ({ ...current, machineId: event.target.value }))}><option value="">All Machines</option>{availableMachines.map((machine) => <option value={machine.id} key={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label>
      <label><span>Period</span><select value={filters.preset} onChange={(event) => setFilters((current) => ({ ...current, preset: event.target.value }))}>{machineCostPeriodPresets.map((preset) => <option value={preset.id} key={preset.id}>{preset.label}</option>)}</select></label>
      {filters.preset === 'custom' && <><label><span>Start</span><input type="date" value={filters.customStart} onChange={(event) => setFilters((current) => ({ ...current, customStart: event.target.value }))} /></label><label><span>End</span><input type="date" min={filters.customStart || undefined} value={filters.customEnd} onChange={(event) => setFilters((current) => ({ ...current, customEnd: event.target.value }))} /></label></>}
      <div className="report-period"><CalendarRange size={16} /><span>{validPeriod ? `${period.start} → ${period.end}` : 'Choose a valid range'}<small>{timezone} filter context</small></span></div>
      <button className="secondary-button" type="button" onClick={refresh} disabled={loading || !validPeriod}><RefreshCcw size={15} />Refresh</button>
    </section>
    <div className="report-action-bar" aria-label="Report export and print actions"><span>Exports use the selected filters and authorized report data.</span><div><button className="secondary-button" type="button" onClick={handleExport} disabled={!report || loading}><Download size={15} />Export CSV</button><button className="secondary-button" type="button" onClick={handlePrint} disabled={!report || loading}><Printer size={15} />Print / Save PDF</button></div></div>
    <nav className="report-tabs" role="tablist" aria-label="Report sections">{reportTabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={filters.tab === tab.id} className={filters.tab === tab.id ? 'active' : ''} onClick={() => setFilters((current) => ({ ...current, tab: tab.id }))}>{tab.label}</button>)}</nav>
    {error && <div className="inline-error" role="alert">{error.message}</div>}
    {loading ? <div className="report-loading glass-surface"><RefreshCcw className="spin" size={23} /><strong>Building report projections…</strong><span>Reading authoritative operational evidence for the selected range.</span></div> : !report ? <EmptyReport title="Report unavailable" detail="Choose a valid report range and try again." /> : <>
      {filters.tab === 'overview' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Overview</span><h2>Operational Summary</h2><p>Standard machine economics and operational Error/Waste remain separately scoped.</p></div><StatusBadge status={overview?.report_status} /></div>
        <section className="report-kpi-grid">
          <ReportKpi icon={Gauge} label="Total Clicks" value={count(overview?.total_clicks)} delta={comparisonByMetric.TOTAL_CLICKS} hint={`${overview?.active_machines ?? 0} active machine projection${overview?.active_machines === 1 ? '' : 's'}`} />
          <ReportKpi icon={Boxes} label="Component Consumption" value={money(overview?.component_consumption_cost)} delta={comparisonByMetric.COMPONENT_CONSUMPTION} hint="Known FIFO-backed consumption; unknown evidence remains flagged" tone="purple" />
          <ReportKpi icon={AlertTriangle} label="Error / Waste" value={money(incidentTotal)} delta={comparisonByMetric.ERROR_WASTE} hint={`${money(overview?.machine_attributed_error_waste)} machine · ${money(overview?.branch_only_error_waste)} branch-only`} tone="warning" />
          <ReportKpi icon={BarChart3} label="Standard Cost / Click" value={money(overview?.standard_cost_per_click)} delta={comparisonByMetric.STANDARD_COST_PER_CLICK} hint="Aggregate Standard Machine Cost ÷ aggregate clicks" tone="green" />
          <ReportKpi icon={TrendingUp} label="Estimated Machine Revenue" value={money(overview?.estimated_machine_revenue)} delta={comparisonByMetric.ESTIMATED_MACHINE_REVENUE} hint="Utilization revenue from historically priced clicks; not invoice revenue" tone="green" />
          <ReportKpi icon={CircleDollarSign} label="Estimated Contribution" value={money(overview?.estimated_standard_contribution)} delta={comparisonByMetric.ESTIMATED_CONTRIBUTION} hint={`Margin ${percent(overview?.contribution_margin_percent)} · not net profit`} tone="blue" />
        </section>
        <PeriodComparison rows={report.periodComparison} />
        <DailyClicksChart rows={report.dailyClicks} />
      </>}
      {filters.tab === 'performance' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Machine Performance</span><h2>Recorded Machine Usage</h2><p>No capacity or utilization percentage is fabricated.</p></div></div>
        <ReportList headers={['Machine','Branch','Total Clicks','Active Days','Daily Average','Latest Counter','Last Input']} rows={report.performance} emptyTitle="No machine performance data" emptyDetail="No active machine belongs to this report scope." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 7 }} key={row.machine_id}><div data-label="Machine"><Printer size={15} /><span><strong>{row.machine_code}</strong><small>{row.machine_name}</small></span></div><span data-label="Branch">{row.branch_name}</span><strong data-label="Total Clicks">{count(row.total_clicks)}</strong><span data-label="Active Days">{row.active_days}</span><span data-label="Daily Average">{count(row.daily_average_clicks)}</span><span data-label="Latest Counter">{count(row.latest_counter)}</span><span data-label="Last Input">{row.last_input_at ? new Date(row.last_input_at).toLocaleString('id-ID', { timeZone: row.resolved_timezone }) : 'No input'}</span></article>} />
      </>}
      {filters.tab === 'economics' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Machine Economics</span><h2>Standard Contribution by Machine</h2><p>Estimated Machine Revenue is utilization evidence, not invoiced sales.</p></div></div>
        <RankingChart title="Estimated Contribution by Machine" description="Available Standard Contribution for machines with comparable price and cost evidence." rows={report.economics.filter((row) => row.estimated_standard_contribution != null).map((row) => ({ ...row, key: row.machine_id }))} valueKey="estimated_standard_contribution" label={(row) => row.machine_code} />
        <ReportList headers={['Machine','Clicks','Standard Cost','Cost / Click','Price Evidence','Est. Machine Revenue','Est. Contribution','Evidence']} rows={report.economics} emptyTitle="No machine economics" emptyDetail="No active machine belongs to this scope." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 8 }} key={row.machine_id}><div data-label="Machine"><strong>{row.machine_code}</strong><small>{row.branch_name} · {row.machine_name}</small></div><span data-label="Clicks">{count(row.total_clicks)}</span><span data-label="Standard Cost">{money(row.standard_machine_cost)}</span><span data-label="Cost / Click">{money(row.standard_cost_per_click)}</span><span data-label="Price Evidence">{priceEvidence(row, money)}</span><span data-label="Est. Machine Revenue">{money(row.estimated_machine_revenue)}<small>{row.revenue_status}</small></span><span data-label="Est. Contribution">{money(row.estimated_standard_contribution)}<small>{row.standard_contribution_margin_percent == null ? 'Margin unavailable' : `Margin ${percent(row.standard_contribution_margin_percent)}`}</small>{row.advanced_enabled && <small>Full: {money(row.estimated_full_contribution)}</small>}</span><span data-label="Evidence" className="report-evidence"><b>{row.standard_contribution_status}</b><small>{row.standard_economics_status}</small></span></article>} />
      </>}
      {filters.tab === 'comparison' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Machine Comparison</span><h2>Compare Machines in Scope</h2><p>Partial evidence remains visible but is not ranked as fully comparable.</p></div><label className="report-metric-select"><span>Chart metric</span><select value={comparisonMetric} onChange={(event) => setFilters((current) => ({ ...current, comparisonMetric: event.target.value }))}>{Object.entries(comparisonMetrics).map(([value, option]) => <option key={value} value={value}>{option.label}</option>)}</select></label></div>
        <RankingChart title={`${comparisonMetrics[comparisonMetric].label} by Machine`} description="One focused comparison using only evidence valid for the selected metric." rows={report.machineComparison.filter((row) => isComparableFor(row, comparisonMetric)).map((row) => ({ ...row, key: row.machine_id }))} valueKey={comparisonMetric} label={(row) => `${row.machine_code} · ${row.branch_code}`} formatValue={comparisonMetrics[comparisonMetric].format} />
        <ReportList headers={['Rank','Machine','Branch','Clicks','Cost / Click','Error / Waste','Est. Machine Revenue','Est. Contribution','Margin','Evidence']} rows={report.machineComparison} emptyTitle="No machines to compare" emptyDetail="No active machine belongs to this report scope." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 10 }} key={row.machine_id}><strong data-label="Rank">{row.contribution_rank ?? '—'}</strong><span data-label="Machine"><strong>{row.machine_code}</strong><small>{row.machine_name}</small></span><span data-label="Branch">{row.branch_name}</span><span data-label="Clicks">{count(row.total_clicks)}</span><span data-label="Cost / Click">{money(row.standard_cost_per_click)}</span><span data-label="Error / Waste">{money(row.error_waste_cost)}</span><span data-label="Est. Machine Revenue">{money(row.estimated_machine_revenue)}</span><span data-label="Est. Contribution">{money(row.estimated_standard_contribution)}</span><span data-label="Margin">{percent(row.standard_contribution_margin_percent)}</span><span data-label="Evidence" className="report-evidence"><b>{row.comparison_status}</b><small>{row.revenue_status} · {row.cost_evidence_status}</small></span></article>} />
      </>}
      {filters.tab === 'components' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Component Consumption</span><h2>Replacement Cost Evidence</h2><p>Logical component identities and CMYK channels remain distinct.</p></div><label className="report-metric-select"><span>Rank by</span><select value={componentSort} onChange={(event) => setFilters((current) => ({ ...current, componentSort: event.target.value }))}><option value="cost_rank">Highest known cost</option><option value="replacement_rank">Most replacements</option><option value="unknown_evidence_rank">Most unknown evidence</option></select></label></div>
        <RankingChart title="Known Consumed Cost Ranking" description="Share and ranking use the known FIFO-backed cost denominator only; unknown events remain explicit." rows={componentRanking.slice(0, 10).map((row) => ({ ...row, key: row.component_id }))} valueKey="known_consumed_cost" label={(row) => row.component_code} tone="purple" />
        <ReportList headers={['Component','Replacements','Known Cost','Known Cost Share','Unknown Events','Evidence']} rows={componentRanking} emptyTitle="No component ranking" emptyDetail="No replacement consumption occurred in this period." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 6 }} key={row.component_id}><span data-label="Component"><strong>{row.component_code}</strong><small>{row.component_name} · {row.component_category}</small></span><strong data-label="Replacements">{row.replacement_count}</strong><span data-label="Known Cost">{money(row.known_consumed_cost)}</span><span data-label="Known Cost Share">{percent(row.known_cost_share_percent)}<small>Known cost denominator</small></span><span data-label="Unknown Events">{row.unknown_cost_events}</span><span data-label="Evidence" className="report-evidence"><b>{row.evidence_status}</b></span></article>} />
        <h3 className="report-subheading">Machine Detail</h3>
        <ReportList headers={['Component','Machine','Branch','Replacements','Known Cost','Unknown Events','Avg. Observed Yield']} rows={report.components} emptyTitle="No component consumption" emptyDetail="No replacement consumption occurred in this period." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 7 }} key={`${row.component_id}-${row.machine_id}`}><div data-label="Component"><Boxes size={15} /><span><strong>{row.component_code}</strong><small>{row.component_name} · {row.component_category}</small></span></div><span data-label="Machine">{row.machine_code}<small>{row.machine_name}</small></span><span data-label="Branch">{row.branch_name}</span><strong data-label="Replacements">{row.replacement_count}</strong><span data-label="Known Cost">{money(row.known_consumed_cost)}</span><span data-label="Unknown Events">{row.unknown_cost_events}</span><span data-label="Avg. Observed Yield">{count(row.average_observed_yield)}</span></article>} />
      </>}
      {filters.tab === 'errors' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Error / Waste</span><h2>Operational Incident Evidence</h2><p>Branch-only and machine-attributed losses remain visibly distinct.</p></div><div className="report-inline-filters"><label><span>Category</span><select value={filters.errorCategory} onChange={(event) => setFilters((current) => ({ ...current, errorCategory: event.target.value }))}><option value="">All</option>{['kesesuaian','kualitas','desain','bahan','prosedur'].map((value) => <option value={value} key={value}>{value}</option>)}</select></label><label><span>Status</span><select value={filters.errorStatus} onChange={(event) => setFilters((current) => ({ ...current, errorStatus: event.target.value }))}><option value="">All</option>{['open','resolved','voided'].map((value) => <option value={value} key={value}>{value}</option>)}</select></label></div></div>
        <section className="report-error-summary" aria-label="Error and Waste analytics">{['CATEGORY','TYPE','ATTRIBUTION','PIC'].map((dimension) => <article className="glass-surface" key={dimension}><h3>By {dimension.toLowerCase()}</h3>{report.errorSummary.filter((row) => row.dimension_type === dimension).length ? <ul>{report.errorSummary.filter((row) => row.dimension_type === dimension).map((row) => <li key={row.dimension_value}><span>{row.dimension_value.replaceAll('_',' ')}</span><strong>{row.incident_count} · {money(row.assessed_loss)}</strong></li>)}</ul> : <p>No assessed evidence</p>}</article>)}</section>
        <ReportList headers={['Date','Scope','Category / Type','PIC','Material','Service','Assessed','Detail']} rows={report.errors} emptyTitle="No Error / Waste evidence" emptyDetail="No incidents match this report scope and filters." renderRow={(row) => <article className={`report-table-row ${row.status === 'voided' ? 'is-voided' : ''}`} style={{ '--report-columns': 8 }} key={row.incident_id}><span data-label="Date">{new Date(row.occurred_at).toLocaleString('id-ID', { timeZone: timezone })}</span><span data-label="Scope"><b>{row.attribution_scope === 'BRANCH_ONLY' ? 'Branch only' : row.machine_code}</b><small>{row.branch_name}</small></span><span data-label="Category / Type">{row.category}<small>{row.incident_type} · {row.status}</small></span><span data-label="PIC">{row.responsible_name || 'Unassigned'}</span><span data-label="Material">{money(row.material_loss)}</span><span data-label="Service">{money(row.service_loss)}</span><strong data-label="Assessed">{money(row.assessed_loss)}</strong><button className="report-eye" type="button" aria-label="View Error / Waste detail" onClick={() => setDetail({ title: 'Error / Waste Detail', description: `${row.attribution_scope === 'BRANCH_ONLY' ? 'Branch-only' : row.machine_code} · ${row.status}`, sections: [{ title: 'Incident', fields: [{ label: 'Occurred', value: new Date(row.occurred_at).toLocaleString('id-ID', { timeZone: timezone }) },{ label: 'Category / Type', value: `${row.category} / ${row.incident_type}` },{ label: 'PIC', value: row.responsible_name || 'Unassigned' },{ label: 'Description', value: row.description }] },{ title: 'Assessed Loss', fields: [{ label: 'Material', value: money(row.material_loss) },{ label: 'Service', value: money(row.service_loss) },{ label: 'Assessed', value: money(row.assessed_loss) }] }] })}><Eye size={16} /></button></article>} />
      </>}
      {filters.tab === 'inventory' && <>
        <div className="report-section-heading"><div><span className="card-kicker">Inventory / Purchasing</span><h2>Operational Stock Context</h2><p>Purchases are account-scoped acquisition context and never become Machine Cost until consumed.</p></div></div>
        <section className="report-kpi-grid compact"><ReportKpi icon={ShoppingCart} label="Purchases" value={String(report.inventory?.purchases ?? 0)} hint={`${money(report.inventory?.purchase_value)} ordered acquisition context`} /><ReportKpi icon={Package} label="Receipts" value={String(report.inventory?.receipts ?? 0)} hint={`${count(report.inventory?.received_quantity)} units · ${money(report.inventory?.received_value)} received value`} tone="green" /><ReportKpi icon={Boxes} label="Inventory Issues" value={String(report.inventory?.issues ?? 0)} hint={`${report.inventory?.replacement_issues ?? 0} replacement · ${report.inventory?.adjustments ?? 0} adjustments · ${report.inventory?.transfer_legs ?? 0} transfer legs`} tone="purple" /><ReportKpi icon={AlertTriangle} label="Stock Attention" value={String(Number(report.inventory?.out_of_stock_items ?? 0) + Number(report.inventory?.low_stock_items ?? 0))} hint={`${report.inventory?.out_of_stock_items ?? 0} out · ${report.inventory?.low_stock_items ?? 0} low`} tone="warning" /></section>
        <h3 className="report-subheading">Purchase Lines</h3>
        <ReportList headers={['Purchase','Supplier','Date / Status','Item','Ordered','Received','Remaining','Detail']} rows={report.purchases} emptyTitle="No purchases in period" emptyDetail="Purchase creation does not change stock; receipts are reported separately." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 8 }} key={`${row.purchase_id}-${row.item_name}-${row.item_sku ?? ''}`}><span data-label="Purchase"><strong>{row.purchase_number}</strong><small>{row.external_reference || 'No external reference'}</small></span><span data-label="Supplier">{row.supplier_name}</span><span data-label="Date / Status">{row.purchase_date}<small>{row.status}</small></span><span data-label="Item">{row.item_name}<small>{row.item_sku || 'No SKU'}</small></span><span data-label="Ordered">{count(row.ordered_quantity)} {row.unit}</span><span data-label="Received">{count(row.received_quantity)} {row.unit}</span><span data-label="Remaining">{count(row.remaining_quantity)} {row.unit}</span><button className="report-eye" type="button" aria-label="View purchase detail" onClick={() => setDetail({ title: row.purchase_number, description: `${row.supplier_name} · read-only purchase evidence`, sections: [{ title: 'Purchase', fields: [{ label: 'External Reference', value: row.external_reference || 'Not provided' },{ label: 'Purchase Date', value: row.purchase_date },{ label: 'Status', value: row.status }] },{ title: 'Line', fields: [{ label: 'Item', value: row.item_sku ? `${row.item_sku} · ${row.item_name}` : row.item_name },{ label: 'Ordered', value: `${count(row.ordered_quantity)} ${row.unit}` },{ label: 'Unit Price', value: money(row.unit_price) },{ label: 'Line Total', value: money(row.line_total) },{ label: 'Received / Remaining', value: `${count(row.received_quantity)} / ${count(row.remaining_quantity)} ${row.unit}` }] }] })}><Eye size={16} /></button></article>} />
        <h3 className="report-subheading">Current Inventory</h3>
        <ReportList headers={['Item / Component','SKU','Stock','Minimum','Status','Locations']} rows={report.stock} emptyTitle="No inventory items" emptyDetail="No active Inventory Items exist in this scope." renderRow={(row) => <article className="report-table-row" style={{ '--report-columns': 6 }} key={row.inventory_item_id}><span data-label="Item / Component"><strong>{row.item_name}</strong><small>{row.component_code ? `${row.component_code} · ${row.component_name}` : 'Not component-linked'}</small></span><span data-label="SKU">{row.sku || 'No SKU'}</span><strong data-label="Stock">{count(row.total_stock)} {row.unit}</strong><span data-label="Minimum">{row.minimum_stock == null ? 'Not set' : `${count(row.minimum_stock)} ${row.unit}`}</span><span data-label="Status" className={`inventory-report-status status-${row.status.toLowerCase()}`}>{row.status.replaceAll('_',' ')}</span><button className="report-eye" type="button" aria-label="View inventory location detail" onClick={() => setDetail({ title: row.item_name, description: 'Read-only current stock and location evidence', sections: [{ title: 'Inventory Item', fields: [{ label: 'SKU', value: row.sku || 'No SKU' },{ label: 'Component', value: row.component_name || 'Not linked' },{ label: 'Total Stock', value: `${count(row.total_stock)} ${row.unit}` },{ label: 'Minimum', value: row.minimum_stock == null ? 'Not set' : `${count(row.minimum_stock)} ${row.unit}` },{ label: 'Status', value: row.status.replaceAll('_',' ') }] },{ title: 'Locations', fields: row.location_breakdown.length ? row.location_breakdown.map((location) => ({ label: location.location_name, value: `${count(location.quantity)} ${row.unit}` })) : [{ label: 'Locations', value: 'No posted stock movement' }] }] })}><Eye size={16} /></button></article>} />
      </>}
    </>}
    {detail && <ReportDetailDialog {...detail} onClose={() => setDetail(null)} />}
  </div>
}
