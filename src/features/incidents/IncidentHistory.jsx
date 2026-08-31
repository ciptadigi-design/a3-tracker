import { ArrowRight, ClipboardList, RefreshCcw } from 'lucide-react'
import { categoryLabels, incidentStatusLabels, incidentTypeLabels } from './incidentConstants.js'
import { formatIncidentDate, formatRupiah } from './incidentUtils.js'
import { userErrorMessage } from '../../lib/appErrors.js'
import { Pagination } from '../../components/ui/Pagination.jsx'
import { usePagination } from '../pagination/usePagination.js'

function HistoryValue({ label, children, className = '' }) {
  return <div className={`incident-history-value ${className}`}><span>{label}</span><strong>{children || '—'}</strong></div>
}

export function IncidentHistory({ incidents, machines, timezone, isLoading, error, onRefresh, onOpen, resetKey }) {
  const machineNames = new Map(machines.map((machine) => [machine.id, `${machine.machine_code} · ${machine.display_name}`]))
  const pagination = usePagination(incidents.length, resetKey)
  const visibleIncidents = incidents.slice(pagination.start, pagination.end)

  return (
    <section className="incident-history-card glass-surface">
      <header className="history-header"><div><span className="card-kicker">Riwayat nyata</span><h2>Human / Operational Error</h2><p>Log mengikuti scope Machine yang dipilih. Record voided tetap tersedia untuk audit.</p></div><button className="icon-button" type="button" onClick={onRefresh} disabled={isLoading} aria-label="Refresh riwayat error"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></header>
      {isLoading ? <div className="history-state"><RefreshCcw className="spin" size={23} /><strong>Memuat riwayat error…</strong></div>
        : error ? <div className="history-state error-state"><strong>Riwayat error tidak dapat dimuat.</strong><span>{userErrorMessage(error, 'Riwayat incident sementara tidak tersedia.')}</span><button className="secondary-button" type="button" onClick={onRefresh}>Coba lagi</button></div>
          : incidents.length === 0 ? <div className="history-state"><span className="history-empty-icon"><ClipboardList size={27} /></span><strong>Belum ada log error operasional.</strong><span>Log pertama akan muncul di sini setelah disimpan.</span></div>
            : <><div className="incident-history-list">{visibleIncidents.map((incident) => <article className={`incident-history-row incident-status-${incident.status}`} key={incident.id}>
              <HistoryValue label="Tanggal" className="incident-history-primary">{formatIncidentDate(incident.occurred_at, timezone)}</HistoryValue>
              <HistoryValue label="Konsumen / Produk"><span>{incident.customer_name_snapshot || 'Tanpa nama konsumen'}</span><small>{incident.product_name_snapshot || incident.invoice_number || 'Tanpa detail produk'}</small></HistoryValue>
              <HistoryValue label="Kategori / Jenis"><span>{categoryLabels[incident.category]}</span><small>{incidentTypeLabels[incident.incident_type]}</small></HistoryValue>
              <HistoryValue label="Machine">{incident.machine_id ? machineNames.get(incident.machine_id) || 'Machine tidak tersedia' : 'Branch / No specific machine'}</HistoryValue>
              <HistoryValue label="PIC">{incident.responsible_name_snapshot || 'Tidak dicatat'}</HistoryValue>
              <HistoryValue label="Rugi Bahan">{formatRupiah(incident.material_loss)}</HistoryValue>
              <HistoryValue label="Rugi Jasa">{formatRupiah(incident.service_loss)}</HistoryValue>
              <HistoryValue label="Assessed Loss" className="incident-history-loss">{formatRupiah(incident.assessed_loss)}</HistoryValue>
              <div className="incident-history-status"><span className={`incident-status-pill ${incident.status}`}>{incidentStatusLabels[incident.status]}</span></div>
              <div className="incident-history-action"><button className="secondary-button" type="button" onClick={() => onOpen(incident.id)}>Detail <ArrowRight size={15} /></button></div>
            </article>)}</div><Pagination total={incidents.length} {...pagination} onPageChange={pagination.setPage} onPageSizeChange={pagination.setPageSize} label="operational errors" /></>}
    </section>
  )
}
