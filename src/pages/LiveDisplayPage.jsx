import { AlertTriangle, RefreshCcw } from 'lucide-react'
import { useTenant } from '../features/account/useTenant.js'
import { overviewContextTimezone } from '../features/overview/overviewPeriod.js'
import { useMachine } from '../features/machines/useMachine.js'
import { isValidMachineId } from '../hooks/useAppRoute.js'
import { useLiveDisplayData } from '../features/liveDisplay/useLiveDisplayData.js'
import { latestEffectiveReading, recentActivityRows, targetSummaryFromProjection, todayActualFromProjection } from '../features/liveDisplay/liveDisplayModel.js'
import { LiveDisplayHeader } from '../features/liveDisplay/LiveDisplayHeader.jsx'
import { CurrentCounterPanel } from '../features/liveDisplay/CurrentCounterPanel.jsx'
import { MonthlyTargetPanel } from '../features/liveDisplay/MonthlyTargetPanel.jsx'
import { RecentActivityStrip } from '../features/liveDisplay/RecentActivityStrip.jsx'
import { SyncStatusFooter } from '../features/liveDisplay/SyncStatusFooter.jsx'
import { userErrorMessage } from '../lib/appErrors.js'

function LiveDisplayMessage({ title, detail }) {
  return (
    <div className="live-display-root live-display-message-root">
      <div className="live-display-message">
        <AlertTriangle size={40} />
        <h1>{title}</h1>
        {detail && <p>{detail}</p>}
      </div>
    </div>
  )
}

/**
 * Fixed deep link for a production-floor TV (see App.jsx: rendered outside
 * AppShell, with no sidebar/topbar). machineId is required and never falls
 * back to another machine - an invalid or missing one is a display-safe
 * message, not a silent redirect, and never reaches the data layer.
 */
export function LiveDisplayPage({ machineId }) {
  const { account, branch } = useTenant()
  const machineIdValid = isValidMachineId(machineId)
  const { machine, isLoading: machineLoading, error: machineError } = useMachine(account?.id, branch?.id, machineIdValid ? machineId : null)
  const timezone = overviewContextTimezone(account, branch)

  const { status, history, projection, error, lastSyncedAt } = useLiveDisplayData({
    accountId: account?.id,
    machineId: machineIdValid ? machineId : null,
    timezone,
    enabled: machineIdValid && Boolean(account?.id) && Boolean(machine),
  })

  if (!machineIdValid) {
    return <LiveDisplayMessage title="Machine ID required" detail="This display's URL must include a specific machine, for example /display/live/<machine-id>." />
  }

  if (machineLoading) {
    return <LiveDisplayMessage title="Loading display…" />
  }

  if (machineError || !machine) {
    return <LiveDisplayMessage title="Machine not available" detail={userErrorMessage(machineError, 'This machine could not be loaded for the current workspace.')} />
  }

  const model = machine.machine_models
  const machineLabel = [model?.manufacturers?.name, model?.name].filter(Boolean).join(' ') || null
  const latestReading = latestEffectiveReading(history)
  const todayActual = todayActualFromProjection(projection, timezone)
  const targetSummary = targetSummaryFromProjection(projection)
  const activityRows = recentActivityRows(history)

  return (
    <div className="live-display-root">
      <LiveDisplayHeader accountName={account?.name} machineCode={machine.machine_code} machineLabel={machineLabel} timezone={timezone} />

      {status === 'loading' ? (
        <div className="live-display-loading"><RefreshCcw className="spin" size={28} /><span>Loading live production data…</span></div>
      ) : status === 'empty' ? (
        <div className="live-display-empty"><span>No counter readings recorded yet for this machine.</span></div>
      ) : (
        <>
          <div className="live-display-grid">
            <CurrentCounterPanel latestReading={latestReading} todayActual={todayActual} timezone={timezone} />
            <MonthlyTargetPanel summary={targetSummary} />
          </div>
          <RecentActivityStrip rows={activityRows} timezone={timezone} />
        </>
      )}

      <SyncStatusFooter status={status} lastSyncedAt={lastSyncedAt} timezone={timezone} />
      {error && status === 'offline' && <span className="live-display-sr-only" role="status">{userErrorMessage(error, 'Live data could not be refreshed.')}</span>}
    </div>
  )
}
