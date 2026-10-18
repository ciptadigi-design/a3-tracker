import { useCallback, useEffect, useState } from 'react'
import { CalendarRange, CheckCircle2, Gauge, ListChecks, PowerOff, RefreshCcw, Timer } from 'lucide-react'
import { useTenant } from '../account/useTenant.js'
import { currentMonthPeriod, describeMachineStatus, formatAvailabilityPercent, formatProductionDowntimeMinutes, NOT_CONFIGURED_LABEL } from './downtimeModel.js'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadDowntimeOverview } from '../../services/downtime.js'

function OverviewKpi({ icon, label, value, hint, tone = 'blue' }) {
  return <article className="report-kpi glass-surface"><span className={`report-kpi-icon tone-${tone}`}>{icon}</span><div><span>{label}</span><strong>{value}</strong>{hint && <small>{hint}</small>}</div></article>
}

/**
 * "Kondisi Mesin" tab of the Maintenance page - presents the existing
 * Downtime Overview data (GET /downtime/overview). Downtime APIs, records,
 * and permissions are unchanged.
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

  const scheduleMissing = overview && overview.availability_percent == null && overview.production_downtime_minutes == null
  const stopped = overview?.attention ?? []

  return (
    <div className="machine-condition-section">
      <section className="machine-cost-filters machine-condition-filters glass-surface" aria-label="Filter kondisi mesin">
        <label><span>Cabang</span><select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}><option value="">Semua cabang</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <div className="machine-cost-period-readout"><CalendarRange size={16} /><span>{period.periodStart} → {period.periodEnd}<small>Bulan berjalan · {effectiveTimezone}</small></span></div>
        <button className="secondary-button" type="button" onClick={refresh} disabled={isLoading}><RefreshCcw size={15} className={isLoading ? 'spin' : ''} />Muat ulang</button>
      </section>

      {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Kondisi mesin tidak dapat dimuat.')}</div>}

      {isLoading && !overview ? <div className="report-loading glass-surface"><RefreshCcw className="spin" size={23} /><strong>Memuat kondisi mesin…</strong></div> : overview && (
        <>
          <section className="report-kpi-grid compact" aria-label="Ringkasan kondisi mesin">
            <OverviewKpi icon={<Gauge size={19} />} label="Ketersediaan Mesin" value={formatAvailabilityPercent(overview.availability_percent)} hint={scheduleMissing ? 'Jadwal produksi belum diatur' : 'Terhadap jam operasional'} />
            <OverviewKpi icon={<Timer size={19} />} label="Downtime Produksi" value={formatProductionDowntimeMinutes(overview.production_downtime_minutes)} hint={scheduleMissing ? 'Jadwal produksi belum diatur' : 'Dalam jam operasional'} tone="warning" />
            <OverviewKpi icon={<ListChecks size={19} />} label="Jumlah Gangguan" value={overview.event_count} hint="Kejadian downtime pada periode ini" tone="purple" />
            <OverviewKpi icon={<PowerOff size={19} />} label="Mesin Berhenti" value={stopped.length} hint="Sedang berhenti saat ini" tone={stopped.length > 0 ? 'warning' : 'green'} />
          </section>

          <section className="downtime-attention glass-surface" aria-label="Mesin perlu perhatian">
            <header><span className="card-kicker">Perlu perhatian</span><h2>Mesin Perlu Perhatian</h2></header>
            {stopped.length === 0 ? (
              <p className="downtime-attention-empty"><CheckCircle2 size={17} />Tidak ada mesin yang sedang berhenti.</p>
            ) : (
              <ul className="downtime-attention-list">
                {stopped.map((item) => {
                  const status = describeMachineStatus(item, effectiveTimezone)
                  return (
                    <li key={item.downtime_event_id}>
                      <div className="downtime-attention-main"><strong>{item.machine_name || item.machine_code}</strong><span>{[item.machine_code && item.machine_name ? item.machine_code : null, status.causeText, status.errorCode].filter(Boolean).join(' · ')}</span></div>
                      <dl className="downtime-attention-meta"><div><dt>Mulai</dt><dd>{status.startText}</dd></div><div><dt>Durasi</dt><dd>{status.durationText}</dd></div></dl>
                      <button className="secondary-button compact-button" type="button" onClick={() => navigate?.(`/machines/${item.machine_id}`)}>Lihat detail</button>
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
          {scheduleMissing && <p className="machine-condition-note">{NOT_CONFIGURED_LABEL}: ketersediaan dan downtime produksi dihitung setelah jadwal produksi diatur.</p>}
        </>
      )}
    </div>
  )
}
