import { Plus, Printer, RefreshCcw } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { useMachines } from '../features/machines/useMachines.js'

export function MachinesPage() {
  const { account, branch } = useTenant()
  const { machines, isLoading, error, refresh } = useMachines(account?.id, branch?.id)
  return (
    <div className="page-stack">
      <PageHeader eyebrow={`${account?.name} · ${branch?.name}`} title="Machines" description="Physical machines registered to the active branch." action={<button className="primary-button" type="button" disabled title="Machine creation arrives in M1.2"><Plus size={18} /> Add machine</button>} />
      <section className="machine-list-card glass-surface">
        <div className="list-toolbar"><div><span className="card-kicker">Machine master</span><h2>{isLoading ? 'Loading machines…' : `${machines.length} ${machines.length === 1 ? 'machine' : 'machines'}`}</h2></div><button className="icon-button" type="button" onClick={refresh} aria-label="Refresh machines"><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></div>
        {error ? <div className="embedded-error" role="alert"><strong>Machines could not be loaded.</strong><span>{error.message}</span><button className="secondary-button" type="button" onClick={refresh}>Try again</button></div>
          : !isLoading && machines.length === 0 ? <div className="machine-empty-state"><div className="empty-machine-icon"><Printer size={40} strokeWidth={1.35} /></div><span className="status-pill neutral-pill">Ready for setup</span><h3>No machines registered yet.</h3><p>Add your first physical machine to start building the operating history for {branch?.name}.</p><button className="secondary-button" type="button" disabled><Plus size={17} /> Add machine in M1.2</button></div>
            : <div className="machine-grid">{machines.map((machine) => <article key={machine.id} className="machine-card"><Printer size={24} /><div><strong>{machine.display_name}</strong><span>{machine.machine_code}</span></div><span className="machine-status">{machine.status}</span></article>)}</div>}
      </section>
    </div>
  )
}
