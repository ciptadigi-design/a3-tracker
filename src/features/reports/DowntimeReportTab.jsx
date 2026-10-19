import { createElement, useCallback, useEffect, useRef, useState } from 'react'
import { AlertTriangle, BarChart3, Clock3, Gauge, RefreshCcw, Wrench } from 'lucide-react'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadDowntimeOverview } from '../../services/downtime.js'
import { causeLabel, describeOwnerKpis, formatProductionDowntimeMinutes } from '../downtime/downtimeModel.js'

function Kpi({ icon, label, value, hint, tone = 'blue' }) {
  return <article className="report-kpi glass-surface"><span className={`report-kpi-icon tone-${tone}`}>{createElement(icon, { size: 19 })}</span><div><span>{label}</span><strong>{value}</strong><small>{hint}</small></div></article>
}

/** Horizontal bars reusing the Reports ranking styles; the longest row is the scale. */
function Bars({ title, description, rows, valueOf, labelOf, formatValue, emptyDetail }) {
  const maximum = Math.max(0, ...rows.map((row) => Number(valueOf(row) ?? 0)))
  return (
    <section className="report-chart glass-surface" aria-label={title}>
      <header><div><span className="card-kicker">Downtime</span><h2>{title}</h2><p>{description}</p></div></header>
      {rows.length ? (
        <div className="report-ranking">
          {rows.map((row, index) => (
            <div key={`${labelOf(row)}-${index}`}>
              <div><strong>{labelOf(row)}</strong><span>{formatValue(row)}</span></div>
              <span className="report-rank-track"><i className="tone-blue" style={{ width: `${maximum ? (Number(valueOf(row) ?? 0) / maximum) * 100 : 0}%` }} /></span>
            </div>
          ))}
        </div>
      ) : <div className="report-chart-empty">{emptyDetail}</div>}
    </section>
  )
}

/**
 * Reports > Downtime. Presentation of GET /downtime/overview?detail=1 for the page's
 * existing branch / machine / period filters; every figure is computed server-side from
 * Settings > Jadwal Operasional, and the server enforces branch authorization.
 */
export function DowntimeReportTab({ account, branch, machineId, period, refreshVersion, onData }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [isLoading, setIsLoading] = useState(true)

  const requestId = useRef(0)
  const load = useCallback(async () => {
    const request = ++requestId.current
    setIsLoading(true); setError(null)
    try {
      const next = await loadDowntimeOverview({ accountId: account.id, branchId: branch?.id, machineId: machineId || undefined, periodStart: period.start, periodEnd: period.end, detail: 1 })
      if (requestId.current !== request) return
      setData(next); onData?.(next)
    } catch (loadError) {
      if (requestId.current !== request) return
      setData(null); onData?.(null); setError(loadError)
    } finally { if (requestId.current === request) setIsLoading(false) }
  }, [account.id, branch?.id, machineId, period.start, period.end, onData])
  useEffect(() => { load() }, [load, refreshVersion])

  if (isLoading && !data) return <div className="report-loading glass-surface"><RefreshCcw className="spin" size={23} /><strong>Memuat analitik downtime…</strong></div>
  if (error) return <div className="inline-error" role="alert">{userErrorMessage(error, 'Analitik downtime tidak dapat dimuat.')}</div>
  if (!data) return null

  const kpi = describeOwnerKpis(data)
  const hasEvents = data.event_count > 0
  const trendRows = data.trend ?? []
  return (
    <>
      <div className="report-section-heading"><div><span className="card-kicker">Downtime</span><h2>Analitik Downtime</h2><p>Berdasarkan jadwal produksi di Pengaturan › Jadwal Operasional. Durasi berjalan (elapsed) tidak dicampur dengan downtime produksi.</p></div></div>
      {kpi.scheduleMissing && <p className="schedule-banner">Belum dikonfigurasi: jadwal produksi belum diatur untuk cakupan ini, sehingga Ketersediaan dan Downtime Produksi belum dapat dihitung. Jumlah insiden dan waktu pemulihan tetap ditampilkan.</p>}
      {kpi.schedulePartial && <p className="schedule-banner">{data.machine_count - data.configured_machine_count} dari {data.machine_count} mesin belum memiliki jadwal produksi dan tidak dihitung dalam Ketersediaan maupun Downtime Produksi.</p>}
      <section className="report-kpi-grid compact" aria-label="Ringkasan downtime">
        <Kpi icon={Gauge} label="Ketersediaan Mesin" value={kpi.availability} hint="Waktu produksi terjadwal dikurangi downtime produksi" tone="green" />
        <Kpi icon={Clock3} label="Downtime Produksi" value={kpi.productionDowntime} hint="Hanya yang beririsan dengan jam produksi" tone="purple" />
        <Kpi icon={AlertTriangle} label="Jumlah Downtime" value={kpi.eventCount} hint={kpi.ongoingCount > 0 ? `${kpi.ongoingCount} masih berjalan` : 'Insiden pada periode ini'} tone="warning" />
        <Kpi icon={Wrench} label="Rata-rata Pemulihan" value={kpi.recovery} hint={kpi.completedCount > 0 ? `${kpi.completedCount} insiden selesai pada periode ini` : 'Hanya insiden yang sudah selesai'} />
      </section>

      {!hasEvents ? <div className="report-empty glass-surface"><BarChart3 size={26} /><strong>Tidak ada downtime pada periode ini</strong><span>Tidak ada insiden downtime yang tercatat untuk cakupan dan periode yang dipilih.</span></div> : (
        <>
          <Bars title="Tren Downtime Produksi" description={trendRows.length > 0 && trendRows[0].bucket.length === 7 ? 'Per bulan' : 'Per hari'} rows={trendRows}
            valueOf={(row) => row.production_downtime_minutes} labelOf={(row) => row.bucket} formatValue={(row) => formatProductionDowntimeMinutes(row.production_downtime_minutes)} emptyDetail="Belum ada data tren." />
          <Bars title="Mesin dengan Downtime Produksi Tertinggi" description="Diurutkan menurut downtime produksi" rows={data.ranking ?? []}
            valueOf={(row) => row.production_downtime_minutes} labelOf={(row) => `${row.machine_code}${row.machine_name ? ` · ${row.machine_name}` : ''}`}
            formatValue={(row) => `${formatProductionDowntimeMinutes(row.production_downtime_minutes)} · ${row.event_count}×`} emptyDetail="Belum ada mesin dengan downtime." />
          <div className="report-two-column">
            <Bars title="Penyebab Paling Sering" description="Dari insiden yang tercatat" rows={data.causes ?? []} valueOf={(row) => row.count} labelOf={(row) => causeLabel(row.cause)} formatValue={(row) => `${row.count}×`} emptyDetail="Belum ada penyebab tercatat." />
            <Bars title="Kode Error Paling Sering" description="Hanya insiden dengan kode error" rows={data.error_codes ?? []} valueOf={(row) => row.count} labelOf={(row) => row.error_code} formatValue={(row) => `${row.count}×`} emptyDetail="Tidak ada kode error tercatat." />
          </div>
        </>
      )}
    </>
  )
}
