import { Component } from 'react'
import { AlertTriangle, RefreshCcw } from 'lucide-react'
import { reportFailure } from '../../lib/appErrors.js'

export class AppErrorBoundary extends Component {
  state = { error: null }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error) {
    reportFailure(error, { operation: 'react.render' })
  }

  render() {
    if (!this.state.error) return this.props.children
    return <main className="fatal-error-page"><section className="fatal-error-card glass-surface" role="alert"><span className="fatal-error-icon"><AlertTriangle size={28} /></span><h1>A3 Tracker hit an unexpected problem.</h1><p>Your database records were not changed by this screen error. Reload the application to restore the current workspace.</p><button className="primary-button" type="button" onClick={() => window.location.reload()}><RefreshCcw size={17} />Reload A3 Tracker</button></section></main>
  }
}
