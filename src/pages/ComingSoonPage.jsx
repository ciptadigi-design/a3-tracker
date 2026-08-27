import { Clock3 } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'

export function ComingSoonPage({ title, description }) {
  return <div className="page-stack"><PageHeader eyebrow="Planned workspace" title={title} description={description} /><section className="coming-soon-card glass-surface"><span className="state-icon"><Clock3 size={26} /></span><span className="status-pill neutral-pill">Coming soon</span><h2>{title} is intentionally paused.</h2><p>This area will activate when its operational data model and workflow are ready.</p></section></div>
}
