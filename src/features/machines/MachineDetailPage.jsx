import { createElement, useState } from 'react'
import { ArrowLeft, CalendarDays, CheckCircle2, Clock3, Edit3, FileText, Gauge, MapPin, Printer, ShieldAlert, Tag, Wrench } from 'lucide-react'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { PageHeader } from '../../components/ui/PageHeader.jsx'
import { useTenant } from '../account/useTenant.js'
import { useAuth } from '../auth/useAuth.js'
import { retireMachine, updateMachine } from '../../services/supabase/machines.js'
import { MachineFormDialog } from './MachineFormDialog.jsx'
import { RetireMachineDialog } from './RetireMachineDialog.jsx'
import { useMachine } from './useMachine.js'
import { useMachineWorkflowState } from './useMachineWorkflowState.js'

function DetailItem({ icon, label, value, hint }) {
  return <div className="detail-item"><span className="detail-item-icon">{createElement(icon, { size: 18 })}</span><div><span>{label}</span><strong>{value || '—'}</strong>{hint && <small>{hint}</small>}</div></div>
}

function formatDate(value) {
  if (!value) return 'Not recorded'
  return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
}

const futureModules = [
  ['Counters', Gauge],
  ['Components', Printer],
  ['Errors', ShieldAlert],
  ['Maintenance', Wrench],
]

export function MachineDetailPage({ machineId, navigate }) {
  const { user } = useAuth()
  const { account, branch: activeBranch, branches, membership, setSelectedBranchId } = useTenant()
  const { machine, isLoading, error, refresh, setMachine } = useMachine(account?.id, machineId)
  const [showRetire, setShowRetire] = useState(false)
  const [success, setSuccess] = useState(null)
  const canManage = membership?.role === 'owner' || membership?.role === 'admin'
  const machineWorkflow = useMachineWorkflowState({ userId: user.id, accountId: account.id, branchId: machine?.branch_id ?? activeBranch?.id })

  if (isLoading) return <LoadingScreen label="Loading machine details" />
  if (error) return <ErrorState title="Machine could not be loaded" detail={error.message} onRetry={refresh} />
  if (!machine) return <ErrorState title="Machine not found" detail="This machine is unavailable in the active account or no longer exists." />

  const model = machine.machine_models
  const branch = branches.find((item) => item.id === machine.branch_id)
  const effectiveTimezone = machine.timezone || branch?.timezone || account?.default_timezone || 'Not configured'
  const timezoneSource = machine.timezone ? 'Machine-specific timezone' : branch?.timezone ? 'Inherited from branch' : account?.default_timezone ? 'Inherited from account' : 'No timezone configured'

  async function handleUpdate(values) {
    const updated = await updateMachine({ accountId: account.id, machineId: machine.id, values })
    setMachine(updated)
    setSelectedBranchId(updated.branch_id)
    setSuccess('Machine details were updated successfully.')
  }

  async function handleRetire() {
    const retired = await retireMachine({ accountId: account.id, machineId: machine.id })
    setMachine(retired)
    setSuccess('Machine retired. Its historical record remains preserved.')
  }

  const actions = canManage && machine.is_active ? <div className="detail-actions"><button className="secondary-button" type="button" onClick={() => machineWorkflow.openEdit(machine.id)}><Edit3 size={17} /> Edit machine</button><button className="danger-outline-button" type="button" onClick={() => setShowRetire(true)}>Retire machine</button></div> : null

  return (
    <div className="page-stack machine-detail-page">
      <button className="back-button" type="button" onClick={() => navigate('/machines')}><ArrowLeft size={17} /> Back to machines</button>
      <PageHeader eyebrow={`${account?.name} · ${branch?.name ?? 'Unknown branch'}`} title={machine.display_name} description="Physical machine identity and current operational state." action={actions} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Dismiss</button></div>}
      {!machine.is_active && <div className="retired-banner"><ShieldAlert size={19} /><div><strong>Retired machine</strong><span>This machine is archived and read-only. Historical records remain preserved.</span></div></div>}

      <section className="machine-detail-hero glass-surface">
        <div className="machine-detail-identity"><span className="machine-detail-icon"><Printer size={36} /></span><div><span className={`machine-status machine-status-${machine.status}`}>{machine.status}</span><h2>{machine.machine_code}</h2><p>{model?.manufacturers?.name} · {model?.name}</p></div></div>
        <div className={machine.is_active ? 'record-state active-state' : 'record-state archived-state'}>{machine.is_active ? 'Active record' : 'Archived record'}</div>
      </section>

      <section className="detail-grid glass-surface" aria-label="Machine details">
        <DetailItem icon={MapPin} label="Branch" value={branch?.name} />
        <DetailItem icon={Tag} label="Serial number" value={machine.serial_number || 'Not recorded'} />
        <DetailItem icon={CalendarDays} label="Installed date" value={formatDate(machine.installed_on)} />
        <DetailItem icon={Clock3} label="Timezone" value={effectiveTimezone} hint={timezoneSource} />
        <DetailItem icon={Printer} label="Machine model" value={model?.name} hint={model?.model_code} />
        <DetailItem icon={FileText} label="Notes" value={machine.notes || 'No notes recorded'} />
      </section>

      <section className="future-module-section"><div><span className="card-kicker">Operational modules</span><h2>Connected workflows will arrive later.</h2><p>No counter, health, component, error, or maintenance data is fabricated here.</p></div><div className="future-module-grid">{futureModules.map((module) => <article className="future-module-card glass-surface" key={module[0]}>{createElement(module[1], { size: 20 })}<strong>{module[0]}</strong><span>Coming later</span></article>)}</div></section>

      {canManage && machineWorkflow.isContextActive && machineWorkflow.workflow.type === 'edit' && machineWorkflow.workflow.machineId === machine.id && <MachineFormDialog mode="edit" machine={machine} account={account} branches={branches} branchId={branch?.id} onClose={machineWorkflow.clearWorkflow} onSave={handleUpdate} />}
      {showRetire && <RetireMachineDialog machine={machine} onClose={() => setShowRetire(false)} onConfirm={handleRetire} />}
    </div>
  )
}
