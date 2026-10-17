import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, Gauge, ListChecks, PowerOff, RefreshCcw, Timer } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { causeLabel, currentMonthPeriod, elapsedMinutesBetween, formatAvailabilityPercent, formatDowntimeDateTime, formatElapsedMinutes, formatProductionDowntimeMinutes } from '../features/downtime/downtimeModel.js'
import { userErrorMessage } from '../lib/appErrors.js'
import { loadDowntimeOverview } from '../services/downtime.js'

function OverviewKpi({ icon, label, value, hint, tone = 'blue' }) {
  return <article className="report-kpi glass-surface"><span className={`report-kpi-icon tone-${tone}`}>{icon}</span><div><span>{label}</span><strong>{value}</strong>{hint && <small>{hint}</small>}</div></article>
}

export function DowntimePage() {
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
    <div className="page-stack downtime-overview-page">
      <PageHeader eyebrow="Operational availability" title="Downtime & Maintenance" description={`Ketersediaan mesin periode ${period.periodStart} s/d ${period.periodEnd}.`} />

      <section className="report-filter-bar glass-surface" aria-label="Downtime overview filters">
        <label><span>Branch</span><select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}><option value="">Semua branch{branch ? ` (default: ${branch.name})` : ''}</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <button className="secondary-button" type="button" onClick={refresh} disabled={isLoading}><RefreshCcw size={15} className={isLoading ? 'spin' : ''} />Refresh</button>
      </section>

      {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Downtime overview tidak dapat dimuat.')}</div>}

      {isLoading && !overview ? <div className="report-loading glass-surface"><RefreshCcw className="spin" size={23} /><strong>Memuat ketersediaan mesin…</strong></div> : overview && (
        <>
          <section className="report-kpi-grid">
            <OverviewKpi icon={<Gauge size={19} />} label="Machine Availability" value={formatAvailabilityPercent(overview.availability_percent)} hint="Periode berjalan" />
            <OverviewKpi icon={<Timer size={19} />} label="Production Downtime" value={formatProductionDowntimeMinutes(overview.production_downtime_minutes)} hint="Terhadap jam operasional" tone="warning" />
            <OverviewKpi icon={<ListChecks size={19} />} label="Downtime Events" value={overview.event_count} hint="Semua downtime pada periode ini" tone="purple" />
            <OverviewKpi icon={<PowerOff size={19} />} label="Machine Attention" value={overview.attention.length} hint="Downtime aktif saat ini" tone="green" />
          </section>

          <section className="downtime-attention glass-surface" aria-label="Machines needing attention">
            <header><span className="card-kicker">Needs attention</span><h2>Mesin sedang downtime</h2></header>
            {overview.attention.length === 0 ? <p className="downtime-notes-empty">Tidak ada mesin yang sedang downtime.</p> : (
              <ul className="downtime-attention-list">
                {overview.attention.map((item) => (
                  <li key={item.downtime_event_id}>
                    <div><strong>{item.machine_name || item.machine_code}</strong><span>{causeLabel(item.cause)}{item.error_code_text ? ` · ${item.error_code_text}` : ''}</span></div>
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
