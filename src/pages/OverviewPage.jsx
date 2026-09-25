import { PeriodFields } from '../features/periods/PeriodFields.jsx'
import { createOverviewRequestGate, overviewContextTimezone, overviewInitialPeriod, overviewPeriod, overviewRangeError } from '../features/overview/overviewPeriod.js'
import { useEffect, useMemo, useState } from 'react'
import { CalendarRange, Gauge, RefreshCcw, Settings as SettingsIcon, TrendingUp } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { useMachines } from '../features/machines/useMachines.js'
import { machineCostPeriodPresets, resolveMachineCostPeriod, validMachineCostFilters } from '../features/machineCost/machineCostPeriods.js'
import { knownConsumptionPresentation, primaryCostPerClickPresentation } from '../features/machineCost/machineCostPresentation.js'
import { formatIdrTotal } from '../features/machineCost/currencyFormat.js'
import { loadMachineCostPeriod } from '../services/machineCost.js'
import { formatClicks, formatPercentage, formatSignedClicks, normalizeDailyPerformance, periodCardPresentation, requiredPacePresentation, targetStatusPresentation, todayContextPresentation } from '../features/clickTargets/clickTargetModel.js'
import { DailyClickPerformanceChart } from '../features/clickTargets/DailyClickPerformanceChart.jsx'
import { loadClickTargetProjection } from '../services/clickTargets.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { userErrorMessage } from '../lib/appErrors.js'

function CostPerClickCard({ summary, periodLabel }) {
  // M2.17.5: product decision changed - Cost / Click now renders whole-Rupiah like
  // every other user-facing IDR value, reusing the same canonical formatIdrTotal()
  // rather than a competing fractional formatter. Only the final display value is
  // rounded; the underlying cost-per-click calculation keeps full precision.
  const presentation = primaryCostPerClickPresentation(summary, formatIdrTotal)
  return <article className="overview-period-card glass-surface">
    <span className="card-kicker">Cost / Click</span>
    <strong>{presentation.value}</strong>
    <span className="overview-period-target">Standard machine cost</span>
    <small>{periodLabel}</small>
  </article>
}

function ComponentConsumptionCard({ summary, periodLabel }) {
  // Reuse Machine Cost's canonical known/partial/unknown replacement-cost
  // presentation. The backend summary is already scoped to this Overview's
  // selected machine and operational period; no replacement math belongs here.
  const presentation = knownConsumptionPresentation(summary, formatIdrTotal)
  return <article className="overview-period-card glass-surface">
    <span className="card-kicker">Component Consumption</span>
    <strong>{presentation.value}</strong>
    <span className="overview-period-target">{presentation.hint}</span>
    <small>{periodLabel}</small>
  </article>
}

function PurchaseValueCard({ summary, periodLabel }) {
  const available = Boolean(summary)
  const purchaseValue = Number(summary?.purchase_value)
  const receivedValue = Number(summary?.received_value)
  const purchaseCount = Number(summary?.purchase_count)
  const receivedPercentage = Number(summary?.received_percentage)
  const safeCount = Number.isFinite(purchaseCount) ? purchaseCount : 0
  const safePercentage = Number.isFinite(receivedPercentage) && purchaseValue > 0 ? receivedPercentage : 0

  return <article className="overview-period-card overview-purchase-card glass-surface">
    <span className="card-kicker">Purchase Value</span>
    <strong>{available ? formatIdrTotal(purchaseValue) : '—'}</strong>
    <span className="overview-period-target">Branch purchasing</span>
    <dl className="overview-purchase-details">
      <div><dt>Purchases</dt><dd>{available ? safeCount.toLocaleString('id-ID') : '—'}</dd></div>
      <div><dt>Received</dt><dd>{available ? formatIdrTotal(receivedValue) : '—'}</dd></div>
      <div><dt>Received value</dt><dd>{available ? `${safePercentage.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%` : '—'}</dd></div>
    </dl>
    <small>{periodLabel}</small>
  </article>
}

function PeriodComparisonRow({ comparison }) {
  if (!comparison) return null
  if (!comparison.available) return <p className="overview-period-comparison tone-neutral"><span>{comparison.unavailableText}</span></p>
  const summary = comparison.isNewActivity
    ? `New activity${comparison.periodLabel ? ` vs ${comparison.periodLabel}` : ''}`
    : `${comparison.arrow ? `${comparison.arrow} ` : ''}${comparison.magnitudeText ?? '—'}${comparison.periodLabel ? ` vs ${comparison.periodLabel}` : ''}`

  return <p className={`overview-period-comparison tone-${comparison.tone}`}>
    <span>{summary}</span>
    {comparison.previousValueText && <b>{comparison.previousValueText}</b>}
  </p>
}

