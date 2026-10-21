import { useCallback, useEffect, useMemo, useState } from 'react'
import { CheckCircle2, ClipboardPlus, Gauge, History, ListChecks, PowerOff, RefreshCcw, Timer } from 'lucide-react'
import { useTenant } from '../account/useTenant.js'
import { machineIdPattern } from '../../hooks/useAppRoute.js'
import { useMachines } from '../machines/useMachines.js'
import { AddNoteDialog } from './AddNoteDialog.jsx'
import { currentMonthValue, describeActiveEntry, describeEventDurations, describeEventStatus, describeFinancialBreakdown, FINANCIAL_ESTIMATE_NOTE, FINANCIAL_OVERLAP_NOTE, formatAvailabilityPercent, formatDowntimeDateTime, formatProductionDowntimeMinutes, monthToPeriod, causeLabel, NOT_CONFIGURED_LABEL } from './downtimeModel.js'
import { ticketStatusLabels } from '../maintenance/maintenanceUtils.js'
import { EndDowntimeDialog } from './EndDowntimeDialog.jsx'
import { StartDowntimeDialog } from './StartDowntimeDialog.jsx'
import { userErrorMessage } from '../../lib/appErrors.js'
import { addDowntimeNote, endDowntime, loadDowntimeEvents, loadDowntimeOverview, startDowntime } from '../../services/downtime.js'

const HISTORY_PAGE_SIZE = 25

function OverviewKpi({ icon, label, value, hint, tone = 'blue' }) {
  return <article className="report-kpi glass-surface"><span className={`report-kpi-icon tone-${tone}`}>{icon}</span><div><span>{label}</span><strong>{value}</strong>{hint && <small>{hint}</small>}</div></article>
}

function ActiveEntry({ item, timezone, canManage, onNote, onEnd, onOpen }) {
  const status = describeActiveEntry(item, timezone)
  const identity = [item.machine_code && item.machine_name ? item.machine_code : null, item.branch_name, status.causeText, status.errorCode].filter(Boolean).join(' · ')
  return (
    <li>
      <div className="downtime-attention-main">
        <strong>{item.machine_name || item.machine_code}</strong>
        <span>{identity}</span>
        {status.latestNote && <span className="downtime-entry-note">Catatan terakhir: {status.latestNote}</span>}
      </div>
      <dl className="downtime-attention-meta"><div><dt>Mulai</dt><dd>{status.startText}</dd></div><div><dt>Durasi</dt><dd>{status.durationText}</dd></div><div><dt>Ticket</dt><dd>{item.maintenance_ticket ? item.maintenance_ticket.id.slice(0, 8).toUpperCase() : 'Belum ada ticket terkait'}</dd></div><div><dt>Status Ticket</dt><dd>{item.maintenance_ticket ? ticketStatusLabels[item.maintenance_ticket.status] : '—'}</dd></div></dl>
      <div className="downtime-entry-actions">
        {canManage && <button className="secondary-button compact-button" type="button" onClick={onNote}><ClipboardPlus size={15} /> Tambah Catatan</button>}
        {canManage && <button className="primary-button compact-button" type="button" onClick={onEnd} disabled={!status.canComplete} title={status.canComplete ? undefined : 'Waktu mulai tidak valid'}><CheckCircle2 size={15} /> Mesin Sudah Normal</button>}
        {item.maintenance_ticket && <button className="secondary-button compact-button" type="button" onClick={onOpen}>Lihat Ticket</button>}
      </div>
    </li>
  )
}

