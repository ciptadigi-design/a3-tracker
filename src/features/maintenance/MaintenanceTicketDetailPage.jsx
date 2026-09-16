import { createElement, useState } from 'react'
import { ArrowLeft, ArrowRightCircle, CalendarClock, CheckCircle2, ClipboardList, Gauge, PencilLine, Printer, ShieldAlert, Tag, UserRound, Wrench, XCircle } from 'lucide-react'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { PageHeader } from '../../components/ui/PageHeader.jsx'
import { useAuth } from '../auth/useAuth.js'
import { useTenant } from '../account/useTenant.js'
import { userErrorMessage } from '../../lib/appErrors.js'
import { assignMaintenanceTicket, recordMaintenanceAction, transitionMaintenanceTicket } from '../../services/maintenance.js'
import { useMaintenanceTicket } from './useMaintenanceTicket.js'
import { RecordActionDialog } from './RecordActionDialog.jsx'
import { formatMaintenanceDate, mapMaintenanceError, nextTicketStatuses, ticketPriorityLabels, ticketStatusLabels, ticketTypeLabels } from './maintenanceUtils.js'

function DetailItem({ icon, label, value, hint }) {
  return <div className="detail-item"><span className="detail-item-icon">{createElement(icon, { size: 18 })}</span><div><span>{label}</span><strong>{value || '—'}</strong>{hint && <small>{hint}</small>}</div></div>
}

const statusActionMeta = {
  IN_PROGRESS: { label: 'Start work', icon: ArrowRightCircle },
  DONE: { label: 'Mark done', icon: CheckCircle2 },
  CANCELLED: { label: 'Cancel ticket', icon: XCircle },
}

export function MaintenanceTicketDetailPage({ ticketId, navigate }) {
  const { user } = useAuth()
  const { account, branch, can } = useTenant()
  const state = useMaintenanceTicket(ticketId)
  const [showRecordAction, setShowRecordAction] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [isUpdating, setIsUpdating] = useState(false)

  if (state.isLoading) return <LoadingScreen label="Loading maintenance ticket" />
  if (state.error) return <ErrorState title="Ticket could not be loaded" detail={userErrorMessage(state.error, 'This ticket is temporarily unavailable.')} onRetry={state.refresh} />
  if (!state.ticket) return <ErrorState title="Ticket not available" detail="It may belong to a different branch or account." />

  const ticket = state.ticket
  const timezone = ticket.machine?.timezone || branch?.timezone || account?.default_timezone || 'UTC'
  const canUpdate = can('maintenance.ticket.update')
  const canAssign = can('maintenance.ticket.assign')
  const canRecordAction = can('maintenance.action.create')
  const availableTransitions = canUpdate ? (nextTicketStatuses[ticket.status] || []) : []

  async function handleTransition(status) {
    setIsUpdating(true)
    setActionError(null)
    try {
      const updated = await transitionMaintenanceTicket(ticket.id, status)
      state.setTicket((current) => ({ ...current, ...updated }))
      setSuccess(`Ticket moved to ${ticketStatusLabels[status]}.`)
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
    setSuccess('Action recorded on this ticket.')
  }

  const actions = (
    <div className="detail-actions">
      {canAssign && <button className="secondary-button" type="button" onClick={handleAssignToggle} disabled={isUpdating}><UserRound size={17} /> {ticket.assigned_to === user.id ? 'Unassign me' : 'Assign to me'}</button>}
      {canRecordAction && ticket.status !== 'DONE' && ticket.status !== 'CANCELLED' && <button className="secondary-button" type="button" onClick={() => setShowRecordAction(true)} disabled={isUpdating}><PencilLine size={17} /> Record action</button>}
      {availableTransitions.map((status) => {
        const meta = statusActionMeta[status]
        return <button key={status} className={status === 'CANCELLED' ? 'danger-outline-button' : 'primary-button'} type="button" onClick={() => handleTransition(status)} disabled={isUpdating}>{createElement(meta.icon, { size: 17 })} {meta.label}</button>
      })}
    </div>
  )

  return (
    <div className="page-stack">
      <button className="back-button" type="button" onClick={() => navigate('/maintenance')}><ArrowLeft size={17} /> Back to Maintenance</button>
      <PageHeader eyebrow={`${account?.name} · ${ticket.machine?.display_name || 'Unknown machine'}`} title={ticket.title} description="Machine problem history, resolution actions, and downtime tracking." action={actions} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
      {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

      <section className="incident-detail-hero glass-surface">
        <div><span className={`incident-status-pill ${ticket.status.toLowerCase()}`}>{ticketStatusLabels[ticket.status]}</span><h2>{ticket.title}</h2><p>{ticketTypeLabels[ticket.type]} · {ticketPriorityLabels[ticket.priority]} · Opened {formatMaintenanceDate(ticket.opened_at, timezone)}</p></div>
        {/* Downtime is opened_at -> resolved_at, shown via the Opened/Resolved detail items below - no separate tracked window. */}
      </section>

      <section className="detail-grid glass-surface" aria-label="Ticket details">
        <DetailItem icon={Printer} label="Machine" value={ticket.machine ? `${ticket.machine.machine_code} · ${ticket.machine.display_name}` : 'Unknown'} />
        <DetailItem icon={ShieldAlert} label="Error code" value={ticket.error_code ? `${ticket.error_code.code} · ${ticket.error_code.title}` : 'Not specified'} />
        <DetailItem icon={Tag} label="Type / Priority" value={`${ticketTypeLabels[ticket.type]} · ${ticketPriorityLabels[ticket.priority]}`} />
        <DetailItem icon={UserRound} label="Reported by" value={ticket.reporter?.name || 'Unknown'} hint={formatMaintenanceDate(ticket.opened_at, timezone)} />
        <DetailItem icon={UserRound} label="Assigned to" value={ticket.assignee?.name || 'Unassigned'} />
        <DetailItem icon={CalendarClock} label="Started" value={formatMaintenanceDate(ticket.started_at, timezone)} />
        <DetailItem icon={Gauge} label="Resolved" value={formatMaintenanceDate(ticket.resolved_at, timezone)} />
      </section>

      {ticket.description && <section className="incident-narrative-card glass-surface"><strong>Description</strong><p>{ticket.description}</p></section>}

      <section className="machine-list-card glass-surface">
        <div className="list-toolbar"><div><span className="card-kicker">Resolution history</span><h2>{(ticket.actions || []).length} recorded {ticket.actions?.length === 1 ? 'action' : 'actions'}</h2></div></div>
        {(ticket.actions || []).length === 0
          ? <div className="machine-empty-state"><ClipboardList size={36} strokeWidth={1.35} /><h3>No actions recorded yet.</h3><p>Actions performed to diagnose or resolve this ticket will appear here.</p></div>
          : <ul className="incident-narrative-list">{[...ticket.actions].sort((a, b) => new Date(b.performed_at) - new Date(a.performed_at)).map((action) => (
            <li key={action.id} className="incident-narrative-card glass-surface"><span><Wrench size={16} /></span><div><strong>{action.performer?.name || 'Unknown'} · {formatMaintenanceDate(action.performed_at, timezone)}</strong><p>{action.action_description}</p>{action.result && <small>Result: {action.result}</small>}</div></li>
          ))}</ul>}
      </section>

      {showRecordAction && <RecordActionDialog onClose={() => setShowRecordAction(false)} onSave={handleRecordAction} />}
    </div>
  )
}
