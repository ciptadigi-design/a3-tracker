import { createElement, useMemo, useState } from 'react'
import { AlertTriangle, Boxes, CheckCircle2, ClipboardPlus, HandCoins, ReceiptText } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { useAuth } from '../features/auth/useAuth.js'
import { IncidentFormDialog } from '../features/incidents/IncidentFormDialog.jsx'
import { IncidentHistory } from '../features/incidents/IncidentHistory.jsx'
import { formatRupiah } from '../features/incidents/incidentUtils.js'
import { useOperationalIncidents } from '../features/incidents/useOperationalIncidents.js'
import { useMachines } from '../features/machines/useMachines.js'
import { createUIStateKey } from '../features/uiState/uiStateKeys.js'
import { usePersistentUIState } from '../features/uiState/usePersistentUIState.js'
import { createOperationalIncident } from '../services/supabase/operationalIncidents.js'

const emptyWorkflow = { type: null }
const isIncidentWorkflow = (value) => value && (value.type === null || value.type === 'create')

function SummaryCard({ icon, label, value, detail, tone }) {
  return <article className="incident-summary-card glass-surface"><span className={`daily-summary-icon ${tone}`}>{createElement(icon, { size: 21 })}</span><div><span>{label}</span><strong>{value}</strong><small>{detail}</small></div></article>
}

export function ErrorsPage({ navigate }) {
  const { user } = useAuth()
  const { account, branch, membership, operationalPermissions } = useTenant()
  const machinesState = useMachines(account.id, branch.id)
  const incidentState = useOperationalIncidents(account.id, branch.id)
  const [success, setSuccess] = useState(null)
  const [machineFilter, setMachineFilter] = useState('all')
  const workflowKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId: branch.id, feature: 'operational-incident-workflow', entityId: 'active' })
  const workflow = usePersistentUIState({ uiStateKey: workflowKey, initialValue: emptyWorkflow, validate: isIncidentWorkflow })
  const timezone = branch.timezone || account.default_timezone || 'Asia/Jakarta'
  const selectedMachine = machinesState.machines.find((machine) => machine.id === machineFilter)
  const effectiveMachineFilter = machineFilter === 'branch' || selectedMachine ? machineFilter : 'all'
  const filteredIncidents = useMemo(() => incidentState.incidents.filter((incident) => effectiveMachineFilter === 'all'
    || (effectiveMachineFilter === 'branch' ? incident.machine_id == null : incident.machine_id === effectiveMachineFilter)), [incidentState.incidents, effectiveMachineFilter])
  const validIncidents = useMemo(() => filteredIncidents.filter((incident) => incident.status !== 'voided'), [filteredIncidents])
  const summary = useMemo(() => validIncidents.reduce((totals, incident) => ({
    count: totals.count + 1,
    material: totals.material + Number(incident.material_loss),
    service: totals.service + Number(incident.service_loss),
    assessed: totals.assessed + Number(incident.assessed_loss),
  }), { count: 0, material: 0, service: 0, assessed: 0 }), [validIncidents])

  async function handleCreate(values) {
    await createOperationalIncident({ accountId: account.id, branchId: branch.id, values })
    await incidentState.refresh()
    setSuccess('Log error operasional tersimpan dan langsung tersedia di riwayat.')
  }

  const isReady = !incidentState.isLoading && !machinesState.isLoading
  const scopeDescription = selectedMachine ? `${selectedMachine.machine_code} · all history` : effectiveMachineFilter === 'branch' ? 'Branch / No specific machine · all history' : 'All assessed operational loss in this branch · all history'
  const canLogErrors = ['owner', 'admin', 'technician'].includes(membership?.role) || (membership?.role === 'operator' && operationalPermissions?.operator_can_log_errors)
  const addAction = canLogErrors ? <button className="primary-button" type="button" onClick={() => workflow.setUIState({ type: 'create' })} disabled={!isReady}><ClipboardPlus size={18} /> Log error baru</button> : null

  return (
    <div className="page-stack errors-page">
      <PageHeader eyebrow={`${account.name} · ${branch.name}`} title="Human / Operational Error" description="Catat kesalahan produksi manusia dan operasional. Fault code teknis mesin tetap menjadi domain terpisah." action={addAction} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Tutup</button></div>}

      <section className="incident-domain-note"><AlertTriangle size={18} /><div><strong>Operational incident scope</strong><span>Jenis “Mesin” berarti kesalahan setting atau penggunaan mesin dalam proses produksi—bukan C-xxxx, kerusakan hardware, atau diagnosis service.</span></div></section>

      <section className="incident-scope-filter glass-surface" aria-label="Error scope filters"><label><span>Machine</span><select value={effectiveMachineFilter} onChange={(event) => setMachineFilter(event.target.value)}><option value="all">All Machines / Branch</option><option value="branch">Branch / No specific machine</option>{machinesState.machines.filter((machine) => machine.is_active).map((machine) => <option key={machine.id} value={machine.id}>{machine.machine_code} · {machine.display_name}</option>)}</select></label><p>These totals cover all recorded history. Match both Machine and period when reconciling with Machine Cost.</p></section>

      <section className="incident-summary-grid" aria-label="Ringkasan kerugian branch aktif">
        <SummaryCard icon={ReceiptText} label="Total Incident" value={String(summary.count)} detail="Open + resolved; voided tidak dihitung" tone="blue" />
        <SummaryCard icon={Boxes} label="Rugi Bahan" value={formatRupiah(summary.material)} detail="Semua record valid pada branch" tone="amber" />
        <SummaryCard icon={HandCoins} label="Rugi Jasa" value={formatRupiah(summary.service)} detail="Semua record valid pada branch" tone="purple" />
        <SummaryCard icon={AlertTriangle} label="Assessed Loss" value={formatRupiah(summary.assessed)} detail={scopeDescription} tone="green" />
      </section>

      <IncidentHistory incidents={filteredIncidents} machines={machinesState.machines} timezone={timezone} isLoading={incidentState.isLoading} error={incidentState.error} onRefresh={incidentState.refresh} onOpen={(incidentId) => navigate(`/errors/${incidentId}`)} />

      {canLogErrors && workflow.value.type === 'create' && isReady && <IncidentFormDialog account={account} branch={branch} machines={machinesState.machines} people={incidentState.people} onClose={workflow.clearUIState} onSave={handleCreate} />}
    </div>
  )
}
