import { ArrowLeft, BookOpenText, ClipboardList, Info } from 'lucide-react'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { useTenant } from '../../account/useTenant.js'
import { formatElapsedMinutes } from '../../downtime/downtimeModel.js'
import { formatMaintenanceDate } from '../maintenanceUtils.js'
import { fieldExperienceFailure, fieldTestResultLabels, fieldTestResultTone, troubleshootingDetailUrl, troubleshootingSearchUrl } from './troubleshootingModel.js'
import { useFieldExperience } from './useFieldExperience.js'

// Read-only view of one completed repair for people who may not open the ticket. It renders only the
// server's safe projection: no PIC, technician or vendor names, user ids or audit data.
export function FieldExperienceDetailPage({ ticketId, search = '', navigate }) {
  const { account, branch } = useTenant()
  const state = useFieldExperience(account?.id, ticketId)
  const params = new URLSearchParams(search)
  const query = params.get('q') || ''
  const machineId = params.get('machine') || ''
  const backTarget = troubleshootingSearchUrl(query, machineId)
  const timezone = branch?.timezone || account?.default_timezone || 'UTC'

  if (state.isLoading) return <LoadingScreen label="Memuat pengalaman lapangan" />
  if (state.error) {
    const failure = fieldExperienceFailure(state.error)
    return <ErrorState title={failure.title} detail={failure.detail} onRetry={failure.retry ? state.refresh : undefined} />
  }
  const item = state.experience
  if (!item) return <ErrorState title="Pengalaman tidak ditemukan" detail="Pengalaman ini tidak tersedia atau sudah tidak memenuhi syarat untuk ditampilkan." />

  const tone = fieldTestResultTone[item.test_result]
  const meta = [
    [item.machine_name, item.model_name].filter(Boolean).join(' · '),
    item.branch_name && `Cabang ${item.branch_name}`,
    item.incident_at && formatMaintenanceDate(item.incident_at, timezone, { dateOnly: true }),
    item.downtime_minutes != null && `Downtime ${formatElapsedMinutes(item.downtime_minutes)}`,
  ].filter(Boolean)
  const activities = item.activities || []

  return (
    <div className="page-stack field-experience-detail">
      <button className="back-button" type="button" onClick={() => navigate(backTarget)}><ArrowLeft size={17} /> Kembali ke pencarian</button>
      <section className="field-experience-card field-experience-detail-card glass-surface">
        <div className="field-experience-head">
          <span className="field-experience-source">Pengalaman Lapangan</span>
          {item.error_code && <span className="troubleshooting-code field-experience-code">{item.error_code}</span>}
          {tone && <span className={`incident-status-pill ${tone}`}>{fieldTestResultLabels[item.test_result]}</span>}
        </div>
        <h1>{item.title}</h1>
        {meta.length > 0 && <p className="field-experience-meta">{meta.join(' · ')}</p>}
        <p className="troubleshooting-group-disclaimer">Berdasarkan pengalaman penanganan sebelumnya, bukan prosedur resmi pabrikan.</p>
        {item.test_result === 'monitoring' && <p className="field-experience-monitoring" role="note"><Info size={15} /> Perlu Monitoring: mesin berfungsi setelah penanganan, namun perlu dipantau.</p>}
        {item.problem && <div className="field-experience-block"><h2>Masalah</h2><p>{item.problem}</p></div>}
        <dl className="field-experience-resolution">
          <div><dt>Penyebab</dt><dd>{item.cause}</dd></div>
          <div><dt>Tindakan</dt><dd>{item.action}</dd></div>
        </dl>
        {item.notes && <div className="field-experience-block"><h2>Catatan</h2><p>{item.notes}</p></div>}
        {item.truncated && <small className="field-experience-meta">Sebagian teks panjang dipersingkat.</small>}
      </section>

      {activities.length > 0 && (
        <section className="field-experience-card field-experience-detail-card glass-surface" aria-label="Kegiatan penanganan">
          <div className="field-experience-head"><ClipboardList size={16} /><span className="field-experience-source">Kegiatan penanganan</span></div>
          <ol className="field-experience-activities">
            {activities.map((activity, index) => (
              <li key={`${activity.performed_at}-${index}`}>
                <small>{activity.performed_at && formatMaintenanceDate(activity.performed_at, timezone)}{activity.result ? ` · ${activity.result}` : ''}</small>
                {activity.description && <p>{activity.description}</p>}
                {activity.findings && <p className="field-experience-findings">Temuan: {activity.findings}</p>}
              </li>
            ))}
          </ol>
        </section>
      )}

      {item.official_reference && (
        <section className="field-experience-card field-experience-detail-card glass-surface">
          <div className="field-experience-head"><BookOpenText size={16} /><span className="field-experience-source">Referensi resmi</span></div>
          <p className="field-experience-meta">Tiket ini mengacu pada prosedur resmi <strong>{item.official_reference.code}</strong>.</p>
          <button className="secondary-button compact-button" type="button" onClick={() => navigate(troubleshootingDetailUrl(item.official_reference.entry_id, machineId))}>Lihat prosedur resmi</button>
        </section>
      )}
      {item.can_open && <button className="secondary-button" type="button" onClick={() => navigate(`/maintenance/tickets/${item.id}`)}>Buka tiket maintenance</button>}
    </div>
  )
}
