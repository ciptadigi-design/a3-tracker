import { createElement, useMemo, useState } from 'react'
import { CalendarCheck2, CheckCircle2, Clock3, Gauge, ListChecks, Printer } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { CounterEntryCard } from '../features/counters/CounterEntryCard.jsx'
import { CounterHistory } from '../features/counters/CounterHistory.jsx'
import { calculateDailySummary, formatCounter } from '../features/counters/counterUtils.js'
import { useCounterHistory } from '../features/counters/useCounterHistory.js'
import { createDraftKey } from '../features/drafts/draftKeys.js'
import { migrateLegacyDailySelection } from '../features/drafts/draftStorage.js'
import { useTenant } from '../features/account/useTenant.js'
import { useAuth } from '../features/auth/useAuth.js'
import { useMachines } from '../features/machines/useMachines.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'

function SummaryCard({ icon, label, value, detail, tone }) {
  return <article className="daily-summary-card glass-surface"><span className={`daily-summary-icon ${tone}`}>{createElement(icon, { size: 21 })}</span><div><span>{label}</span><strong>{value}</strong><small>{detail}</small></div></article>
}

export function DailyPage() {
  const { user } = useAuth()
  const { account, branch, membership } = useTenant()
  const machinesState = useMachines(account?.id, branch?.id)
  const activeMachines = useMemo(() => machinesState.machines.filter((machine) => machine.is_active), [machinesState.machines])
  const selectionKey = createUIStateKey({ userId: user?.id, accountId: account?.id, branchId: branch?.id, feature: 'daily-counter', entityId: 'selected-machine' })
  const legacySelectionKey = createDraftKey({ userId: user?.id, accountId: account?.id, branchId: branch?.id, feature: 'daily-counter', entityId: 'selected-machine' })
  const machineSelection = usePersistentUIState({
    uiStateKey: selectionKey,
    initialValue: { machineId: '' },
    validate: (value) => value && typeof value.machineId === 'string',
    legacyDraftKey: legacySelectionKey,
    prepareLegacyState: () => migrateLegacyDailySelection(legacySelectionKey, user?.id, account?.id, branch?.id),
  })
  const selectedMachineId = machineSelection.value.machineId
  const selectedMachine = activeMachines.find((machine) => machine.id === selectedMachineId) ?? activeMachines[0] ?? null
  const counterState = useCounterHistory(account?.id, selectedMachine?.id)
  const timezone = selectedMachine?.timezone || branch?.timezone || account?.default_timezone || 'Asia/Jakarta'
  const summary = calculateDailySummary(counterState.history, timezone)
  const canCorrect = membership?.role === 'owner' || membership?.role === 'admin'
  const [success, setSuccess] = useState(null)

  async function handleRecorded() {
    await counterState.refresh()
    setSuccess('Counter saved. History and today’s summary are now up to date.')
  }

  async function handleCorrected(action) {
    await counterState.refresh()
    setSuccess(action === 'void' ? 'Latest reading was voided without deleting its history.' : 'Latest reading was superseded by an audited replacement.')
  }

  function handleMachineChange(machineId) {
    machineSelection.setUIState({ machineId })
    setSuccess(null)
  }

  if (machinesState.error) {
    return <div className="page-stack"><PageHeader eyebrow={`${account?.name} · ${branch?.name}`} title="Daily" description="Machine-centric daily counter workspace." /><div className="embedded-error glass-surface" role="alert"><strong>Machines could not be loaded.</strong><span>{machinesState.error.message}</span><button className="secondary-button" type="button" onClick={machinesState.refresh}>Try again</button></div></div>
  }

  return (
    <div className="page-stack daily-page">
      <PageHeader eyebrow={`${account?.name} · ${branch?.name}`} title="Daily counter" description="Record the cumulative Total Impressions shown on a physical machine. Usage is derived automatically." />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}

      <section className="daily-machine-context glass-surface">
        <div><span className="daily-context-icon"><Printer size={23} /></span><div><span className="card-kicker">Machine context</span><h2>{machinesState.isLoading ? 'Loading active machines…' : selectedMachine?.display_name || 'No active machine available'}</h2><p>{selectedMachine ? `${selectedMachine.machine_code} · ${selectedMachine.machine_models?.manufacturers?.name} ${selectedMachine.machine_models?.name}` : `Register or activate a machine in ${branch?.name} before entering counters.`}</p></div></div>
        {activeMachines.length > 0 && <label className="daily-machine-select"><span>Select machine</span><select value={selectedMachine?.id ?? ''} onChange={(event) => handleMachineChange(event.target.value)}>{activeMachines.map((machine) => <option key={machine.id} value={machine.id}>{machine.display_name} · {machine.machine_code}</option>)}</select></label>}
      </section>

      {!machinesState.isLoading && !selectedMachine ? <section className="daily-no-machine glass-surface"><span className="empty-machine-icon"><Printer size={38} /></span><h2>No active machine in this branch.</h2><p>Daily counter input becomes available after a physical machine is registered and active.</p></section>
        : selectedMachine && <>
          <section className="daily-summary-grid" aria-label="Daily counter summary">
            <SummaryCard icon={Gauge} label="Last Counter" value={formatCounter(summary.lastReading?.reading_value)} detail={summary.lastReading ? 'Latest effective reading' : 'No baseline yet'} tone="blue" />
            <SummaryCard icon={CalendarCheck2} label="Today's Usage" value={summary.todayUsage == null ? '—' : `+${formatCounter(summary.todayUsage)}`} detail={summary.todayEntryCount ? summary.todayUsage == null ? 'Baseline only; no prior delta' : 'Sum of today’s database-derived deltas' : 'No entries today'} tone="green" />
            <SummaryCard icon={Clock3} label="Last Input" value={summary.lastReading ? new Intl.DateTimeFormat('en-GB', { timeZone: timezone, hour: '2-digit', minute: '2-digit' }).format(new Date(summary.lastReading.observed_at)) : '—'} detail={summary.lastReading ? new Intl.DateTimeFormat('en-GB', { timeZone: timezone, dateStyle: 'medium' }).format(new Date(summary.lastReading.observed_at)) : 'No input recorded'} tone="purple" />
            <SummaryCard icon={ListChecks} label="Today's Entries" value={String(summary.todayEntryCount)} detail="Effective readings in machine timezone" tone="amber" />
          </section>
          <CounterEntryCard key={`${user.id}:${selectedMachine.id}`} accountId={account.id} branchId={branch.id} userId={user.id} machine={selectedMachine} lastReading={summary.lastReading} onRecorded={handleRecorded} />
          <CounterHistory history={counterState.history} profiles={counterState.profiles} currentUserId={user?.id} timezone={timezone} isLoading={counterState.isLoading} error={counterState.error} canCorrect={canCorrect} onRefresh={counterState.refresh} onCorrected={handleCorrected} />
        </>}
    </div>
  )
}
