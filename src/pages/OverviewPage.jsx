import { useCallback, useEffect, useMemo, useState } from 'react'
import { Gauge, RefreshCcw, Settings as SettingsIcon, TrendingUp } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { useMachines } from '../features/machines/useMachines.js'
import { CANONICAL_PERIOD_TIMEZONE, resolveMachineCostPeriod } from '../features/machineCost/machineCostPeriods.js'
import { primaryCostPerClickPresentation } from '../features/machineCost/machineCostPresentation.js'
import { formatIdrUnit } from '../features/machineCost/currencyFormat.js'
import { loadMachineCostPeriod } from '../services/machineCost.js'
import { formatClicks, formatPercentage, formatSignedClicks, normalizeDailyPerformance, periodCardPresentation, requiredPacePresentation, targetStatusPresentation, todayContextPresentation } from '../features/clickTargets/clickTargetModel.js'
import { DailyClickPerformanceChart } from '../features/clickTargets/DailyClickPerformanceChart.jsx'
import { loadClickTargetProjection } from '../services/clickTargets.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { userErrorMessage } from '../lib/appErrors.js'

const monthFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', timeZone: 'UTC' })

function currentYearMonth(timezone) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit' }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return { year: Number(values.year), month: Number(values.month) }
}

function todayDateKey(timezone) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}

function CostPerClickCard({ summary, monthLabel }) {
  const presentation = primaryCostPerClickPresentation(summary, formatIdrUnit)
  return <article className="overview-period-card glass-surface">
    <span className="card-kicker">Cost / Click</span>
    <strong>{presentation.value}</strong>
    <span className="overview-period-target">Standard machine cost</span>
    <small>{monthLabel}</small>
  </article>
}

function PeriodCard({ label, card }) {
  const presentation = periodCardPresentation(card)
  return <article className={`overview-period-card glass-surface tone-${presentation.tone}`}>
    <span className="card-kicker">{label}</span>
    <strong>{presentation.actual}</strong>
    <span className="overview-period-target">{presentation.planned === 'Not configured' ? 'Not configured' : `of ${presentation.planned}`}</span>
    {presentation.achievement && <em>{presentation.achievement}</em>}
    {presentation.varianceLabel && <small>{presentation.varianceLabel}</small>}
  </article>
}

