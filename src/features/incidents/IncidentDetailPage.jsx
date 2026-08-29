import { createElement, useEffect, useMemo, useState } from 'react'
import { ArrowLeft, Boxes, CalendarClock, CheckCircle2, ClipboardCheck, FileText, Gauge, HandCoins, Hash, MapPin, PencilLine, Printer, ReceiptText, ShieldCheck, ShieldX, Tag, UserRound } from 'lucide-react'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { PageHeader } from '../../components/ui/PageHeader.jsx'
import { useTenant } from '../account/useTenant.js'
import { useAuth } from '../auth/useAuth.js'
import { useMachines } from '../machines/useMachines.js'
import { createUIStateKey } from '../uiState/uiStateKeys.js'
import { usePersistentUIState } from '../uiState/usePersistentUIState.js'
import { IncidentAuditHistory } from './IncidentAuditHistory.jsx'
import { IncidentFormDialog } from './IncidentFormDialog.jsx'
import { SolveIncidentDialog } from './SolveIncidentDialog.jsx'
import { categoryLabels, incidentStatusLabels, incidentTypeLabels } from './incidentConstants.js'
import { formatIncidentDate, formatRupiah, mapIncidentError } from './incidentUtils.js'
import { useOperationalIncident } from './useOperationalIncidents.js'
import { VoidIncidentDialog } from './VoidIncidentDialog.jsx'
import { solveOperationalIncident, updateOperationalIncident, voidOperationalIncident } from '../../services/supabase/operationalIncidents.js'
import { userErrorMessage } from '../../lib/appErrors.js'

const emptyEditWorkflow = { type: null }
const isEditWorkflow = (value) => value && (value.type === null || value.type === 'edit')

function DetailItem({ icon, label, value, hint }) {
  return <div className="detail-item incident-detail-item"><span className="detail-item-icon">{createElement(icon, { size: 18 })}</span><div><span>{label}</span><strong>{value || '—'}</strong>{hint && <small>{hint}</small>}</div></div>
}

function Narrative({ number, title, value }) {
  return <article className="incident-narrative-card glass-surface"><span>{number}</span><div><strong>{title}</strong><p>{value || 'Tidak dicatat.'}</p></div></article>
}

