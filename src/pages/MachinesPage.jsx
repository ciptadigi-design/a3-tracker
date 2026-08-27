import { useEffect, useMemo, useState } from 'react'
import { Archive, ArrowRight, CheckCircle2, MapPin, Plus, Printer, RefreshCcw, ShieldCheck } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { useAuth } from '../features/auth/useAuth.js'
import { MachineFormDialog } from '../features/machines/MachineFormDialog.jsx'
import { useMachineCatalog } from '../features/machines/useMachineCatalog.js'
import { useMachineWorkflowState } from '../features/machines/useMachineWorkflowState.js'
import { useMachines } from '../features/machines/useMachines.js'
import { createMachine } from '../services/supabase/machines.js'

function MachineCard({ machine, branchName, onOpen }) {
  const model = machine.machine_models
  return (
    <button className="machine-card" type="button" onClick={onOpen}>
      <span className="machine-card-icon"><Printer size={23} /></span>
      <span className="machine-card-main"><span className="machine-card-title"><strong>{machine.display_name}</strong><span className={`machine-status machine-status-${machine.status}`}>{machine.status}</span></span><span className="machine-card-code">{machine.machine_code}</span><span className="machine-card-model">{model?.manufacturers?.name} · {model?.name}</span></span>
      <span className="machine-card-meta"><span><MapPin size={14} /> {branchName}</span><span>{machine.serial_number || 'No serial recorded'}</span><span className={machine.is_active ? 'active-state' : 'archived-state'}>{machine.is_active ? 'Active record' : 'Archived record'}</span></span>
      <ArrowRight className="machine-card-arrow" size={18} />
    </button>
  )
}

export function MachinesPage({ navigate }) {
  const { user } = useAuth()
  const { account, branch, branches, membership, setSelectedBranchId } = useTenant()
  const { machines, isLoading, error, refresh } = useMachines(account?.id, branch?.id)
  const canManage = membership?.role === 'owner' || membership?.role === 'admin'
  const catalog = useMachineCatalog(account?.id, canManage)
  const [view, setView] = useState('active')
  const [success, setSuccess] = useState(null)
  const { workflow, workflowBranchId, isContextActive, openCreate, clearWorkflow } = useMachineWorkflowState({ userId: user.id, accountId: account.id, branchId: branch?.id })

  const activeMachines = useMemo(() => machines.filter((machine) => machine.is_active), [machines])
  const archivedMachines = useMemo(() => machines.filter((machine) => !machine.is_active), [machines])
  const visibleMachines = view === 'active' ? activeMachines : archivedMachines

  useEffect(() => {
    if (!workflowBranchId || isContextActive) {
      if (workflow.type === 'edit' && workflow.machineId) navigate(`/machines/${workflow.machineId}`)
      return
    }
    if (branches.some((item) => item.id === workflowBranchId)) setSelectedBranchId(workflowBranchId)
    else clearWorkflow()
  }, [branches, clearWorkflow, isContextActive, navigate, setSelectedBranchId, workflow.machineId, workflow.type, workflowBranchId])

  async function handleCreate(values) {
    const created = await createMachine({ accountId: account.id, values })
    setSuccess(`${created.display_name} was created successfully.`)
    if (created.branch_id !== branch?.id) setSelectedBranchId(created.branch_id)
    else await refresh()
  }

  const addDisabled = catalog.isLoading || Boolean(catalog.error)
  const addAction = canManage ? <button className="primary-button" type="button" onClick={openCreate} disabled={addDisabled} title={catalog.error ? 'Machine catalog unavailable' : undefined}><Plus size={18} /> {catalog.isLoading ? 'Loading catalog…' : 'Add machine'}</button> : null

  return (
    <div className="page-stack">
      <PageHeader eyebrow={`${account?.name} · ${branch?.name}`} title="Machines" description="Physical machines registered to the selected branch, backed by live Supabase data." action={addAction} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
      {!canManage && <div className="permission-banner"><ShieldCheck size={18} /><span>Your {membership?.role ?? 'member'} role has read-only access to machine master data.</span></div>}
      {catalog.error && canManage && <div className="inline-error catalog-error" role="alert"><span>Machine catalog could not be loaded: {catalog.error.message}</span><button className="secondary-button" type="button" onClick={catalog.refresh}>Try again</button></div>}

      <section className="machine-list-card glass-surface">
        <div className="list-toolbar"><div><span className="card-kicker">Machine master</span><h2>{isLoading ? 'Loading machines…' : `${activeMachines.length} active ${activeMachines.length === 1 ? 'machine' : 'machines'}`}</h2></div><button className="icon-button" type="button" onClick={refresh} aria-label="Refresh machines" disabled={isLoading}><RefreshCcw size={17} className={isLoading ? 'spin' : ''} /></button></div>
        <div className="machine-view-tabs" role="tablist" aria-label="Machine record state"><button type="button" role="tab" aria-selected={view === 'active'} className={view === 'active' ? 'selected' : ''} onClick={() => setView('active')}>Active <span>{activeMachines.length}</span></button><button type="button" role="tab" aria-selected={view === 'archived'} className={view === 'archived' ? 'selected' : ''} onClick={() => setView('archived')}>Archived <span>{archivedMachines.length}</span></button></div>

        {isLoading ? <div className="machine-loading-state"><RefreshCcw className="spin" size={24} /><strong>Loading real machine records…</strong><span>Reading the selected branch from Supabase.</span></div>
          : error ? <div className="embedded-error" role="alert"><strong>Machines could not be loaded.</strong><span>{error.message}</span><button className="secondary-button" type="button" onClick={refresh}>Try again</button></div>
          : !isLoading && visibleMachines.length === 0 ? <div className="machine-empty-state"><div className="empty-machine-icon">{view === 'active' ? <Printer size={40} strokeWidth={1.35} /> : <Archive size={38} strokeWidth={1.35} />}</div><span className="status-pill neutral-pill">{view === 'active' ? 'Ready for setup' : 'History preserved'}</span><h3>{view === 'active' ? 'No active machines in this branch.' : 'No archived machines in this branch.'}</h3><p>{view === 'active' ? `Register the first physical machine for ${branch?.name} when you are ready.` : 'Retired machines will remain available here without appearing as active.'}</p>{view === 'active' && canManage && <button className="secondary-button" type="button" onClick={openCreate} disabled={addDisabled}><Plus size={17} /> Add machine</button>}</div>
            : <div className="machine-grid">{visibleMachines.map((machine) => <MachineCard key={machine.id} machine={machine} branchName={branches.find((item) => item.id === machine.branch_id)?.name ?? 'Unknown branch'} onOpen={() => navigate(`/machines/${machine.id}`)} />)}</div>}
      </section>

      {canManage && isContextActive && workflow.type === 'create' && !addDisabled && <MachineFormDialog mode="create" account={account} branches={branches} branchId={branch?.id} manufacturers={catalog.manufacturers} models={catalog.models} onClose={clearWorkflow} onSave={handleCreate} />}
    </div>
  )
}
