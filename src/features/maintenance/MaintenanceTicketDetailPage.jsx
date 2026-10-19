import { createElement, useState } from 'react'
import { ArrowLeft, ArrowRight, ArrowRightCircle, BookOpenText, CalendarClock, CheckCircle2, ClipboardList, Gauge, PencilLine, Printer, ShieldAlert, Tag, UserRound, Wrench, XCircle } from 'lucide-react'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { PageHeader } from '../../components/ui/PageHeader.jsx'
import { useAuth } from '../auth/useAuth.js'
import { useTenant } from '../account/useTenant.js'
import { userErrorMessage } from '../../lib/appErrors.js'
import { assignMaintenanceTicket, correctMaintenanceAction, recordMaintenanceAction, setMaintenanceResponsible, transitionMaintenanceTicket } from '../../services/maintenance.js'
import { branchOperationalPeople } from '../operationalPeople/eligibility.js'
import { useOperationalPeople } from '../operationalPeople/useOperationalPeople.js'
import { useMaintenanceTicket } from './useMaintenanceTicket.js'
import { RecordActionDialog } from './RecordActionDialog.jsx'
import { ResolveTicketDialog } from './ResolveTicketDialog.jsx'
import { ResponsibleSection } from './ResponsibleSection.jsx'
import { formatMaintenanceDate, hasActiveLinkedDowntime, groupActivities, mapMaintenanceError, nextTicketStatuses, performerParts, testResultLabels, ticketPriorityLabels, ticketStatusLabels, ticketTypeLabels } from './maintenanceUtils.js'
import { troubleshootingDetailUrl } from './troubleshooting/troubleshootingModel.js'
import { causeLabel, formatElapsedMinutes } from '../downtime/downtimeModel.js'

function DetailItem({ icon, label, value, hint }) {
  return <div className="detail-item"><span className="detail-item-icon">{createElement(icon, { size: 18 })}</span><div><span>{label}</span><strong>{value || '—'}</strong>{hint && <small>{hint}</small>}</div></div>
}

const statusActionMeta = {
  IN_PROGRESS: { label: 'Mulai Penanganan', icon: ArrowRightCircle },
  DONE: { label: 'Selesaikan Ticket', icon: CheckCircle2 },
  CANCELLED: { label: 'Batalkan Ticket', icon: XCircle },
}

