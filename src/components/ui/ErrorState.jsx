import { AlertTriangle, RotateCcw } from 'lucide-react'

export function ErrorState({ title, detail, onRetry }) {
  return (
    <main className="centered-state-page"><section className="state-card glass-surface">
      <span className="state-icon state-icon-error"><AlertTriangle size={24} /></span><h1>{title}</h1><p>{detail}</p>
      {onRetry && <button className="secondary-button" type="button" onClick={onRetry}><RotateCcw size={17} /> Try again</button>}
    </section></main>
  )
}
