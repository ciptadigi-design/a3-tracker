import { useCallback, useEffect, useState } from 'react'
import { ArrowRight, BookOpenText, CalendarClock, ClipboardPlus, History, LoaderCircle, RefreshCcw, Wrench } from 'lucide-react'
import { Pagination } from '../../components/ui/Pagination.jsx'
import { userErrorMessage } from '../../lib/appErrors.js'
import { loadMachineMaintenanceHistory } from '../../services/maintenance.js'
import { formatMaintenanceDate, ticketPriorityLabels, ticketStatusLabels } from './maintenanceUtils.js'
import { troubleshootingDetailUrl } from './troubleshooting/troubleshootingModel.js'

const historyStatuses = ['', 'OPEN', 'IN_PROGRESS', 'DONE', 'CANCELLED']

function HistoryItem({ ticket, machineId, timezone, navigate }) {
  const official = ticket.official_error_entry
  const knownError = ticket.error_code
  const code = official?.code || knownError?.code
  const classification = official?.classification || knownError?.title

  return <article className="maintenance-history-item glass-surface">
    <time dateTime={ticket.opened_at}><CalendarClock size={16} />{formatMaintenanceDate(ticket.opened_at, timezone)}</time>
    <div className="maintenance-history-content">
      <div className="maintenance-history-title">
        <div>{code && <strong className="maintenance-history-code">{code}</strong>}<h3>{classification || ticket.title || 'Maintenance Ticket'}</h3></div>
        <span className={`incident-status-pill ${ticket.status.toLowerCase()}`}>{ticketStatusLabels[ticket.status] || ticket.status}</span>
      </div>
      {(code || classification) && ticket.title && <p className="maintenance-history-incident-title">{ticket.title}</p>}
      {ticket.description && <p className="maintenance-history-description">{ticket.description}</p>}
      <div className="maintenance-history-meta"><span>{ticketPriorityLabels[ticket.priority] || ticket.priority}</span>{ticket.assignee?.name && <span>Teknisi: {ticket.assignee.name}</span>}{ticket.resolved_at && <span>{ticket.status === 'DONE' ? 'Selesai' : 'Ditutup'}: {formatMaintenanceDate(ticket.resolved_at, timezone)}</span>}</div>
    </div>
    <div className="maintenance-history-actions">
      <button className="secondary-button" type="button" onClick={() => navigate(`/maintenance/tickets/${ticket.id}`)}>Lihat Ticket <ArrowRight size={16} /></button>
      {official && <button className="text-button" type="button" onClick={() => navigate(troubleshootingDetailUrl(official.id, machineId))}><BookOpenText size={16} /> Lihat Penanganan</button>}
    </div>
  </article>
}

export function MachineMaintenanceHistory({ machine, timezone, canCreateTicket, navigate, onCreateTicket }) {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [history, setHistory] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadHistory = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      setHistory(await loadMachineMaintenanceHistory({ machineId: machine.id, status, page, perPage: pageSize }))
    } catch (nextError) {
      setError(nextError)
    } finally {
      setIsLoading(false)
    }
  }, [machine.id, page, pageSize, status])

  useEffect(() => { loadHistory() }, [loadHistory])

  const tickets = history?.tickets
  const items = tickets?.data || []
  const summary = history?.summary || { total: 0, active: 0, completed: 0 }

  function changeStatus(nextStatus) {
    setStatus(nextStatus)
    setPage(1)
  }

  function changePageSize(nextPageSize) {
    setPageSize(Number(nextPageSize))
    setPage(1)
  }

  return <section className="machine-maintenance-history" id="maintenance-history" aria-labelledby="maintenance-history-title">
    <header className="maintenance-history-header">
      <div><span className="card-kicker">Operational timeline</span><h2 id="maintenance-history-title">Riwayat Maintenance</h2><p>Masalah maintenance mesin ini, kapan terjadi, dan status penanganannya.</p></div>
      <div className="maintenance-history-header-actions">{canCreateTicket && machine.is_active && <button className="primary-button" type="button" onClick={onCreateTicket}><ClipboardPlus size={17} /> Buat Ticket</button>}<button className="icon-button" type="button" onClick={loadHistory} disabled={isLoading} aria-label="Refresh maintenance history"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></div>
    </header>

    <div className="maintenance-history-summary" aria-label="Maintenance ticket summary">
      <div><span>Total ticket</span><strong>{summary.total}</strong></div>
      <div><span>Aktif</span><strong>{summary.active}</strong></div>
      <div><span>Selesai</span><strong>{summary.completed}</strong></div>
    </div>

    <div className="maintenance-history-filter"><label><span>Status</span><select value={status} onChange={(event) => changeStatus(event.target.value)}>{historyStatuses.map((value) => <option value={value} key={value || 'all'}>{value ? ticketStatusLabels[value] : 'Semua status'}</option>)}</select></label></div>

    {isLoading && !history ? <div className="maintenance-history-state"><LoaderCircle className="spin" size={25} /><strong>Memuat riwayat maintenance…</strong></div>
      : error ? <div className="embedded-error" role="alert"><strong>Riwayat maintenance tidak dapat dimuat.</strong><span>{userErrorMessage(error, 'Coba lagi beberapa saat.')}</span><button className="secondary-button" type="button" onClick={loadHistory}>Coba lagi</button></div>
        : items.length === 0 ? <div className="maintenance-history-empty glass-surface"><History size={38} strokeWidth={1.35} /><h3>{status ? `Tidak ada ticket berstatus ${ticketStatusLabels[status]}.` : 'Belum ada riwayat maintenance untuk mesin ini.'}</h3><p>Ticket manual dan ticket dari Troubleshooting akan tampil di sini.</p><div><button className="secondary-button" type="button" onClick={() => navigate(`/maintenance/troubleshooting?machine=${encodeURIComponent(machine.id)}`)}><Wrench size={17} /> Troubleshooting</button>{canCreateTicket && machine.is_active && <button className="primary-button" type="button" onClick={onCreateTicket}><ClipboardPlus size={17} /> Buat Ticket</button>}</div></div>
          : <div className="maintenance-history-list">{items.map((ticket) => <HistoryItem key={ticket.id} ticket={ticket} machineId={machine.id} timezone={timezone} navigate={navigate} />)}</div>}

    {tickets && <Pagination total={tickets.total} page={tickets.current_page} pageSize={tickets.per_page} pages={tickets.last_page} start={tickets.from ? tickets.from - 1 : 0} end={tickets.to || 0} onPageChange={setPage} onPageSizeChange={changePageSize} label="maintenance tickets" />}
  </section>
}
