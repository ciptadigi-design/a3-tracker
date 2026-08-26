import { createElement, useMemo, useState } from 'react'
import { ArrowLeft, Boxes, CalendarClock, CheckCircle2, ClipboardCheck, FileText, Gauge, HandCoins, Hash, MapPin, Printer, ReceiptText, ShieldCheck, ShieldX, Tag, UserRound } from 'lucide-react'
import { ErrorState } from '../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../components/ui/LoadingScreen.jsx'
import { PageHeader } from '../../components/ui/PageHeader.jsx'
import { useTenant } from '../account/useTenant.js'
import { useMachines } from '../machines/useMachines.js'
import { categoryLabels, incidentStatusLabels, incidentTypeLabels } from './incidentConstants.js'
import { formatIncidentDate, formatRupiah, mapIncidentError } from './incidentUtils.js'
import { useOperationalIncident } from './useOperationalIncidents.js'
import { VoidIncidentDialog } from './VoidIncidentDialog.jsx'
import { resolveOperationalIncident, voidOperationalIncident } from '../../services/supabase/operationalIncidents.js'

function DetailItem({ icon, label, value, hint }) {
  return <div className="detail-item incident-detail-item"><span className="detail-item-icon">{createElement(icon, { size: 18 })}</span><div><span>{label}</span><strong>{value || '—'}</strong>{hint && <small>{hint}</small>}</div></div>
}

function Narrative({ number, title, value }) {
  return <article className="incident-narrative-card glass-surface"><span>{number}</span><div><strong>{title}</strong><p>{value || 'Tidak dicatat.'}</p></div></article>
}

