import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, Gauge, ListChecks, PowerOff, RefreshCcw, Timer } from 'lucide-react'
import { useTenant } from '../account/useTenant.js'
import { causeLabel, currentMonthPeriod, elapsedMinutesBetween, formatAvailabilityPercent, formatDowntimeDateTime, formatElapsedMinutes, formatProductionDowntimeMinutes } from './downtimeModel.js'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadDowntimeOverview } from '../../services/downtime.js'

function OverviewKpi({ icon, label, value, hint, tone = 'blue' }) {
  return <article className="report-kpi glass-surface"><span className={`report-kpi-icon tone-${tone}`}>{icon}</span><div><span>{label}</span><strong>{value}</strong>{hint && <small>{hint}</small>}</div></article>
}

/**
 * "Kondisi Mesin" tab of the Maintenance page - reuses the Downtime Overview
 * data (GET /downtime/overview) that previously lived on its own standalone
 * /downtime page. Downtime APIs, records, and permissions are unchanged;
 * this is only a presentational home for the same data.
 */
export function MachineConditionSection({ navigate }) {
  const { account, branch, branches } = useTenant()
  const effectiveTimezone = branch?.timezone || account?.default_timezone || 'UTC'
  const [branchFilter, setBranchFilter] = useState('')
  const [overview, setOverview] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const period = currentMonthPeriod(effectiveTimezone)

  const refresh = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      setOverview(await loadDowntimeOverview({ accountId: account.id, branchId: branchFilter || undefined, periodStart: period.periodStart, periodEnd: period.periodEnd }))
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [account.id, branchFilter, period.periodEnd, period.periodStart])

  useEffect(() => { refresh() }, [refresh])

  return (
    <div className="machine-condition-section">
      <section className="report-filter-bar glass-surface" aria-label="Downtime overview filters">
        <label><span>Branch</span><select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}><option value="">All branches</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <button className="secondary-button" type="button" onClick={refresh} disabled={isLoading}><RefreshCcw size={15} className={isLoading ? 'spin' : ''} />Refresh</button>
      </section>
      <p className="machine-condition-period-note">Machine availability for {period.periodStart} through {period.periodEnd}.</p>

      {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Downtime overview could not be loaded.')}</div>}

      {isLoading && !overview ? <div className="report-loading glass-surface"><RefreshCcw className="spin" size={23} /><strong>Loading machine availability…</strong></div> : overview && (
        <>
          <section className="report-kpi-grid">
            <OverviewKpi icon={<Gauge size={19} />} label="Machine Availability" value={formatAvailabilityPercent(overview.availability_percent)} hint="Periode berjalan" />
            <OverviewKpi icon={<Timer size={19} />} label="Production Downtime" value={formatProductionDowntimeMinutes(overview.production_downtime_minutes)} hint="Terhadap jam operasional" tone="warning" />
            <OverviewKpi icon={<ListChecks size={19} />} label="Downtime Events" value={overview.event_count} hint="Semua downtime pada periode ini" tone="purple" />
            <OverviewKpi icon={<PowerOff size={19} />} label="Machine Attention" value={overview.attention.length} hint="Downtime aktif saat ini" tone="green" />
          </section>

          <section className="downtime-attention glass-surface" aria-label="Machines needing attention">
            <header><span className="card-kicker">Needs attention</span><h2>Machines currently in downtime</h2></header>
            {overview.attention.length === 0 ? <p className="downtime-notes-empty">No machines are currently in downtime.</p> : (
              <ul className="downtime-attention-list">
                {overview.attention.map((item) => (
                  <li key={item.downtime_event_id}>
                    <button type="button" className="downtime-attention-link" onClick={() => navigate?.(`/machines/${item.machine_id}`)}>
                      <strong>{item.machine_name || item.machine_code}</strong><span>{causeLabel(item.cause)}{item.error_code_text ? ` · ${item.error_code_text}` : ''}</span>
                    </button>
                    <div className="downtime-attention-meta"><span>{formatDowntimeDateTime(item.actual_start_at, effectiveTimezone)}</span><strong><AlertTriangle size={14} /> {formatElapsedMinutes(elapsedMinutesBetween(item.actual_start_at))}</strong></div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  )
}
