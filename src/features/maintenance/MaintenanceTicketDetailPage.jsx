import { createElement, useState } from 'react'
import { ArrowLeft, ArrowRight, ArrowRightCircle, BookOpenText, CheckCircle2, ClipboardList, Gauge, PencilLine, UserRound, XCircle } from 'lucide-react'
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
import { formatMaintenanceDate, hasActiveLinkedDowntime, groupActivities, mapMaintenanceError, nextTicketStatuses, performerParts, testResultLabels, ticketErrorCodeDisplay, ticketPriorityLabels, ticketStatusLabels, ticketTypeLabels } from './maintenanceUtils.js'
import { troubleshootingDetailUrl } from './troubleshooting/troubleshootingModel.js'
import { causeLabel, formatElapsedMinutes } from '../downtime/downtimeModel.js'

function Field({ label, children, hint, wide }) {
  return <div className={wide ? 'ticket-field-wide' : undefined}><dt>{label}</dt><dd>{children || '—'}{hint && <small>{hint}</small>}</dd></div>
}

// Existing status pill palette: green = normal, amber = needs monitoring, red = not normal.
const testResultTone = { normal: 'done', monitoring: 'in_progress', not_normal: 'voided' }

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
  const errorCode = ticketErrorCodeDisplay(ticket)

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
    <div className="page-stack ticket-page">
      <button className="back-button" type="button" onClick={() => navigate('/maintenance')}><ArrowLeft size={17} /> Back to Maintenance</button>
      <PageHeader eyebrow={`${account?.name} · ${ticket.machine?.display_name || 'Unknown machine'}`} title={ticket.title} description="Penanganan gangguan, riwayat aktivitas, dan penyelesaian." action={actions} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
      {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

      <section className="ticket-card glass-surface" aria-label="Ringkasan ticket">
        <div className="ticket-summary-head">
          <span className={`incident-status-pill ${ticket.status.toLowerCase()}`}>{ticketStatusLabels[ticket.status]}</span>
          <p>{ticketTypeLabels[ticket.type]} · {ticketPriorityLabels[ticket.priority]} · Opened {formatMaintenanceDate(ticket.opened_at, timezone)}{ticket.downtime_event ? ' · Sumber: Downtime' : ''}</p>
        </div>
        {ticket.description && <p className="ticket-description">{ticket.description}</p>}
        {ticket.official_error_entry && <div className="ticket-reference"><span><BookOpenText size={18} /></span><div><span className="card-kicker">Troubleshooting Reference</span><strong>{ticket.official_error_entry.code}</strong><small>{ticket.official_error_entry.classification || 'Official troubleshooting procedure'}{ticket.official_error_entry.document?.title ? ` · ${ticket.official_error_entry.document.title}` : ''}</small></div><button className="secondary-button compact-button" type="button" onClick={() => navigate(troubleshootingDetailUrl(ticket.official_error_entry.id, ticket.machine?.id))}>Lihat Penanganan <ArrowRight size={15} /></button></div>}
      </section>

      {ticket.downtime_event && (
        <section className="ticket-card glass-surface" aria-label="Downtime terkait">
          <div className="ticket-card-head"><div><span className="card-kicker">Downtime terkait</span><h2>{causeLabel(ticket.downtime_event.cause)}</h2></div><button className="secondary-button compact-button" type="button" onClick={() => navigate(`/maintenance/machine-condition?machine=${encodeURIComponent(ticket.machine_id)}`)}><Gauge size={15} /> Kondisi Mesin <ArrowRight size={15} /></button></div>
          <dl className="ticket-fields">
            <Field label="Kode error">{ticket.downtime_event.error_code_text}</Field>
            <Field label="Mulai">{formatMaintenanceDate(ticket.downtime_event.actual_start_at, timezone)}</Field>
            <Field label="Selesai">{ticket.downtime_event.actual_end_at ? formatMaintenanceDate(ticket.downtime_event.actual_end_at, timezone) : 'Masih berjalan'}</Field>
            <Field label="Durasi">{formatElapsedMinutes(ticket.downtime_event.elapsed_minutes)}</Field>
          </dl>
        </section>
      )}

      <section className="ticket-card glass-surface" aria-label="Informasi ticket">
        <div className="ticket-card-head"><div><span className="card-kicker">Informasi ticket</span></div></div>
        <dl className="ticket-fields">
          <Field label="Machine">{ticket.machine ? `${ticket.machine.machine_code} · ${ticket.machine.display_name}` : 'Unknown'}</Field>
          {/* The ticket's own error_code is the master-data link; auto-created tickets never set it. The code
              reported on the related downtime is shown as such, never mapped onto the ticket. */}
          <Field label="Error code" hint={errorCode.hint}>{errorCode.value}</Field>
          <Field label="Type / Priority">{`${ticketTypeLabels[ticket.type]} · ${ticketPriorityLabels[ticket.priority]}`}</Field>
          <Field label="Reported by" hint={formatMaintenanceDate(ticket.opened_at, timezone)}>{ticket.reporter?.name || 'Unknown'}</Field>
          <Field label="Assigned to">{ticket.assignee?.name || 'Unassigned'}</Field>
          <Field label="Started">{formatMaintenanceDate(ticket.started_at, timezone)}</Field>
          <Field label="Resolved">{formatMaintenanceDate(ticket.resolved_at, timezone)}</Field>
        </dl>
      </section>

      <section className="ticket-card glass-surface" aria-label="Penanganan dan penyelesaian">
        <div className="ticket-card-head"><div><span className="card-kicker">Penanganan &amp; Penyelesaian</span></div></div>

        <ResponsibleSection key={`${ticket.internal_pic_person_id}|${ticket.external_technician_name}|${ticket.external_vendor_name}|${ticket.status}`} ticket={ticket} people={eligiblePeople} peopleLoading={people.isLoading} peopleError={people.error} canEdit={canSetResponsible && isOpenTicket} onSave={handleSaveResponsible} />

        <div className="ticket-subsection" aria-label="Riwayat Penanganan">
          <div className="ticket-subsection-head"><h3>Riwayat Penanganan</h3>{canRecordAction && isOpenTicket && <button className="secondary-button compact-button" type="button" onClick={() => setShowRecordAction(true)}><PencilLine size={15} /> Tambah Aktivitas</button>}</div>
          {(ticket.actions || []).length === 0
            ? <p className="ticket-empty"><ClipboardList size={16} />Belum ada aktivitas penanganan yang dicatat.</p>
            : <ol className="ticket-activity-list maintenance-timeline">{groupActivities(ticket.actions).map(({ original, corrections, current }) => (
              <li key={original.id} className="ticket-activity">
                <div className="ticket-activity-head">
                  <strong>{formatMaintenanceDate(original.performed_at, timezone)}{corrections.length > 0 && <em className="correction-badge"> · Dikoreksi</em>}</strong>
                  <div className="ticket-activity-who">{performerParts(original).map((part) => <span key={part.kind}>{part.kind === 'internal' ? 'PIC' : 'Teknisi eksternal'}: {part.label}</span>)}</div>
                </div>
                <small className="timeline-label">{corrections.length > 0 ? 'Catatan asli' : 'Aktivitas'}</small>
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
                {canRecordAction && <button className="secondary-button compact-button ticket-activity-correct" type="button" onClick={() => setCorrecting({ original, current })}>Koreksi</button>}
              </li>
            ))}</ol>}
        </div>

        <div className="ticket-subsection" aria-label="Hasil Penyelesaian">
          <div className="ticket-subsection-head"><h3>Hasil Penyelesaian</h3></div>
          {ticket.status === 'DONE' ? (
            <>
              {ticket.resolution_cause || ticket.resolution_action
                ? <dl className="ticket-fields ticket-resolution">
                  <Field label="Penyebab" wide>{ticket.resolution_cause}</Field>
                  <Field label="Tindakan" wide>{ticket.resolution_action}</Field>
                  <Field label="Hasil Pengujian">{testResultLabels[ticket.resolution_test_result] ? <span className={`incident-status-pill ${testResultTone[ticket.resolution_test_result]}`}>{testResultLabels[ticket.resolution_test_result]}</span> : '—'}</Field>
                  <Field label="Waktu Penyelesaian">{formatMaintenanceDate(ticket.resolved_at, timezone)}</Field>
                  <Field label="Diselesaikan Oleh">{ticket.resolver?.name}</Field>
                  {ticket.resolution_notes && <Field label="Catatan" wide>{ticket.resolution_notes}</Field>}
                </dl>
                : <>
                  <p className="ticket-empty">Ticket ini diselesaikan tanpa ringkasan penyelesaian.</p>
                  <dl className="ticket-fields"><Field label="Waktu Penyelesaian">{formatMaintenanceDate(ticket.resolved_at, timezone)}</Field><Field label="Diselesaikan Oleh">{ticket.resolver?.name}</Field></dl>
                </>}
              {downtimeStillActive && <small className="field-error" role="status">Downtime terkait masih berjalan. Akhiri dari Kondisi Mesin bila mesin sudah beroperasi.</small>}
            </>
          ) : <p className="ticket-empty">{ticket.status === 'CANCELLED' ? 'Ticket dibatalkan tanpa penyelesaian.' : 'Hasil penyelesaian muncul setelah ticket diselesaikan.'}</p>}
        </div>
      </section>

      {showResolve && <ResolveTicketDialog ticket={ticket} downtimeStillActive={downtimeStillActive} onClose={() => setShowResolve(false)} onSave={handleResolve} />}
      {correcting && <RecordActionDialog people={eligiblePeople} peopleLoading={people.isLoading} peopleError={people.error} correcting={correcting.current} onClose={() => setCorrecting(null)} onSave={handleCorrect} />}
      {showRecordAction && <RecordActionDialog people={eligiblePeople} peopleLoading={people.isLoading} peopleError={people.error} onClose={() => setShowRecordAction(false)} onSave={handleRecordAction} />}
    </div>
  )
}