export function MaintenanceTicketDetailPage({ ticketId, navigate }) {
  const { user } = useAuth()
  const { account, branch, can } = useTenant()
  const state = useMaintenanceTicket(ticketId)
  const people = useOperationalPeople(state.ticket?.account_id, state.ticket?.branch_id)
  const [showRecordAction, setShowRecordAction] = useState(false)
  const [showResolve, setShowResolve] = useState(false)
  const [correcting, setCorrecting] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [isUpdating, setIsUpdating] = useState(false)

  if (state.isLoading) return <LoadingScreen label="Loading maintenance ticket" />
  if (state.error) return <ErrorState title="Ticket could not be loaded" detail={userErrorMessage(state.error, 'This ticket is temporarily unavailable.')} onRetry={state.refresh} />
  if (!state.ticket) return <ErrorState title="Ticket not available" detail="It may belong to a different branch or account." />

  const ticket = state.ticket
  const timezone = ticket.machine?.timezone || branch?.timezone || account?.default_timezone || 'UTC'
  const canProgress = can('maintenance.ticket.progress')
  const canAssign = can('maintenance.ticket.assign')
  const canSetResponsible = can('maintenance.ticket.responsible')
  const canCancel = can('maintenance.ticket.cancel')
  const canRecordAction = can('maintenance.action.create')
  const availableTransitions = canProgress ? (nextTicketStatuses[ticket.status] || []).filter((status) => status !== 'CANCELLED' || canCancel) : []

  const eligiblePeople = branchOperationalPeople(people.people, ticket.branch_id)
  const isOpenTicket = ticket.status === 'OPEN' || ticket.status === 'IN_PROGRESS'
  const downtimeStillActive = hasActiveLinkedDowntime(ticket)

  async function handleResolve(resolution) {
    const updated = await transitionMaintenanceTicket(ticket.id, 'DONE', resolution)
    state.setTicket((current) => ({ ...current, ...updated }))
    await state.refresh({ silent: true })
    setSuccess('Ticket diselesaikan.')
  }

  async function handleCorrect(values) {
    await correctMaintenanceAction(ticket.id, correcting.original.id, values)
    await state.refresh({ silent: true })
    setSuccess('Koreksi dicatat. Catatan asli tetap tersimpan.')
  }

  async function handleSaveResponsible(values) {
    const updated = await setMaintenanceResponsible(ticket.id, values)
    state.setTicket((current) => ({ ...current, ...updated }))
    setSuccess('Penanggung jawab disimpan.')
  }

  async function handleTransition(status) {
    if (status === 'DONE') { setShowResolve(true); return }
    setIsUpdating(true)
    setActionError(null)
    try {
      const updated = await transitionMaintenanceTicket(ticket.id, status)
      state.setTicket((current) => ({ ...current, ...updated }))
      setSuccess(`Ticket dipindahkan ke ${ticketStatusLabels[status]}.`)
    } catch (error) {
      setActionError(mapMaintenanceError(error))
    } finally {
      setIsUpdating(false)
    }
  }

  async function handleAssignToggle() {
    setIsUpdating(true)
    setActionError(null)
    try {
      const nextAssignee = ticket.assigned_to === user.id ? null : user.id
      const updated = await assignMaintenanceTicket(ticket.id, nextAssignee)
      state.setTicket((current) => ({ ...current, ...updated }))
      setSuccess(nextAssignee ? 'Ticket assigned to you.' : 'Ticket unassigned.')
    } catch (error) {
      setActionError(mapMaintenanceError(error))
    } finally {
      setIsUpdating(false)
    }
  }

  async function handleRecordAction(values) {
    const action = await recordMaintenanceAction(ticket.id, values)
    state.setTicket((current) => ({ ...current, actions: [...(current.actions || []), action] }))
    await state.refresh({ silent: true })
    setSuccess('Aktivitas dicatat.')
  }

  const actions = (
    <div className="detail-actions">
      {canAssign && <button className="secondary-button" type="button" onClick={handleAssignToggle} disabled={isUpdating}><UserRound size={17} /> {ticket.assigned_to === user.id ? 'Unassign me' : 'Assign to me'}</button>}
      {availableTransitions.map((status) => {
        const meta = statusActionMeta[status]
        return <button key={status} className={status === 'CANCELLED' ? 'danger-outline-button' : 'primary-button'} type="button" onClick={() => handleTransition(status)} disabled={isUpdating}>{createElement(meta.icon, { size: 17 })} {meta.label}</button>
      })}
    </div>
  )

  return (
    <div className="page-stack">
      <button className="back-button" type="button" onClick={() => navigate('/maintenance')}><ArrowLeft size={17} /> Back to Maintenance</button>
      <PageHeader eyebrow={`${account?.name} · ${ticket.machine?.display_name || 'Unknown machine'}`} title={ticket.title} description="Penanganan gangguan, riwayat aktivitas, dan penyelesaian." action={actions} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
      {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

      <section className="incident-detail-hero glass-surface">
        <div><span className={`incident-status-pill ${ticket.status.toLowerCase()}`}>{ticketStatusLabels[ticket.status]}</span><h2>{ticket.title}</h2><p>{ticketTypeLabels[ticket.type]} · {ticketPriorityLabels[ticket.priority]} · Opened {formatMaintenanceDate(ticket.opened_at, timezone)}{ticket.downtime_event ? ' · Sumber: Downtime' : ''}</p></div>
      </section>

      {ticket.downtime_event && <section className="ticket-knowledge-reference glass-surface"><span><Gauge size={20} /></span><div><span className="card-kicker">Downtime terkait</span><h2>{causeLabel(ticket.downtime_event.cause)}</h2><p>Mulai {formatMaintenanceDate(ticket.downtime_event.actual_start_at, timezone)} · Selesai {formatMaintenanceDate(ticket.downtime_event.actual_end_at, timezone)} · Durasi {formatElapsedMinutes(ticket.downtime_event.elapsed_minutes)}</p>{ticket.downtime_event.error_code_text && <small>Kode error: {ticket.downtime_event.error_code_text}</small>}</div><button className="secondary-button" type="button" onClick={() => navigate(`/maintenance/machine-condition?machine=${encodeURIComponent(ticket.machine_id)}`)}>Kondisi Mesin <ArrowRight size={16} /></button></section>}

      <section className="detail-grid glass-surface" aria-label="Ticket details">
        <DetailItem icon={Printer} label="Machine" value={ticket.machine ? `${ticket.machine.machine_code} · ${ticket.machine.display_name}` : 'Unknown'} />
        <DetailItem icon={ShieldAlert} label="Error code" value={ticket.error_code ? `${ticket.error_code.code} · ${ticket.error_code.title}` : 'Not specified'} />
        <DetailItem icon={Tag} label="Type / Priority" value={`${ticketTypeLabels[ticket.type]} · ${ticketPriorityLabels[ticket.priority]}`} />
        <DetailItem icon={UserRound} label="Reported by" value={ticket.reporter?.name || 'Unknown'} hint={formatMaintenanceDate(ticket.opened_at, timezone)} />
        <DetailItem icon={UserRound} label="Assigned to" value={ticket.assignee?.name || 'Unassigned'} />
        <DetailItem icon={CalendarClock} label="Started" value={formatMaintenanceDate(ticket.started_at, timezone)} />
        <DetailItem icon={Gauge} label="Resolved" value={formatMaintenanceDate(ticket.resolved_at, timezone)} />
      </section>

      {ticket.official_error_entry && <section className="ticket-knowledge-reference glass-surface"><span><BookOpenText size={20} /></span><div><span className="card-kicker">Troubleshooting Reference</span><h2>{ticket.official_error_entry.code}</h2><p>{ticket.official_error_entry.classification || 'Official troubleshooting procedure'}</p>{ticket.official_error_entry.document?.title && <small>{ticket.official_error_entry.document.title}</small>}</div><button className="secondary-button" type="button" onClick={() => navigate(troubleshootingDetailUrl(ticket.official_error_entry.id, ticket.machine?.id))}>Lihat Penanganan <ArrowRight size={16} /></button></section>}

      {ticket.description && <section className="incident-narrative-card glass-surface"><strong>Description</strong><p>{ticket.description}</p></section>}

      <ResponsibleSection key={`${ticket.internal_pic_person_id}|${ticket.external_technician_name}|${ticket.external_vendor_name}|${ticket.status}`} ticket={ticket} people={eligiblePeople} peopleLoading={people.isLoading} peopleError={people.error} canEdit={canSetResponsible && isOpenTicket} onSave={handleSaveResponsible} />

      <section className="machine-list-card glass-surface" aria-label="Riwayat Penanganan">
        <div className="list-toolbar"><div><span className="card-kicker">Riwayat Penanganan</span><h2>{(ticket.actions || []).length} aktivitas tercatat</h2></div>{canRecordAction && isOpenTicket && <button className="secondary-button" type="button" onClick={() => setShowRecordAction(true)}><PencilLine size={16} /> Tambah Aktivitas</button>}</div>
        {(ticket.actions || []).length === 0
          ? <div className="machine-empty-state"><ClipboardList size={36} strokeWidth={1.35} /><h3>Belum ada aktivitas.</h3><p>Pemeriksaan dan perbaikan yang dilakukan akan muncul di sini.</p></div>
          : <ol className="incident-narrative-list maintenance-timeline">{groupActivities(ticket.actions).map(({ original, corrections, current }) => (
            <li key={original.id} className="incident-narrative-card glass-surface"><span><Wrench size={16} /></span><div>
              <strong>{formatMaintenanceDate(original.performed_at, timezone)}{corrections.length > 0 && <em className="correction-badge"> · Dikoreksi</em>}</strong>
              <small className="timeline-label">{corrections.length > 0 ? 'Catatan asli' : 'Aktivitas'}</small>
              {performerParts(original).map((part) => <small key={part.kind}>{part.kind === 'internal' ? 'PIC' : 'Teknisi eksternal'}: {part.label}</small>)}
              <p className={corrections.length > 0 ? 'timeline-superseded' : undefined}>{original.action_description}</p>
              {original.findings && <small>Temuan: {original.findings}</small>}
              {original.result && <small>Hasil: {original.result}</small>}
              <small>Dicatat oleh {original.performer?.name || 'Tidak diketahui'}</small>
              {corrections.map((correction) => (
                <div key={correction.id} className="timeline-correction">
                  <small className="timeline-label">Informasi koreksi</small>
                  {performerParts(correction).map((part) => <small key={part.kind}>{part.kind === 'internal' ? 'PIC' : 'Teknisi eksternal'}: {part.label}</small>)}
                  <p>{correction.action_description}</p>
                  {correction.findings && <small>Temuan: {correction.findings}</small>}
                  <small>Alasan koreksi: {correction.correction_reason}</small>
                  <small>Dikoreksi oleh {correction.performer?.name || 'Tidak diketahui'} · {formatMaintenanceDate(correction.performed_at, timezone)}</small>
                </div>
              ))}
              {canRecordAction && <button className="secondary-button" type="button" onClick={() => setCorrecting({ original, current })}>Koreksi</button>}
            </div></li>
          ))}</ol>}
      </section>

      {ticket.status === 'DONE' && (
        <section className="incident-narrative-card glass-surface" aria-label="Penyelesaian">
          <strong>Penyelesaian</strong>
          {ticket.resolution_cause || ticket.resolution_action
            ? <>
              <p><b>Penyebab:</b> {ticket.resolution_cause}</p>
              <p><b>Tindakan:</b> {ticket.resolution_action}</p>
              <p><b>Hasil pengujian:</b> {testResultLabels[ticket.resolution_test_result] || '—'}</p>
              {ticket.resolution_notes && <p><b>Catatan:</b> {ticket.resolution_notes}</p>}
            </>
            : <p>Ticket ini diselesaikan tanpa ringkasan penyelesaian.</p>}
          <small>Selesai {formatMaintenanceDate(ticket.resolved_at, timezone)}{ticket.resolver?.name ? ` oleh ${ticket.resolver.name}` : ''}</small>
          {downtimeStillActive && <small className="field-error" role="status">Downtime terkait masih berjalan. Akhiri dari Kondisi Mesin bila mesin sudah beroperasi.</small>}
        </section>
      )}

      {showResolve && <ResolveTicketDialog ticket={ticket} downtimeStillActive={downtimeStillActive} onClose={() => setShowResolve(false)} onSave={handleResolve} />}
      {correcting && <RecordActionDialog people={eligiblePeople} peopleLoading={people.isLoading} peopleError={people.error} correcting={correcting.current} onClose={() => setCorrecting(null)} onSave={handleCorrect} />}
      {showRecordAction && <RecordActionDialog people={eligiblePeople} peopleLoading={people.isLoading} peopleError={people.error} onClose={() => setShowRecordAction(false)} onSave={handleRecordAction} />}
    </div>
  )
}