function HistoryEntry({ event, timezone, onOpenTicket }) {
  const state = describeEventStatus(event)
  const durations = describeEventDurations(event)
  const machine = event.machine
  const money = describeFinancialBreakdown(event.financial)
  const identity = [machine?.machine_code && machine?.display_name ? machine.machine_code : null, event.branch_name, causeLabel(event.cause), event.error_code_text].filter(Boolean).join(' · ')
  return (
    <li>
      <div className="downtime-attention-main"><strong>{machine?.display_name || machine?.machine_code || 'Mesin'}</strong><span>{identity}</span></div>
      <dl className="downtime-history-meta">
        <div><dt>Mulai</dt><dd>{formatDowntimeDateTime(event.actual_start_at, timezone)}</dd></div>
        <div><dt>Selesai</dt><dd>{event.actual_end_at ? formatDowntimeDateTime(event.actual_end_at, timezone) : '—'}</dd></div>
        <div><dt>Durasi</dt><dd>{durations.elapsedText}</dd></div>
        <div><dt>Downtime Produksi</dt><dd>{durations.productionText}</dd></div>
      </dl>
      {money && <dl className="downtime-history-meta downtime-history-financial" aria-label="Estimasi dampak finansial">
        <div><dt>Downtime Produksi Dialokasikan</dt><dd>{money.allocatedMinutes}<small>{FINANCIAL_OVERLAP_NOTE}</small></dd></div>
        <div><dt>Biaya Operasional Terdampak</dt><dd>{money.operatingCost}<small>Tarif saat ini {money.costRate}</small></dd></div>
        <div><dt>Potensi Pendapatan Hilang</dt><dd>{money.lostRevenue}<small>Tarif saat ini {money.revenueRate}</small></dd></div>
      </dl>}
      {money && <small className="financial-note">{FINANCIAL_ESTIMATE_NOTE}</small>}
      <div className="downtime-history-ticket">
        <span className={`machine-status ${state.state === 'active' ? 'machine-status-down' : ''}`}>{state.label}</span>
        {event.maintenance_ticket
          ? <><small>Ticket {event.maintenance_ticket.id.slice(0, 8).toUpperCase()} · {ticketStatusLabels[event.maintenance_ticket.status]}</small><button className="secondary-button compact-button" type="button" onClick={onOpenTicket}>Lihat Ticket</button></>
          : <small>Belum ada ticket terkait</small>}
      </div>
    </li>
  )
}

/**
 * "Kondisi Mesin" tab of the Maintenance page - the operational downtime
 * workspace: summary, active downtime (note / complete), the report action and
 * history. Machine Detail only links here (?machine=<id>&action=report|manage).
 * Mutations reuse the existing downtime APIs, so the backend stays the source
 * of truth for timestamps, duplicate prevention, scope and backdating.
 */
