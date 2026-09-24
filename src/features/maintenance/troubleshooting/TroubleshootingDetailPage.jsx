import { createElement } from 'react'
import { AlertTriangle, ArrowLeft, BookOpenText, Boxes, FileText, Info, ListChecks, ShieldAlert, Wrench } from 'lucide-react'
import { ErrorState } from '../../../components/ui/ErrorState.jsx'
import { LoadingScreen } from '../../../components/ui/LoadingScreen.jsx'
import { userErrorMessage } from '../../../lib/appErrors.js'
import { applicabilityLabels, technicalReferenceLabel } from './troubleshootingModel.js'
import { useOfficialErrorEntry } from './useOfficialErrorEntry.js'

function DetailSection({ icon, eyebrow, title, className = '', children }) {
  return (
    <section className={`troubleshooting-detail-section glass-surface ${className}`.trim()}>
      <header><span>{createElement(icon, { size: 19 })}</span><div><span className="card-kicker">{eyebrow}</span><h2>{title}</h2></div></header>
      <div className="troubleshooting-detail-content">{children}</div>
    </section>
  )
}

function SafetyNotice({ warning, alertMeasure }) {
  if (!warning && !alertMeasure) return null
  return (
    <DetailSection icon={ShieldAlert} eyebrow="Safety" title="Peringatan" className="troubleshooting-safety">
      {warning && <div><strong>Peringatan</strong><p>{warning}</p></div>}
      {alertMeasure && <div><strong>Tindakan keselamatan</strong><p>{alertMeasure}</p></div>}
    </DetailSection>
  )
}

function TechnicalReferences({ entry }) {
  const hasTechnicalText = entry.isolation_dipsw || entry.detached_control
  if (!entry.references?.length && !hasTechnicalText) return null
  return (
    <DetailSection icon={BookOpenText} eyebrow="Manual references" title="Referensi Teknis">
      <div className="technical-reference-list">
        {(entry.references || []).map((reference) => (
          <article key={reference.id}>
            <strong>{technicalReferenceLabel(reference.reference_type)}</strong>
            <p>{reference.reference_value}</p>
            {(reference.step_number || reference.page_number || reference.section_number) && <small>{[
              reference.step_number ? `Step ${reference.step_number}` : null,
              reference.page_number ? `Page ${reference.page_number}` : null,
              reference.section_number ? `Section ${reference.section_number}` : null,
            ].filter(Boolean).join(' · ')}</small>}
          </article>
        ))}
        {entry.isolation_dipsw && <article><strong>DIPSW</strong><p>{entry.isolation_dipsw}</p></article>}
        {entry.detached_control && <article><strong>Detached control</strong><p>{entry.detached_control}</p></article>}
      </div>
    </DetailSection>
  )
}

function SourceProvenance({ provenance }) {
  if (!provenance?.document) return null
  const page = provenance.source_page_start && provenance.source_page_end && provenance.source_page_start !== provenance.source_page_end
    ? `Pages ${provenance.source_page_start}–${provenance.source_page_end}`
    : provenance.source_page_start ? `Page ${provenance.source_page_start}` : null
  return (
    <DetailSection icon={FileText} eyebrow="Manufacturer source" title="Sumber">
      <div className="source-provenance"><strong>{provenance.document.title || 'Konica Minolta Service Manual'}</strong><span>{[provenance.section_number ? `Section ${provenance.section_number}` : null, page].filter(Boolean).join(' · ')}</span></div>
    </DetailSection>
  )
}

export function TroubleshootingDetailPage({ entryId, navigate }) {
  const state = useOfficialErrorEntry(entryId)
  if (state.isLoading) return <LoadingScreen label="Memuat penanganan resmi" />
  if (state.error) return <ErrorState title="Penanganan tidak dapat dimuat" detail={userErrorMessage(state.error, 'Prosedur ini sementara tidak tersedia.')} onRetry={state.refresh} />
  if (!state.entry) return <ErrorState title="Penanganan tidak ditemukan" detail="Data mungkin tidak tersedia untuk akun aktif." />

  const entry = state.entry
  const backTarget = `/maintenance/troubleshooting?q=${encodeURIComponent(entry.code)}`
  const labels = applicabilityLabels(entry)

  return (
    <div className="page-stack troubleshooting-detail-page">
      <button className="back-button" type="button" onClick={() => navigate(backTarget)}><ArrowLeft size={17} /> Kembali ke pencarian</button>
      <section className="troubleshooting-detail-hero glass-surface">
        <div><span className="troubleshooting-code">{entry.code}</span><h1>{entry.classification || 'Official troubleshooting procedure'}</h1><div className="troubleshooting-applicabilities">{labels.map((label) => <span className="troubleshooting-applicability" key={label}>{label}</span>)}</div></div>
        <span className="troubleshooting-official-mark"><Wrench size={18} /> Official manufacturer knowledge</span>
      </section>

      {(entry.steps?.length > 0 || entry.correction) && (
        <DetailSection icon={ListChecks} eyebrow="Apa yang harus dilakukan" title="Penanganan">
          {entry.steps?.length > 0
            ? <ol className="solution-steps">{entry.steps.map((step) => <li key={step.id} value={step.step_number}><p>{step.instruction}</p>{step.applicability_label && <small>{step.applicability_label}</small>}{step.requires_technician && <span>Teknisi diperlukan</span>}</li>)}</ol>
            : <p className="manufacturer-copy">{entry.correction}</p>}
        </DetailSection>
      )}

      {entry.cause && <DetailSection icon={Info} eyebrow="Manufacturer explanation" title="Penyebab"><p className="manufacturer-copy">{entry.cause}</p></DetailSection>}

      {entry.parts?.length > 0 && (
        <DetailSection icon={Boxes} eyebrow="Service parts" title="Part Terkait">
          <ul className="related-parts">{entry.parts.map((part) => <li key={part.id}><div><strong>{part.part_name}</strong>{part.part_code && <span>{part.part_code}</span>}</div>{part.applicability_label && <small>{part.applicability_label}</small>}</li>)}</ul>
        </DetailSection>
      )}

      <SafetyNotice warning={entry.warning} alertMeasure={entry.alert_measure} />
      {entry.note && <DetailSection icon={AlertTriangle} eyebrow="Manufacturer note" title="Catatan"><p className="manufacturer-copy">{entry.note}</p></DetailSection>}
      <TechnicalReferences entry={entry} />
      <SourceProvenance provenance={entry.provenance} />
    </div>
  )
}
