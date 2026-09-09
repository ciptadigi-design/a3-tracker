import { useCallback, useEffect, useMemo, useState } from 'react'
import { CalendarOff, Edit3, Gauge, History, Plus, RefreshCcw, Trash2 } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useAuth } from '../features/auth/useAuth.js'
import { useTenant } from '../features/account/useTenant.js'
import { useMachines } from '../features/machines/useMachines.js'
import { exceptionTypes, formatClicks } from '../features/clickTargets/clickTargetModel.js'
import { CalendarExceptionDialog } from '../features/clickTargets/CalendarExceptionDialog.jsx'
import { SetTargetDialog } from '../features/clickTargets/SetTargetDialog.jsx'
import { createCalendarException, loadCalendarExceptions, loadClickTargetHistory, loadClickTargetProjection, removeCalendarException, saveMachineClickTarget } from '../services/clickTargets.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { userErrorMessage } from '../lib/appErrors.js'

const monthFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', timeZone: 'UTC' })
const exceptionTypeLabels = Object.fromEntries(exceptionTypes)

function monthOptions() {
  return Array.from({ length: 12 }, (_, index) => ({ value: index + 1, label: monthFormatter.format(new Date(Date.UTC(2000, index, 1))) }))
}

export function ClickTargetSettingsPage() {
  const { user } = useAuth()
  const { account, branch, membership, isPlatformSuperuser } = useTenant()
  const canManage = isPlatformSuperuser || ['owner', 'admin'].includes(membership?.role)
  const { machines } = useMachines(account?.id, branch?.id)
  const activeMachines = useMemo(() => machines.filter((machine) => machine.status !== 'retired'), [machines])

  const filterKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch?.id, feature: 'click-target-settings', entityId: 'filters' })
  const now = new Date()
  const { value: filters, setUIState: setFilters } = usePersistentUIState({ uiStateKey: filterKey, initialValue: { machineId: null, year: now.getUTCFullYear(), month: now.getUTCMonth() + 1 }, validate: (v) => v && typeof v === 'object' })
  const selectedMachine = activeMachines.find((machine) => machine.id === filters.machineId) ?? activeMachines[0] ?? null

  useEffect(() => {
    if (selectedMachine && selectedMachine.id !== filters.machineId) setFilters((current) => ({ ...current, machineId: selectedMachine.id }))
  }, [filters, selectedMachine, setFilters])

  const [projection, setProjection] = useState(null)
  const [exceptionRows, setExceptionRows] = useState([])
  const [history, setHistory] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [targetDialog, setTargetDialog] = useState(false)
  const [exceptionDialog, setExceptionDialog] = useState(false)

  const refresh = useCallback(async () => {
    if (!selectedMachine) return
    setLoading(true); setError(null)
    try {
      const [projectionData, exceptions, historyRows] = await Promise.all([
        loadClickTargetProjection({ machineId: selectedMachine.id, year: filters.year, month: filters.month }),
        loadCalendarExceptions({ machineId: selectedMachine.id, year: filters.year, month: filters.month }),
        loadClickTargetHistory({ machineId: selectedMachine.id, year: filters.year, month: filters.month }),
      ])
      setProjection(projectionData); setExceptionRows(exceptions); setHistory(historyRows)
    } catch (loadError) { setError(loadError) } finally { setLoading(false) }
  }, [selectedMachine, filters.year, filters.month])

  useEffect(() => { refresh() }, [refresh])

  async function saveTarget({ monthlyClickTarget, reason, clientRequestId }) {
    await saveMachineClickTarget({ machineId: selectedMachine.id, targetYear: filters.year, targetMonth: filters.month, monthlyClickTarget, reason, clientRequestId })
    await refresh()
  }

  async function addException({ calendarDate, exceptionType, notes, clientRequestId }) {
    await createCalendarException({ machineId: selectedMachine.id, calendarDate, exceptionType, notes, clientRequestId })
    await refresh()
  }

  async function deleteException(id) {
    await removeCalendarException({ exceptionId: id })
    await refresh()
  }

  const monthLabel = monthFormatter.format(new Date(Date.UTC(2000, filters.month - 1, 1)))

  return <div className="page-stack settings-page">
    <PageHeader eyebrow={`${account?.name} · Settings · Operations`} title="Click Targets" description="Configure the monthly click target and operational calendar for each machine." />

    <section className="machine-cost-filters glass-surface" aria-label="Click target filters">
      <label><span>Machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => setFilters((current) => ({ ...current, machineId: event.target.value }))} disabled={!activeMachines.length}><option value="">{activeMachines.length ? 'Select machine' : 'No machines'}</option>{activeMachines.map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label>
      <label><span>Year</span><input type="number" value={filters.year} onChange={(event) => setFilters((current) => ({ ...current, year: Number(event.target.value) }))} /></label>
      <label><span>Month</span><select value={filters.month} onChange={(event) => setFilters((current) => ({ ...current, month: Number(event.target.value) }))}>{monthOptions().map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
      <button className="secondary-button" type="button" onClick={refresh} disabled={loading || !selectedMachine}><RefreshCcw size={15} />Refresh</button>
    </section>

    {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Click target could not be loaded.')}</div>}
    {!selectedMachine ? <div className="machine-empty-state glass-surface"><h3>No machine selected</h3></div> : loading && !projection ? <div className="machine-loading-state glass-surface"><RefreshCcw className="spin" size={24} /><strong>Loading…</strong></div> : projection && <>
      <section className="settings-card glass-surface">
        <header><div><span className="card-kicker">Target configuration</span><h2>{monthLabel} {filters.year}</h2><p>{projection.monthly_target != null ? `${formatClicks(projection.monthly_target)} clicks across ${projection.active_days_total} active day${projection.active_days_total === 1 ? '' : 's'}.` : 'No target configured for this month.'}</p></div>{canManage && <button className="primary-button" type="button" onClick={() => setTargetDialog(true)}><Edit3 size={15} />{projection.monthly_target != null ? 'Revise target' : 'Set monthly target'}</button>}</header>
        <dl className="settings-detail-list">
          <div><dt>Monthly target</dt><dd>{formatClicks(projection.monthly_target)}</dd></div>
          <div><dt>Calendar days</dt><dd>{projection.calendar_days}</dd></div>
          <div><dt>Active days</dt><dd>{projection.active_days_total}</dd></div>
          <div><dt>Excluded days</dt><dd>{projection.excluded_days_total}</dd></div>
        </dl>
      </section>

      <section className="settings-card glass-surface">
        <header><div><span className="card-kicker">Operational calendar</span><h2>Excluded dates</h2><p>Every calendar date is active by default. Exclude specific dates for closures, maintenance, or events.</p></div>{canManage && <button className="primary-button" type="button" onClick={() => setExceptionDialog(true)}><Plus size={16} />Exclude a date</button>}</header>
        {exceptionRows.length === 0 ? <div className="machine-cost-empty compact"><CalendarOff size={20} /><strong>No excluded dates this month.</strong></div> : <div className="settings-row-list">{exceptionRows.map((row) => <article key={row.id}><span className="settings-row-icon"><CalendarOff size={18} /></span><div><strong>{row.calendar_date}</strong><span>{exceptionTypeLabels[row.exception_type] ?? row.exception_type}</span>{row.notes && <small>{row.notes}</small>}</div>{canManage && <div className="settings-row-actions"><button type="button" aria-label={`Remove exclusion on ${row.calendar_date}`} onClick={() => deleteException(row.id)}><Trash2 size={15} /></button></div>}</article>)}</div>}
      </section>

      <section className="settings-card glass-surface">
        <header><div><span className="card-kicker">Audit evidence</span><h2>Target history</h2></div><History size={18} /></header>
        {history.length === 0 ? <div className="machine-cost-empty compact"><Gauge size={20} /><strong>No revisions yet.</strong></div> : <div className="settings-audit-list">{history.map((row) => <div key={row.id}><strong>{row.previous_target == null ? 'Set' : 'Revised'} to {formatClicks(row.new_target)}</strong><span>{row.previous_target != null ? `from ${formatClicks(row.previous_target)} · ` : ''}{new Date(row.created_at).toLocaleString()}</span>{row.reason && <small>{row.reason}</small>}</div>)}</div>}
      </section>

      {targetDialog && <SetTargetDialog machine={selectedMachine} monthLabel={`${monthLabel} ${filters.year}`} currentTarget={projection.monthly_target} onClose={() => setTargetDialog(false)} onSave={saveTarget} />}
      {exceptionDialog && <CalendarExceptionDialog defaultDate={`${filters.year}-${String(filters.month).padStart(2, '0')}-01`} onClose={() => setExceptionDialog(false)} onSave={addException} />}
    </>}
  </div>
}
