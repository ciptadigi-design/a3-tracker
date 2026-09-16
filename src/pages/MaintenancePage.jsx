import { useState } from 'react'
import { BookOpen, CheckCircle2, ClipboardPlus, ListChecks, Plus, RefreshCcw, Wrench } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { CreateTicketDialog } from '../features/maintenance/CreateTicketDialog.jsx'
import { useMaintenanceKnowledge } from '../features/maintenance/useMaintenanceKnowledge.js'
import { useMaintenanceTickets } from '../features/maintenance/useMaintenanceTickets.js'
import { formatMaintenanceDate, ticketPriorityLabels, ticketStatusLabels } from '../features/maintenance/maintenanceUtils.js'
import { useMachines } from '../features/machines/useMachines.js'
import { createMaintenanceTicket } from '../services/maintenance.js'
import { userErrorMessage } from '../lib/appErrors.js'

const statusTabs = ['OPEN', 'IN_PROGRESS', 'DONE', 'CANCELLED']

function TicketRow({ ticket, timezone, onOpen }) {
  return (
    <button className="machine-card" type="button" onClick={onOpen}>
      <span className="machine-card-icon"><Wrench size={22} /></span>
      <span className="machine-card-main">
        <span className="machine-card-title"><strong>{ticket.title}</strong><span className={`incident-status-pill ${ticket.status.toLowerCase()}`}>{ticketStatusLabels[ticket.status]}</span></span>
        <span className="machine-card-code">{ticket.machine?.machine_code || 'Unknown machine'} · {ticketPriorityLabels[ticket.priority]}</span>
      </span>
      <span className="machine-card-meta"><span>Opened {formatMaintenanceDate(ticket.opened_at, timezone, { dateOnly: true })}</span><span>{ticket.assignee?.name ? `Assigned: ${ticket.assignee.name}` : 'Unassigned'}</span></span>
    </button>
  )
}

export function MaintenancePage({ navigate }) {
  const { account, branch, can } = useTenant()
  const [tab, setTab] = useState('tickets')
  const [statusFilter, setStatusFilter] = useState('OPEN')
  const [showCreate, setShowCreate] = useState(false)
  const [success, setSuccess] = useState(null)
  const machinesState = useMachines(account?.id, branch?.id)
  const ticketsState = useMaintenanceTickets(account?.id, { status: statusFilter })
  const knowledgeState = useMaintenanceKnowledge({ approvalStatus: 'PUBLISHED' })
  const timezone = branch?.timezone || account?.default_timezone || 'UTC'
  const canCreateTicket = can('maintenance.ticket.create')

  async function handleCreate(values) {
    await createMaintenanceTicket(values)
    await ticketsState.refresh()
    setSuccess('Maintenance ticket opened.')
  }

  const addAction = canCreateTicket ? <button className="primary-button" type="button" onClick={() => setShowCreate(true)}><Plus size={18} /> Open ticket</button> : null

  return (
    <div className="page-stack">
      <PageHeader eyebrow={`${account?.name} · ${branch?.name ?? 'No branch'}`} title="Maintenance" description="Machine problem history, tickets, and the approved troubleshooting knowledge base." action={addAction} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}

      <nav className="machine-view-tabs" role="tablist" aria-label="Maintenance sections">
        <button type="button" role="tab" aria-selected={tab === 'tickets'} className={tab === 'tickets' ? 'selected' : ''} onClick={() => setTab('tickets')}><ListChecks size={16} /> Tickets</button>
        <button type="button" role="tab" aria-selected={tab === 'knowledge'} className={tab === 'knowledge' ? 'selected' : ''} onClick={() => setTab('knowledge')}><BookOpen size={16} /> Knowledge base</button>
      </nav>

      {tab === 'tickets' ? (
        <section className="machine-list-card glass-surface">
          <div className="list-toolbar"><div><span className="card-kicker">Maintenance tickets</span><h2>{ticketsState.isLoading ? 'Loading tickets…' : `${ticketsState.tickets.length} ${ticketStatusLabels[statusFilter].toLowerCase()}`}</h2></div><button className="icon-button" type="button" onClick={ticketsState.refresh} aria-label="Refresh tickets" disabled={ticketsState.isLoading}><RefreshCcw size={17} className={ticketsState.isLoading ? 'spin' : ''} /></button></div>
          <div className="machine-view-tabs" role="tablist" aria-label="Ticket status filter">
            {statusTabs.map((status) => <button key={status} type="button" role="tab" aria-selected={statusFilter === status} className={statusFilter === status ? 'selected' : ''} onClick={() => setStatusFilter(status)}>{ticketStatusLabels[status]}</button>)}
          </div>

          {ticketsState.isLoading ? <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading maintenance tickets…</strong></div>
            : ticketsState.error ? <div className="embedded-error" role="alert"><strong>Tickets could not be loaded.</strong><span>{userErrorMessage(ticketsState.error, 'Tickets are temporarily unavailable.')}</span><button className="secondary-button" type="button" onClick={ticketsState.refresh}>Try again</button></div>
            : ticketsState.tickets.length === 0 ? <div className="machine-empty-state"><Wrench size={38} strokeWidth={1.35} /><h3>No {ticketStatusLabels[statusFilter].toLowerCase()} tickets.</h3><p>Machine problems logged here will track through to resolution.</p>{canCreateTicket && <button className="secondary-button" type="button" onClick={() => setShowCreate(true)}><ClipboardPlus size={17} /> Open ticket</button>}</div>
              : <div className="machine-grid">{ticketsState.tickets.map((ticket) => <TicketRow key={ticket.id} ticket={ticket} timezone={timezone} onOpen={() => navigate(`/maintenance/tickets/${ticket.id}`)} />)}</div>}
        </section>
      ) : (
        <section className="machine-list-card glass-surface">
          <div className="list-toolbar"><div><span className="card-kicker">Published knowledge base</span><h2>{knowledgeState.isLoading ? 'Loading…' : `${knowledgeState.entries.length} approved ${knowledgeState.entries.length === 1 ? 'entry' : 'entries'}`}</h2></div><button className="icon-button" type="button" onClick={knowledgeState.refresh} aria-label="Refresh knowledge base" disabled={knowledgeState.isLoading}><RefreshCcw size={17} className={knowledgeState.isLoading ? 'spin' : ''} /></button></div>
          {knowledgeState.isLoading ? <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading knowledge base…</strong></div>
            : knowledgeState.error ? <div className="embedded-error" role="alert"><strong>Knowledge base could not be loaded.</strong><span>{userErrorMessage(knowledgeState.error, 'Try again shortly.')}</span><button className="secondary-button" type="button" onClick={knowledgeState.refresh}>Try again</button></div>
            : knowledgeState.entries.length === 0 ? <div className="machine-empty-state"><BookOpen size={38} strokeWidth={1.35} /><h3>No published knowledge yet.</h3><p>Technician resolution notes approved by an admin will appear here.</p></div>
              : <ul className="incident-narrative-list">{knowledgeState.entries.map((entry) => <li key={entry.id} className="incident-narrative-card glass-surface"><span><BookOpen size={16} /></span><div><strong>{entry.problem}</strong><p>{entry.solution}</p>{entry.success_notes && <small>{entry.success_notes}</small>}</div></li>)}</ul>}
        </section>
      )}

      {showCreate && <CreateTicketDialog machines={machinesState.machines} onClose={() => setShowCreate(false)} onSave={handleCreate} />}
    </div>
  )
}
