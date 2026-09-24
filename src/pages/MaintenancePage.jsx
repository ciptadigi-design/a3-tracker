import { useState } from 'react'
import { CheckCircle2, ClipboardPlus, FileText, ListChecks, Plus, RefreshCcw, Search, Wrench } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { CreateTicketDialog } from '../features/maintenance/CreateTicketDialog.jsx'
import { DocumentRepositorySection } from '../features/maintenance/documents/DocumentRepositorySection.jsx'
import { useMaintenanceTickets } from '../features/maintenance/useMaintenanceTickets.js'
import { formatMaintenanceDate, ticketPriorityLabels, ticketStatusLabels } from '../features/maintenance/maintenanceUtils.js'
import { useMachines } from '../features/machines/useMachines.js'
import { createMaintenanceTicket } from '../services/maintenance.js'
import { userErrorMessage } from '../lib/appErrors.js'
import { TroubleshootingSection } from '../features/maintenance/troubleshooting/TroubleshootingSection.jsx'

const statusTabs = ['OPEN', 'IN_PROGRESS', 'DONE', 'CANCELLED']

// Architecture note (V1.2): "Knowledge base" holds approved internal
// troubleshooting write-ups (maintenance_knowledge - DRAFT/REVIEW/PUBLISHED,
// technician-submitted, admin-reviewed prose). "Documents" is the deliberately
// separate machine document repository (MaintenanceDocumentController) -
// manufacturer manuals, service documents, and reference files, optionally
// linked to a manufacturer/machine model and to machine_error_codes via
// maintenance_document_references. It remains reference-metadata only (a
// title + an external file_path/file_name) by design - no file upload or
// storage subsystem exists or should be added yet; that is a future phase.

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

function TicketsSection({ account, branch, can, navigate }) {
  const [statusFilter, setStatusFilter] = useState('OPEN')
  const [showCreate, setShowCreate] = useState(false)
  const [success, setSuccess] = useState(null)
  const machinesState = useMachines(account?.id, branch?.id)
  const ticketsState = useMaintenanceTickets(account?.id, { status: statusFilter })
  const timezone = branch?.timezone || account?.default_timezone || 'UTC'
  const canCreateTicket = can('maintenance.ticket.create')

  async function handleCreate(values) {
    await createMaintenanceTicket(values)
    await ticketsState.refresh()
    setSuccess('Maintenance ticket opened.')
  }

  return (
    <>
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
      <section className="machine-list-card glass-surface">
          <div className="list-toolbar"><div><span className="card-kicker">Maintenance tickets</span><h2>{ticketsState.isLoading ? 'Loading tickets…' : `${ticketsState.tickets.length} ${ticketStatusLabels[statusFilter].toLowerCase()}`}</h2></div><div className="maintenance-ticket-actions">{canCreateTicket && <button className="primary-button" type="button" onClick={() => setShowCreate(true)}><Plus size={17} /> Open ticket</button>}<button className="icon-button" type="button" onClick={ticketsState.refresh} aria-label="Refresh tickets" disabled={ticketsState.isLoading}><RefreshCcw size={17} className={ticketsState.isLoading ? 'spin' : ''} /></button></div></div>
          <div className="machine-view-tabs" role="tablist" aria-label="Ticket status filter">
            {statusTabs.map((status) => <button key={status} type="button" role="tab" aria-selected={statusFilter === status} className={statusFilter === status ? 'selected' : ''} onClick={() => setStatusFilter(status)}>{ticketStatusLabels[status]}</button>)}
          </div>

          {ticketsState.isLoading ? <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading maintenance tickets…</strong></div>
            : ticketsState.error ? <div className="embedded-error" role="alert"><strong>Tickets could not be loaded.</strong><span>{userErrorMessage(ticketsState.error, 'Tickets are temporarily unavailable.')}</span><button className="secondary-button" type="button" onClick={ticketsState.refresh}>Try again</button></div>
            : ticketsState.tickets.length === 0 ? <div className="machine-empty-state"><Wrench size={38} strokeWidth={1.35} /><h3>No {ticketStatusLabels[statusFilter].toLowerCase()} tickets.</h3><p>Machine problems logged here will track through to resolution.</p>{canCreateTicket && <button className="secondary-button" type="button" onClick={() => setShowCreate(true)}><ClipboardPlus size={17} /> Open ticket</button>}</div>
              : <div className="machine-grid">{ticketsState.tickets.map((ticket) => <TicketRow key={ticket.id} ticket={ticket} timezone={timezone} onOpen={() => navigate(`/maintenance/tickets/${ticket.id}`)} />)}</div>}
      </section>

      {showCreate && <CreateTicketDialog machines={machinesState.machines} onClose={() => setShowCreate(false)} onSave={handleCreate} />}
    </>
  )
}

export function MaintenancePage({ path, search, navigate }) {
  const { account, branch, can } = useTenant()
  const tab = path === '/maintenance/tickets' ? 'tickets' : path === '/maintenance/documents' ? 'documents' : 'troubleshooting'
  const initialQuery = new URLSearchParams(search).get('q') || ''

  return (
    <div className="page-stack maintenance-page">
      <PageHeader eyebrow={`${account?.name} · ${branch?.name ?? 'No branch'}`} title="Maintenance" description="Cari penanganan resmi, kelola tiket, dan akses dokumen mesin." />

      <nav className="machine-view-tabs maintenance-tabs" role="tablist" aria-label="Maintenance sections">
        <button type="button" role="tab" aria-selected={tab === 'troubleshooting'} className={tab === 'troubleshooting' ? 'selected' : ''} onClick={() => navigate('/maintenance/troubleshooting')}><Search size={16} /> Troubleshooting</button>
        <button type="button" role="tab" aria-selected={tab === 'tickets'} className={tab === 'tickets' ? 'selected' : ''} onClick={() => navigate('/maintenance/tickets')}><ListChecks size={16} /> Tickets</button>
        <button type="button" role="tab" aria-selected={tab === 'documents'} className={tab === 'documents' ? 'selected' : ''} onClick={() => navigate('/maintenance/documents')}><FileText size={16} /> Documents</button>
      </nav>

      {tab === 'troubleshooting' && <TroubleshootingSection key={initialQuery || 'empty'} initialQuery={initialQuery} navigate={navigate} />}
      {tab === 'tickets' && <TicketsSection account={account} branch={branch} can={can} navigate={navigate} />}
      {tab === 'documents' && <DocumentRepositorySection />}
    </div>
  )
}
