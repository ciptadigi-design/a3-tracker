import { Eye, X } from 'lucide-react'
import { BlockingDialog } from '../../components/ui/BlockingDialog.jsx'

export function ReportDetailDialog({ title, description, sections, onClose }) {
  return <BlockingDialog className="machine-dialog report-detail-dialog glass-surface" backdropClassName="machine-dialog-backdrop" labelledBy="report-detail-title" describedBy="report-detail-description" onClose={onClose}>
    <header className="dialog-header"><div className="dialog-heading"><span className="dialog-icon"><Eye size={22} /></span><div><span className="card-kicker">Read-only report detail</span><h2 id="report-detail-title">{title}</h2><p id="report-detail-description">{description}</p></div></div><button className="icon-button" type="button" onClick={onClose} aria-label="Close report detail"><X size={19} /></button></header>
    <div className="machine-form-body report-detail-body">{sections.map((section) => <section key={section.title}><h3>{section.title}</h3><dl>{section.fields.map((field) => <div key={field.label}><dt>{field.label}</dt><dd>{field.value ?? 'Unavailable'}</dd></div>)}</dl></section>)}</div>
    <footer className="dialog-actions"><button className="secondary-button" type="button" onClick={onClose}>Close</button></footer>
  </BlockingDialog>
}