export function IncidentDetailPage({ incidentId, navigate }) {
  const { user } = useAuth()
  const { account, branch: activeBranch, branches, membership } = useTenant()
  const state = useOperationalIncident(account.id, activeBranch.id, incidentId)
  const machinesState = useMachines(account.id, activeBranch.id)
  const [showVoid, setShowVoid] = useState(false)
  const [showSolve, setShowSolve] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [isUpdating, setIsUpdating] = useState(false)
  const memberNames = useMemo(() => new Map(state.members.map((member) => [member.user_id, member.display_name])), [state.members])
  const branchId = state.incident?.branch_id || 'unknown'
  const editWorkflowKey = createUIStateKey({ userId: user.id, accountId: account.id, branchId, feature: 'operational-incident-edit-workflow', entityId: incidentId })
  const editWorkflow = usePersistentUIState({ uiStateKey: editWorkflowKey, initialValue: emptyEditWorkflow, validate: isEditWorkflow })
  const editWorkflowType = editWorkflow.value.type
  const clearEditWorkflow = editWorkflow.clearUIState

  useEffect(() => {
    if (state.incident?.status !== 'open' && editWorkflowType === 'edit') clearEditWorkflow()
  }, [clearEditWorkflow, editWorkflowType, state.incident?.status])

  if (state.isLoading) return <LoadingScreen label="Memuat detail operational error" />
  if (state.error) return <ErrorState title="Incident tidak dapat dimuat" detail={userErrorMessage(state.error, 'Detail incident sementara tidak tersedia.')} onRetry={state.refresh} />
  if (!state.incident) return <ErrorState title="Incident tidak tersedia di Branch ini" detail="Pilih Branch pemilik incident atau kembali ke daftar Errors Branch aktif." />

  const incident = state.incident
  const branch = branches.find((item) => item.id === incident.branch_id)
  const machine = machinesState.machines.find((item) => item.id === incident.machine_id)
  const timezone = branch?.timezone || account.default_timezone || 'Asia/Jakarta'
  const canEdit = ['owner', 'admin'].includes(membership.role) && incident.status === 'open'
  const canResolve = ['owner', 'admin', 'technician'].includes(membership.role) && incident.status === 'open'
  const canVoid = ['owner', 'admin'].includes(membership.role) && incident.status !== 'voided'

  async function handleSolve(resolutionNote) {
    setIsUpdating(true)
    setActionError(null)
    try {
      const updated = await solveOperationalIncident({ incidentId: incident.id, resolutionNote })
      state.setIncident(updated)
      editWorkflow.clearUIState()
      await state.refresh({ silent: true })
      setSuccess('Incident ditandai Diselesaikan dan sekarang read-only.')
    } catch (error) {
      setActionError(mapIncidentError(error))
      throw error
    } finally {
      setIsUpdating(false)
    }
  }

  async function handleEdit(values) {
    const updated = await updateOperationalIncident({ incidentId: incident.id, values })
    state.setIncident(updated)
    await state.refresh({ silent: true })
    setSuccess('Perubahan tersimpan dan revision audit telah ditambahkan.')
  }

  async function handleVoid(reason) {
    const updated = await voidOperationalIncident({ incidentId: incident.id, reason })
    state.setIncident(updated)
    setSuccess('Incident di-void tanpa menghapus riwayat.')
  }

  const actions = (canEdit || canResolve || canVoid) ? <div className="detail-actions">{canEdit && <button className="secondary-button" type="button" onClick={() => editWorkflow.setUIState({ type: 'edit' })}><PencilLine size={17} /> Edit Log</button>}{canResolve && <button className="secondary-button" type="button" onClick={() => setShowSolve(true)} disabled={isUpdating}><ShieldCheck size={17} /> Mark Solved</button>}{canVoid && <button className="danger-outline-button" type="button" onClick={() => setShowVoid(true)}><ShieldX size={17} /> Void</button>}</div> : null

  return (
    <div className="page-stack incident-detail-page">
      <button className="back-button" type="button" onClick={() => navigate('/errors')}><ArrowLeft size={17} /> Kembali ke Errors</button>
      <PageHeader eyebrow={`${account.name} · ${branch?.name || 'Unknown branch'}`} title="Incident detail" description="Current truth tetap terbaca; revision history menjelaskan bagaimana data berubah." action={actions} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Tutup</button></div>}
      {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

      <section className="incident-detail-hero glass-surface"><div><span className={`incident-status-pill ${incident.status}`}>{incidentStatusLabels[incident.status]}</span><h2>{incident.customer_name_snapshot || incident.product_name_snapshot || 'Operational incident'}</h2><p>{categoryLabels[incident.category]} · {incidentTypeLabels[incident.incident_type]} · {formatIncidentDate(incident.occurred_at, timezone)}</p></div><div className="incident-detail-total"><span>Total assessed loss</span><strong>{formatRupiah(incident.assessed_loss)}</strong><small>{formatRupiah(incident.material_loss)} bahan + {formatRupiah(incident.service_loss)} jasa · {Number(incident.penalty_multiplier)}×</small></div></section>

      <section className="detail-grid incident-detail-grid glass-surface" aria-label="Incident details">
        <DetailItem icon={CalendarClock} label="Tanggal / waktu" value={formatIncidentDate(incident.occurred_at, timezone)} />
        <DetailItem icon={Hash} label="Invoice CRM" value={incident.invoice_number || 'Tidak dicatat'} />
        <DetailItem icon={UserRound} label="Konsumen" value={incident.customer_name_snapshot || 'Tidak dicatat'} />
        <DetailItem icon={ReceiptText} label="Produk" value={incident.product_name_snapshot || 'Tidak dicatat'} />
        <DetailItem icon={Tag} label="Kategori / Jenis" value={`${categoryLabels[incident.category]} · ${incidentTypeLabels[incident.incident_type]}`} hint="Mesin = operational usage, bukan technical fault" />
        <DetailItem icon={Printer} label="Machine" value={machine ? `${machine.machine_code} · ${machine.display_name}` : 'Branch / No specific machine'} />
        <DetailItem icon={Gauge} label="Qty affected" value={incident.qty_affected == null ? 'Tidak dicatat' : String(incident.qty_affected)} />
        <DetailItem icon={UserRound} label="PIC / Operator" value={incident.operator_name_snapshot || 'Tidak dicatat'} hint={incident.operator_person_id ? 'Operational Person + immutable snapshot' : 'Historical record without Operator identity'} />
        <DetailItem icon={UserRound} label="PIC terlibat" value={incident.responsible_name_snapshot || 'Tidak dicatat'} hint={incident.responsible_person_id ? 'Operational Person + immutable snapshot' : 'Historical snapshot'} />
        <DetailItem icon={Boxes} label="Rugi bahan" value={formatRupiah(incident.material_loss)} />
        <DetailItem icon={HandCoins} label="Rugi jasa" value={formatRupiah(incident.service_loss)} />
        <DetailItem icon={ClipboardCheck} label="Created by" value={memberNames.get(incident.created_by) || 'User record unavailable'} hint={formatIncidentDate(incident.created_at, timezone)} />
        <DetailItem icon={MapPin} label="Status" value={incidentStatusLabels[incident.status]} hint={incident.void_reason || incident.resolution_note || undefined} />
      </section>

      <section className="incident-narrative-list">
        <Narrative number="1" title="Deskripsi Kesalahan" value={incident.description} />
        <Narrative number="2" title="Penyebab Kesalahan" value={incident.cause} />
        <Narrative number="3" title="Solusi & Pencegahan" value={incident.prevention} />
        <Narrative number="4" title="Penyelesaian Untuk Konsumen" value={incident.customer_resolution} />
      </section>

      <IncidentAuditHistory revisions={state.revisions} members={state.members} machines={machinesState.machines} timezone={timezone} />

      {incident.status === 'resolved' && <section className="resolved-banner"><ShieldCheck size={19} /><div><strong>Incident Diselesaikan</strong><span>{incident.resolution_note || 'Tidak ada resolution note.'}{incident.resolved_by ? ` · ${memberNames.get(incident.resolved_by) || 'User record unavailable'}` : ''}{incident.resolved_at ? ` · ${formatIncidentDate(incident.resolved_at, timezone)}` : ''}</span></div></section>}
      {incident.status === 'voided' && <section className="retired-banner"><FileText size={19} /><div><strong>Voided incident</strong><span>{incident.void_reason} · {formatIncidentDate(incident.voided_at, timezone)}</span></div></section>}
      {editWorkflow.value.type === 'edit' && canEdit && <IncidentFormDialog mode="edit" incident={incident} account={account} branch={branch} machines={machinesState.machines} people={state.people} onClose={editWorkflow.clearUIState} onSave={handleEdit} onLoadLatest={() => state.refresh({ silent: true })} />}
      {showSolve && canResolve && <SolveIncidentDialog incident={incident} onClose={() => setShowSolve(false)} onConfirm={handleSolve} />}
      {showVoid && <VoidIncidentDialog incident={incident} onClose={() => setShowVoid(false)} onConfirm={handleVoid} />}
    </div>
  )
}