export function OverviewPage({ navigate }) {
  const { user } = useAuth()
  const { account, branch, membership, isPlatformSuperuser } = useTenant()
  const { machines, isLoading: machinesLoading, error: machinesError } = useMachines(account?.id, branch?.id)
  const activeMachines = useMemo(() => machines.filter((machine) => machine.is_active !== false && machine.status !== 'retired'), [machines])
  const canManageTargets = isPlatformSuperuser || ['owner', 'admin'].includes(membership?.role)

  const selectionKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch?.id, feature: 'overview-machine', entityId: 'selection' })
  const { value: selection, setUIState: setSelection } = usePersistentUIState({ uiStateKey: selectionKey, initialValue: { machineId: null }, validate: (v) => v && typeof v === 'object' })
  const selectedMachine = activeMachines.find((machine) => machine.id === selection.machineId) ?? activeMachines[0] ?? null

  useEffect(() => {
    if (selectedMachine && selectedMachine.id !== selection.machineId) setSelection({ machineId: selectedMachine.id })
  }, [selectedMachine, selection.machineId, setSelection])

  const timezone = selectedMachine?.timezone || branch?.timezone || account.default_timezone || CANONICAL_PERIOD_TIMEZONE
  const { year, month } = useMemo(() => currentYearMonth(timezone), [timezone])

  const [projection, setProjection] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const refresh = useCallback(async () => {
    if (!selectedMachine) { setProjection(null); setLoading(false); return }
    setLoading(true); setError(null)
    try { setProjection(await loadClickTargetProjection({ machineId: selectedMachine.id, year, month })) }
    catch (loadError) { setError(loadError); setProjection(null) }
    finally { setLoading(false) }
  }, [selectedMachine, year, month])

  useEffect(() => { refresh() }, [refresh])

  const [costSummary, setCostSummary] = useState(null)
  useEffect(() => {
    let active = true
    if (!selectedMachine) { setCostSummary(null); return undefined }
    const period = resolveMachineCostPeriod({ preset: 'this_month', timezone })
    if (!period.start || !period.end) { setCostSummary(null); return undefined }
    loadMachineCostPeriod({ accountId: account.id, machineId: selectedMachine.id, periodStart: period.start, periodEnd: period.end })
      .then((summary) => { if (active) setCostSummary(summary) })
      .catch(() => { if (active) setCostSummary(null) })
    return () => { active = false }
  }, [account.id, selectedMachine, timezone])

  const monthLabel = monthFormatter.format(new Date(Date.UTC(2000, month - 1, 1)))
  const dailyRows = useMemo(() => normalizeDailyPerformance(projection?.daily), [projection])
  const [statusLabel, statusTone] = targetStatusPresentation(projection?.target_status)
  const requiredPace = requiredPacePresentation(projection)
  const notConfigured = projection?.target_status === 'NOT_CONFIGURED'
  const todayRow = useMemo(() => dailyRows.find((row) => row.date === todayDateKey(timezone)), [dailyRows, timezone])
  const todayContext = todayContextPresentation(todayRow)

  return (
    <div className="page-stack overview-page">
      <PageHeader eyebrow="Live workspace" title={`${account?.name} · ${branch?.name ?? 'All branches'}`} description="Operational click target and pace for the selected branch." />

      {machinesLoading ? null : machinesError ? <div className="inline-error" role="alert">{userErrorMessage(machinesError, 'Machine data is temporarily unavailable.')}</div> : activeMachines.length === 0 ? (
        <section className="starting-state glass-surface"><div><span className="card-kicker">Starting point</span><h3>Your operations workspace is ready</h3><p>No fabricated activity or KPI data is shown. Real operational insights will appear as your team begins using A3 Tracker.</p></div><div className="starting-state-line"><span /></div></section>
      ) : (
        <>
          {activeMachines.length > 1 && (
            <section className="overview-machine-select glass-surface">
              <label><span>Machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => setSelection({ machineId: event.target.value })}>{activeMachines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label>
              <button className="secondary-button" type="button" onClick={refresh} disabled={loading}><RefreshCcw size={15} />Refresh</button>
            </section>
          )}

          {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Click target could not be loaded for this machine.')}</div>}

          {loading && !projection ? (
            <div className="machine-loading-state glass-surface"><RefreshCcw className="spin" size={24} /><strong>Loading operational pace…</strong></div>
          ) : notConfigured ? (
            <section className="overview-target-empty glass-surface">
              <span className="section-icon"><Gauge size={21} /></span>
              <div>
                <h3>No click target configured for {monthLabel}.</h3>
                <p>{canManageTargets ? 'Set a monthly click target to track daily and weekly pace for this machine.' : 'Target not configured. Ask a workspace owner or admin to set one.'}</p>
              </div>
              {canManageTargets && <button className="primary-button" type="button" onClick={() => navigate?.('/settings/click-targets')}><SettingsIcon size={15} />Set monthly target</button>}
            </section>
          ) : projection && (
            <section className="overview-target-hero glass-surface">
              <header>
                <div><span className="card-kicker">{monthLabel.toUpperCase()} CLICK TARGET</span><h2>{formatClicks(projection.actual_month_to_date)} / {formatClicks(projection.monthly_target)}</h2><p>{formatPercentage(projection.achievement_percentage)} achieved</p></div>
                {canManageTargets && <button className="secondary-button compact-button" type="button" onClick={() => navigate?.('/settings/click-targets')}><SettingsIcon size={14} />Manage target</button>}
              </header>
              <div className={`overview-target-status tone-${statusTone}`}><TrendingUp size={16} /><span>{statusLabel}</span>{projection.variance != null && <strong>{formatSignedClicks(projection.variance)}</strong>}</div>
              <dl className="overview-target-metrics">
                <div><dt>Expected by today</dt><dd>{formatClicks(projection.planned_month_to_date)}</dd></div>
                <div><dt>Remaining</dt><dd>{formatClicks(projection.remaining_target)}</dd></div>
                <div><dt>Active days remaining</dt><dd>{projection.active_days_remaining}</dd></div>
                <div><dt>Required pace</dt><dd>{requiredPace.value}<small>{requiredPace.hint}</small></dd></div>
              </dl>
            </section>
          )}

          {projection && (
            <>
              <section className="overview-period-grid" aria-label="Cost per click, week, and month progress">
                <CostPerClickCard summary={costSummary} monthLabel={monthLabel} />
                <PeriodCard label="This Week" card={projection.week} />
                <PeriodCard label="This Month" card={projection.month} />
              </section>
              <DailyClickPerformanceChart rows={dailyRows} todayContext={todayContext} />
            </>
          )}
        </>
      )}
    </div>
  )
}