export function IncidentDetailPage({ incidentId, navigate }) {
  const { account, branches, membership } = useTenant()
  const state = useOperationalIncident(account.id, incidentId)
  const machinesState = useMachines(account.id)
  const [showVoid, setShowVoid] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [success, setSuccess] = useState(null)
  const [isUpdating, setIsUpdating] = useState(false)
  const memberNames = useMemo(() => new Map(state.members.map((member) => [member.user_id, member.display_name])), [state.members])

  if (state.isLoading) return <LoadingScreen label="Memuat detail operational error" />
  if (state.error) return <ErrorState title="Incident tidak dapat dimuat" detail={state.error.message} onRetry={state.refresh} />
  if (!state.incident) return <ErrorState title="Incident tidak ditemukan" detail="Record tidak tersedia pada account aktif." />

  const incident = state.incident
  const branch = branches.find((item) => item.id === incident.branch_id)
  const machine = machinesState.machines.find((item) => item.id === incident.machine_id)
  const timezone = branch?.timezone || account.default_timezone || 'Asia/Jakarta'
  const canResolve = ['owner', 'admin', 'technician'].includes(membership.role) && incident.status === 'open'
  const canVoid = ['owner', 'admin'].includes(membership.role) && incident.status !== 'voided'

  async function handleResolve() {
    setIsUpdating(true)
    setActionError(null)
    try {
      const updated = await resolveOperationalIncident(incident.id)
      state.setIncident(updated)
      setSuccess('Incident ditandai resolved.')
    } catch (error) {
      setActionError(mapIncidentError(error))
    } finally {
      setIsUpdating(false)
    }
  }

  async function handleVoid(reason) {
    const updated = await voidOperationalIncident({ incidentId: incident.id, reason })
    state.setIncident(updated)
    setSuccess('Incident di-void tanpa menghapus riwayat.')
  }

  const actions = (canResolve || canVoid) ? <div className="detail-actions">{canResolve && <button className="secondary-button" type="button" onClick={handleResolve} disabled={isUpdating}><ShieldCheck size={17} /> Tandai resolved</button>}{canVoid && <button className="danger-outline-button" type="button" onClick={() => setShowVoid(true)}><ShieldX size={17} /> Void</button>}</div> : null

  return (
    <div className="page-stack incident-detail-page">
      <button className="back-button" type="button" onClick={() => navigate('/errors')}><ArrowLeft size={17} /> Kembali ke Errors</button>
      <PageHeader eyebrow={`${account.name} · ${branch?.name || 'Unknown branch'}`} title="Incident detail" description="Posted operational incident content is preserved as an auditable record." action={actions} />
      {success && <div className="success-banner" role="status"><CheckCircle2 size={18} /><span>{success}</span><button type="button" onClick={() => setSuccess(null)}>Tutup</button></div>}
      {actionError && <div className="form-error" role="alert"><span>{actionError}</span></div>}

      <section className="incident-detail-hero glass-surface"><div><span className={`incident-status-pill ${incident.status}`}>{incidentStatusLabels[incident.status]}</span><h2>{incident.customer_name_snapshot || incident.product_name_snapshot || 'Operational incident'}</h2><p>{categoryLabels[incident.category]} · {incidentTypeLabels[incident.incident_type]} · {formatIncidentDate(incident.occurred_at, timezone)}</p></div><div className="incident-detail-total"><span>Total assessed loss</span><strong>{formatRupiah(incident.assessed_loss)}</strong><small>{formatRupiah(incident.material_loss)} bahan + {formatRupiah(incident.service_loss)} jasa · {Number(incident.penalty_multiplier)}×</small></div></section>

      <section className="detail-grid incident-detail-grid glass-surface" aria-label="Incident details">
        <DetailItem icon={CalendarClock} label="Tanggal / waktu" value={formatIncidentDate(incident.occurred_at, timezone)} />
        <DetailItem icon={Hash} label="Invoice CRM" value={incident.invoice_number || 'Tidak dicatat'} />
        <DetailItem icon={UserRound} label="Konsumen" value={incident.customer_name_snapshot || 'Tidak dicatat'} />
        <DetailItem icon={ReceiptText} label="Produk" value={incident.product_name_snapshot || 'Tidak dicatat'} />
        <DetailItem icon={Tag} label="Kategori / Jenis" value={`${categoryLabels[incident.category]} · ${incidentTypeLabels[incident.incident_type]}`} hint="Mesin = operational usage, bukan technical fault" />
        <DetailItem icon={Printer} label="Machine" value={machine ? `${machine.display_name} · ${machine.machine_code}` : 'Tidak terkait machine'} />
        <DetailItem icon={Gauge} label="Qty affected" value={incident.qty_affected == null ? 'Tidak dicatat' : String(incident.qty_affected)} />
        <DetailItem icon={UserRound} label="PIC terlibat" value={incident.responsible_name_snapshot || 'Tidak dicatat'} hint={incident.responsible_user_id ? 'Linked account member + snapshot' : 'Snapshot name'} />
        <DetailItem icon={Boxes} label="Rugi bahan" value={formatRupiah(incident.material_loss)} />
        <DetailItem icon={HandCoins} label="Rugi jasa" value={formatRupiah(incident.service_loss)} />
        <DetailItem icon={ClipboardCheck} label="Created by" value={memberNames.get(incident.created_by) || 'User record unavailable'} hint={formatIncidentDate(incident.created_at, timezone)} />
        <DetailItem icon={MapPin} label="Status" value={incidentStatusLabels[incident.status]} hint={incident.void_reason || undefined} />
      </section>

      <section className="incident-narrative-list">
        <Narrative number="1" title="Deskripsi Kesalahan" value={incident.description} />
        <Narrative number="2" title="Penyebab Kesalahan" value={incident.cause} />
        <Narrative number="3" title="Solusi & Pencegahan" value={incident.prevention} />
        <Narrative number="4" title="Penyelesaian Untuk Konsumen" value={incident.customer_resolution} />
      </section>

      {incident.status === 'voided' && <section className="retired-banner"><FileText size={19} /><div><strong>Voided incident</strong><span>{incident.void_reason} · {formatIncidentDate(incident.voided_at, timezone)}</span></div></section>}
      {showVoid && <VoidIncidentDialog incident={incident} onClose={() => setShowVoid(false)} onConfirm={handleVoid} />}
    </div>
  )
}

