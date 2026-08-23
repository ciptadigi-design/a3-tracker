import { ArrowRight, Building2, MapPin, Printer, Sparkles } from 'lucide-react'
import { PageHeader } from '../components/ui/PageHeader.jsx'
import { useTenant } from '../features/account/useTenant.js'
import { useMachines } from '../features/machines/useMachines.js'

export function OverviewPage() {
  const { account, branch } = useTenant()
  const { machines, isLoading, error } = useMachines(account?.id, branch?.id)
  return (
    <div className="page-stack">
      <PageHeader eyebrow="Live workspace" title={`Good to see you at ${account?.name}`} description="Your machine operations workspace is ready for its first registered machine." />
      <section className="overview-grid">
        <article className="hero-card glass-surface"><div className="hero-card-copy"><span className="status-pill"><span /> Workspace online</span><h2>A clean foundation for every machine you operate.</h2><p>Your account and branch context are connected to Supabase and protected by tenant-level access controls.</p></div><div className="hero-machine-visual" aria-hidden="true"><div className="machine-glow" /><Printer size={72} strokeWidth={1.2} /></div></article>
        <article className="next-step-card glass-surface"><span className="section-icon"><Sparkles size={21} /></span><div><span className="card-kicker">Next milestone</span><h3>Register your first machine</h3><p>Machine creation will be enabled in M1.2.</p></div><ArrowRight size={18} /></article>
      </section>
      <section className="metric-grid" aria-label="Workspace summary">
        <article className="metric-card glass-surface"><span className="metric-icon metric-icon-blue"><Building2 size={22} /></span><div><span>Account</span><strong>{account?.name}</strong></div></article>
        <article className="metric-card glass-surface"><span className="metric-icon metric-icon-purple"><MapPin size={22} /></span><div><span>Branch</span><strong>{branch?.name ?? '—'}</strong></div></article>
        <article className="metric-card glass-surface"><span className="metric-icon metric-icon-green"><Printer size={22} /></span><div><span>Machines</span><strong>{isLoading ? '—' : error ? 'Unavailable' : machines.length}</strong></div></article>
      </section>
      <section className="starting-state glass-surface"><div><span className="card-kicker">Starting point</span><h3>{error ? 'Machine data could not be loaded' : 'Your operations workspace is ready'}</h3><p>{error ? error.message : 'No fabricated activity or KPI data is shown. Real operational insights will appear as your team begins using A3 Tracker.'}</p></div><div className="starting-state-line"><span /></div></section>
    </div>
  )
}