export function MachineConditionSection({ navigate, initialMachineId: requestedMachineId = '', initialAction = '' }) {
  const initialMachineId = machineIdPattern.test(requestedMachineId) ? requestedMachineId : ''
  const { account, branch, branches, can } = useTenant()
  const timezone = branch?.timezone || account?.default_timezone || 'UTC'
  const canManage = can('downtime.manage')
  const canBackdate = can('downtime.backdate')
  const [branchFilter, setBranchFilter] = useState('')
  const [machineFilter, setMachineFilter] = useState(initialMachineId)
  const [statusFilter, setStatusFilter] = useState('')
  const [month, setMonth] = useState(() => currentMonthValue(timezone))
  const [overview, setOverview] = useState(null)
  const [history, setHistory] = useState({ rows: [], page: 1, hasMore: false })
  const [isLoading, setIsLoading] = useState(true)
  const [isLoadingMore, setIsLoadingMore] = useState(false)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [dialog, setDialog] = useState(initialAction === 'report' && canManage ? { type: 'start' } : null)
  const period = monthToPeriod(month) ?? monthToPeriod(currentMonthValue(timezone))
  const machinesState = useMachines(account?.id, branchFilter || branch?.id)
  const machines = useMemo(() => machinesState.machines.filter((item) => item.status !== 'retired'), [machinesState.machines])

  const refresh = useCallback(async () => {
    setIsLoading(true)
    setError(null)
    try {
      const [overviewResult, eventsResult] = await Promise.all([
        loadDowntimeOverview({ accountId: account.id, branchId: branchFilter || undefined, machineId: machineFilter || undefined, periodStart: period.periodStart, periodEnd: period.periodEnd }),
        loadDowntimeEvents({ branchId: branchFilter || undefined, machineId: machineFilter || undefined, status: statusFilter || undefined, periodStart: period.periodStart, periodEnd: period.periodEnd, perPage: HISTORY_PAGE_SIZE }),
      ])
      setOverview(overviewResult)
      setHistory({ rows: eventsResult?.data ?? [], page: 1, hasMore: Boolean(eventsResult?.next_page_url) })
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoading(false)
    }
  }, [account.id, branchFilter, machineFilter, statusFilter, period.periodStart, period.periodEnd])

  useEffect(() => { refresh() }, [refresh])

  async function loadMore() {
    setIsLoadingMore(true)
    try {
      const next = await loadDowntimeEvents({ branchId: branchFilter || undefined, machineId: machineFilter || undefined, status: statusFilter || undefined, periodStart: period.periodStart, periodEnd: period.periodEnd, perPage: HISTORY_PAGE_SIZE, page: history.page + 1 })
      setHistory((current) => ({ rows: [...current.rows, ...(next?.data ?? [])], page: current.page + 1, hasMore: Boolean(next?.next_page_url) }))
    } catch (loadError) {
      setError(loadError)
    } finally {
      setIsLoadingMore(false)
    }
  }

  const active = overview?.attention ?? []
  const scheduleMissing = overview && overview.availability_percent == null && overview.production_downtime_minutes == null
  const machineName = (id) => active.find((item) => item.machine_id === id)?.machine_name || machines.find((item) => item.id === id)?.display_name || 'mesin'

  async function handleStart(machineId, payload) {
    await startDowntime(machineId, payload)
    setNotice('Mesin dilaporkan berhenti.')
    await refresh()
  }
  async function handleNote(entry, payload) {
    await addDowntimeNote(entry.downtime_event_id, payload)
    setNotice('Catatan ditambahkan. Downtime tetap berjalan.')
    await refresh()
  }
  async function handleEnd(entry, payload) {
    await endDowntime(entry.downtime_event_id, payload)
    setNotice('Mesin dicatat sudah normal.')
    await refresh()
  }

  return (
    <div className="machine-condition-section">
      {notice && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{notice}</span><button type="button" onClick={() => setNotice(null)}>Tutup</button></div>}

      <section className="machine-cost-filters machine-condition-filters glass-surface" aria-label="Filter kondisi mesin">
        <label><span>Cabang</span><select value={branchFilter} onChange={(event) => { setBranchFilter(event.target.value); setMachineFilter('') }}><option value="">Semua cabang</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <label><span>Mesin</span><select value={machineFilter} onChange={(event) => setMachineFilter(event.target.value)}><option value="">Semua mesin</option>{machines.map((item) => <option key={item.id} value={item.id}>{item.display_name || item.machine_code}</option>)}</select></label>
        <label><span>Periode</span><input type="month" value={month} onChange={(event) => event.target.value && setMonth(event.target.value)} max="2100-12" min="2000-01" /></label>
        <button className="secondary-button" type="button" onClick={refresh} disabled={isLoading}><RefreshCcw size={15} className={isLoading ? 'spin' : ''} />Muat ulang</button>
      </section>

      {error && <div className="inline-error" role="alert">{userErrorMessage(error, 'Kondisi mesin tidak dapat dimuat.')}</div>}

      {isLoading && !overview ? <div className="report-loading glass-surface"><RefreshCcw className="spin" size={23} /><strong>Memuat kondisi mesin…</strong></div> : overview && (
        <>
          <section className="report-kpi-grid compact" aria-label="Ringkasan kondisi mesin">
            <OverviewKpi icon={<Gauge size={19} />} label="Ketersediaan Mesin" value={formatAvailabilityPercent(overview.availability_percent)} hint={scheduleMissing ? 'Jadwal operasional belum diatur' : 'Terhadap jam operasional'} />
            <OverviewKpi icon={<Timer size={19} />} label="Downtime Produksi" value={formatProductionDowntimeMinutes(overview.production_downtime_minutes)} hint={scheduleMissing ? 'Jadwal operasional belum diatur' : 'Dalam jam operasional'} tone="warning" />
            <OverviewKpi icon={<ListChecks size={19} />} label="Jumlah Gangguan" value={overview.event_count} hint="Kejadian downtime pada periode ini" tone="purple" />
            <OverviewKpi icon={<PowerOff size={19} />} label="Mesin Berhenti" value={active.length} hint="Sedang berhenti saat ini" tone={active.length > 0 ? 'warning' : 'green'} />
          </section>

          <section className="downtime-attention glass-surface" aria-label="Downtime aktif">
            <header className="downtime-card-header">
              <div><span className="card-kicker">Perlu perhatian</span><h2>Downtime Aktif</h2></div>
              {canManage && <button className="danger-outline-button" type="button" onClick={() => setDialog({ type: 'start' })}><PowerOff size={17} /> Laporkan Mesin Berhenti</button>}
            </header>
            {active.length === 0 ? (
              <p className="downtime-attention-empty"><CheckCircle2 size={17} />Tidak ada mesin yang sedang berhenti.</p>
            ) : (
              <ul className="downtime-attention-list">
                {active.map((item) => (
                  <ActiveEntry key={item.downtime_event_id} item={item} timezone={timezone} canManage={canManage}
                    onNote={() => setDialog({ type: 'note', entry: item })} onEnd={() => setDialog({ type: 'end', entry: item })} onOpen={() => navigate?.(`/maintenance/tickets/${item.maintenance_ticket.id}`)} />
                ))}
              </ul>
            )}
          </section>

          <section className="downtime-attention glass-surface" aria-label="Riwayat downtime">
            <header className="downtime-card-header">
              <div><span className="card-kicker">Periode {period.periodStart} → {period.periodEnd}</span><h2>Riwayat Downtime</h2></div>
              <label className="downtime-history-filter"><span>Status</span><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}><option value="">Semua status</option><option value="active">Berhenti</option><option value="closed">Selesai</option></select></label>
            </header>
            {history.rows.length === 0 ? (
              <p className="downtime-attention-empty"><History size={17} />Belum ada downtime pada periode ini.</p>
            ) : (
              <>
                <ul className="downtime-attention-list downtime-history-list">{history.rows.map((event) => <HistoryEntry key={event.id} event={event} timezone={timezone} onOpenTicket={() => navigate?.(`/maintenance/tickets/${event.maintenance_ticket.id}`)} />)}</ul>
                {history.hasMore && <button className="secondary-button downtime-history-more" type="button" onClick={loadMore} disabled={isLoadingMore}>{isLoadingMore ? 'Memuat…' : 'Muat lebih banyak'}</button>}
              </>
            )}
            {scheduleMissing && <p className="machine-condition-note">{NOT_CONFIGURED_LABEL}: ketersediaan dan downtime produksi dihitung setelah jadwal operasional diatur di Pengaturan.</p>}
          </section>
        </>
      )}

      {dialog?.type === 'start' && <StartDowntimeDialog machines={machines} machineId={machineFilter || initialMachineId} activeMachineIds={active.map((item) => item.machine_id)} canBackdate={canBackdate} onClose={() => setDialog(null)} onStart={handleStart} />}
      {dialog?.type === 'note' && <AddNoteDialog machineName={machineName(dialog.entry.machine_id)} onClose={() => setDialog(null)} onSave={(payload) => handleNote(dialog.entry, payload)} />}
      {dialog?.type === 'end' && <EndDowntimeDialog startAt={dialog.entry.actual_start_at} canBackdate={canBackdate} onClose={() => setDialog(null)} onEnd={(payload) => handleEnd(dialog.entry, payload)} />}
    </div>
  )
}