function PeriodCard({ label, card, monthToDate }) {
  const presentation = periodCardPresentation(card, { monthToDate })
  return <article className={`overview-period-card glass-surface tone-${presentation.tone}`}>
    <span className="card-kicker">{label}</span>
    <strong>{presentation.actual}</strong>
    <span className="overview-period-target">{presentation.planned === 'Not configured' ? 'Not configured' : `of ${presentation.planned}`}</span>
    {presentation.achievement && <em>{presentation.achievement}</em>}
    {presentation.varianceLabel && <small>{presentation.varianceLabel}</small>}
    <PeriodComparisonRow comparison={presentation.comparison} />
  </article>
}

export function OverviewPage({ navigate }) {
  const { account, branch } = useTenant()
  // Synchronous context remount prevents even a single render of old machine data.
  return <OverviewWorkspace key={`${account.id}:${branch?.id ?? 'all'}`} navigate={navigate} />
}

function OverviewWorkspace({ navigate }) {
  const { user } = useAuth()
  const { account, branch, membership, isPlatformSuperuser, can } = useTenant()
  const { machines, isLoading: machinesLoading, error: machinesError } = useMachines(account?.id, branch?.id)
  const activeMachines = useMemo(() => machines.filter((machine) => machine.is_active !== false && machine.status !== 'retired'), [machines])
  const canManageTargets = isPlatformSuperuser || ['owner', 'admin'].includes(membership?.role)

  const selectionKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch?.id, feature: 'overview-machine', entityId: 'selection' })
  const { value: selection, setUIState: setSelection } = usePersistentUIState({ uiStateKey: selectionKey, initialValue: { machineId: null }, validate: (v) => v && typeof v === 'object' })
  const selectedMachine = activeMachines.find((machine) => machine.id === selection.machineId) ?? activeMachines[0] ?? null

  useEffect(() => {
    if (selectedMachine && selectedMachine.id !== selection.machineId) setSelection({ machineId: selectedMachine.id })
  }, [selectedMachine, selection.machineId, setSelection])

  const periodKey = createUIStateKey({ userId: user.id, accountId: account.id, feature: 'overview-period', entityId: 'workspace' })
  const { value: filters, setUIState: setFilters } = usePersistentUIState({ uiStateKey: periodKey, initialValue: overviewInitialPeriod, validate: validMachineCostFilters })
  const timezone = overviewContextTimezone(account, branch)
  const [refreshVersion, setRefreshVersion] = useState(0)
  const period = overviewPeriod(filters, account, branch)
  const rangeError = overviewRangeError(period)
  const validPeriod = !rangeError
  const periodLabel = machineCostPeriodPresets.find((preset) => preset.id === filters.preset)?.label
  const rangeLabel = validPeriod ? `${period.start} → ${period.end}` : rangeError
  const requestKey = `${account.id}:${branch?.id}:${selectedMachine?.id}:${timezone}:${filters.preset}:${period.start}:${period.end}:${refreshVersion}`
  const [gate] = useState(createOverviewRequestGate)
  const [result, setResult] = useState(null)
  const currentResult = result?.key === requestKey ? result : null
  const projection = currentResult?.data?.projection ?? null
  const costSummary = currentResult?.data?.costSummary ?? null
  const loading = Boolean(selectedMachine && validPeriod && !currentResult)
  const error = currentResult?.error
  const refresh = () => setRefreshVersion((value) => value + 1)

  useEffect(() => {
    if (!selectedMachine || !validPeriod) return undefined
    const args = { accountId: account.id, machineId: selectedMachine.id, periodStart: period.start, periodEnd: period.end, periodPreset: filters.preset }
    gate.run(async () => {
      const [projection, costSummary] = await Promise.all([
        loadClickTargetProjection(args),
        // Existing capability failures keep cost unavailable; never retain old cost.
        loadMachineCostPeriod({ ...args, summaryOnly: true }).catch(() => null),
      ])
      return { projection, costSummary }
    }, (next) => setResult({ ...next, key: requestKey }))
    return () => gate.invalidate()
  }, [account.id, filters.preset, gate, period.end, period.start, requestKey, selectedMachine, validPeriod])

  const dailyRows = useMemo(() => normalizeDailyPerformance(projection?.daily), [projection])
  const [statusLabel, statusTone] = targetStatusPresentation(projection?.target_status)
  const requiredPace = projection?.required_pace_status === 'NO_ACTIVE_DAYS_REMAINING' ? { value: '—', hint: 'No active days remain in this period' } : requiredPacePresentation(projection)
  const expectedTargetLabel = ['this_month', 'this_year'].includes(filters.preset) ? 'Expected by today' : 'Allocated target'
  const selectedCardLabel = filters.preset === 'this_month' ? 'Expected by Today' : filters.preset === 'this_year' ? 'Expected to Date' : periodLabel
  const notConfigured = projection?.target_status === 'NOT_CONFIGURED'
  const todayRow = useMemo(() => dailyRows.find((row) => row.date === resolveMachineCostPeriod({ preset: 'today', timezone: projection?.period.timezone || timezone }).start), [dailyRows, projection, timezone])
  const todayContext = todayRow ? todayContextPresentation(todayRow) : null

  return (
    <div className="page-stack overview-page">
      <PageHeader eyebrow="Live workspace" title={`${account?.name} · ${branch?.name ?? 'All branches'}`} description="Operational click target and pace for the selected period." />

      <section className="machine-cost-filters glass-surface" aria-label="Overview filters">
        <label><span>Machine</span><select disabled={!activeMachines.length} value={selectedMachine?.id ?? ''} onChange={(event) => setSelection({ machineId: event.target.value })}>{activeMachines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label>
        <PeriodFields filters={filters} setFilters={setFilters} />
        <div className="machine-cost-period-readout"><CalendarRange size={16} /><span>{rangeLabel}<small>{timezone} operational dates{selectedMachine?.timezone && selectedMachine.timezone !== timezone ? ` · Machine boundaries: ${selectedMachine.timezone}` : ''}</small></span></div>
        <button className="secondary-button" type="button" onClick={refresh} disabled={loading || !validPeriod || !selectedMachine}><RefreshCcw size={15} />Refresh</button>
      </section>
      {!validPeriod && <div className="inline-error" role="alert">{rangeError}</div>}

      {machinesLoading ? null : machinesError ? <div className="inline-error" role="alert">{userErrorMessage(machinesError, 'Machine data is temporarily unavailable.')}</div> : activeMachines.length === 0 ? (
        <section className="starting-state glass-surface"><div><span className="card-kicker">Starting point</span><h3>{branch ? 'Your operations workspace is ready' : 'Create your first branch'}</h3><p>{branch ? 'No fabricated activity or KPI data is shown. Real operational insights will appear as your team begins using A3 Tracker.' : 'Branches establish the operational scope for machines, people, and inventory.'}</p></div>{!branch && can('settings.view') ? <button className="primary-button" type="button" onClick={() => navigate?.('/settings')}>Open Settings</button> : <div className="starting-state-line"><span /></div>}</section>
      ) : (
        <>
          {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Click target could not be loaded for this machine.')}</div>}

          {loading && !projection ? (
            <div className="machine-loading-state glass-surface"><RefreshCcw className="spin" size={24} /><strong>Loading operational pace…</strong></div>
          ) : notConfigured ? (
            <section className="overview-target-empty glass-surface">
              <span className="section-icon"><Gauge size={21} /></span>
              <div>
                <h3>Click target not fully configured for this period.</h3>
                <p>{canManageTargets ? 'Set a monthly click target for each month in the selected range.' : 'Target not configured. Ask a workspace owner or admin to set one.'}</p>
              </div>
              {canManageTargets && <button className="primary-button" type="button" onClick={() => navigate?.('/settings/click-targets')}><SettingsIcon size={15} />Set monthly target</button>}
            </section>
          ) : projection && (
            <section className="overview-target-hero glass-surface">
              <header>
                <div><span className="card-kicker">{periodLabel.toUpperCase()} CLICK TARGET</span><h2>{formatClicks(projection.actual)} / {formatClicks(projection.period_target)}</h2><p>{formatPercentage(projection.achievement_percentage)} achieved</p></div>
                {canManageTargets && <button className="secondary-button compact-button" type="button" onClick={() => navigate?.('/settings/click-targets')}><SettingsIcon size={14} />Manage target</button>}
              </header>
              <div className={`overview-target-status tone-${statusTone}`}><TrendingUp size={16} /><span>{statusLabel}</span>{projection.pace_variance != null && <strong>{formatSignedClicks(projection.pace_variance)}</strong>}</div>
              <dl className="overview-target-metrics">
                <div><dt>{expectedTargetLabel}</dt><dd>{formatClicks(projection.expected_by_today)}</dd></div>
                <div><dt>Remaining</dt><dd>{formatClicks(projection.remaining)}</dd></div>
                <div><dt>Active days remaining</dt><dd>{projection.active_days_remaining}</dd></div>
                <div><dt>Required pace</dt><dd>{requiredPace.value}<small>{requiredPace.hint}</small></dd></div>
              </dl>
            </section>
          )}

          {projection && (
            <>
              <section className="overview-period-grid" aria-label="Selected period cost and click progress">
                <CostPerClickCard summary={costSummary} periodLabel={rangeLabel} />
                <PeriodCard label={selectedCardLabel} card={projection.selected} />
                <PurchaseValueCard summary={costSummary?.purchase_summary} periodLabel={rangeLabel} />
                <ComponentConsumptionCard summary={costSummary} periodLabel={rangeLabel} />
              </section>
              <DailyClickPerformanceChart rows={dailyRows} todayContext={todayContext} />
            </>
          )}
        </>
      )}
    </div>
  )
}
