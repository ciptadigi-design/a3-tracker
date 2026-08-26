import { ChevronDown, History, UserRound } from 'lucide-react'
import { categoryLabels, incidentRevisionFieldLabels, incidentTypeLabels } from './incidentConstants.js'
import { formatIncidentDate, formatRupiah } from './incidentUtils.js'

const financialFields = new Set(['material_loss', 'service_loss'])
const narrativeFields = new Set(['description', 'cause', 'prevention', 'customer_resolution'])

function displayValue(field, value, { machines, members, timezone }) {
  if (value == null || value === '') return 'Tidak dicatat'
  if (field === 'occurred_at') return formatIncidentDate(value, timezone)
  if (field === 'category') return categoryLabels[value] || value
  if (field === 'incident_type') return incidentTypeLabels[value] || value
  if (field === 'machine_id') {
    const machine = machines.find((item) => item.id === value)
    return machine ? `${machine.display_name} · ${machine.machine_code}` : 'Machine tidak tersedia'
  }
  if (field === 'responsible_user_id') return members.get(value) || 'User tidak tersedia'
  if (financialFields.has(field)) return formatRupiah(value)
  return String(value)
}

export function IncidentAuditHistory({ revisions, members, machines, timezone }) {
  const memberNames = new Map(members.map((member) => [member.user_id, member.display_name]))

  return (
    <section className="incident-audit-section glass-surface" aria-labelledby="incident-audit-title">
      <div className="section-header"><div><span className="card-kicker">Organizational learning</span><h2 id="incident-audit-title">Riwayat Perubahan</h2><p>Satu revision mencatat satu hasil diskusi secara atomik.</p></div><span className="audit-count"><History size={16} />{revisions.length}</span></div>
      {!revisions.length && <div className="incident-audit-empty"><History size={20} /><span>Belum ada perubahan setelah laporan awal.</span></div>}
      <div className="incident-audit-list">
        {revisions.map((revision, index) => (
          <details className="incident-revision-card" key={revision.id}>
            <summary>
              <div><strong>Revision {index + 1}</strong><span>{formatIncidentDate(revision.changed_at, timezone)} · {revision.changed_fields.length} bidang</span></div>
              <div className="revision-actor"><UserRound size={14} /><span>{memberNames.get(revision.changed_by) || 'User record unavailable'}</span><ChevronDown size={16} /></div>
            </summary>
            <div className="revision-body">
              {revision.change_reason && <div className="revision-reason"><span>Catatan perubahan</span><p>{revision.change_reason}</p></div>}
              <div className="revision-fields">
                {revision.changed_fields.map((field) => (
                  <article className={`revision-field ${narrativeFields.has(field) ? 'narrative' : ''}`} key={field}>
                    <strong>{incidentRevisionFieldLabels[field] || 'Bidang incident'}</strong>
                    <div><span>Before</span><p>{displayValue(field, revision.old_values[field], { machines, members: memberNames, timezone })}</p></div>
                    <div><span>After</span><p>{displayValue(field, revision.new_values[field], { machines, members: memberNames, timezone })}</p></div>
                  </article>
                ))}
              </div>
            </div>
          </details>
        ))}
      </div>
    </section>
  )
}
